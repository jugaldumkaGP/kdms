#!/usr/bin/env bash
# Deploy KDMS Cloud Run services using the newest images in Artifact Registry.
#
# Replaces the manual 3-step flow:
#   1. git pull origin main          (optional — use --pull)
#   2. pin image digests in tfvars   (always — branch-main, or local HEAD SHA when present)
#   3. terraform plan && apply
#
# Usage (from kdms repo root):
#   ./scripts/deploy-kdms-latest.sh
#   ./scripts/deploy-kdms-latest.sh --pull --yes
#   ./scripts/deploy-kdms-latest.sh --plan-only
#   ./scripts/deploy-kdms-latest.sh --wait
#
# Environment overrides:
#   PROJECT_ID, GAR_REGION, GAR_REPOSITORY, ROLLING_TAG, TFVARS
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TFVARS="${TFVARS:-${ROOT}/terraform/terraform.tfvars}"
GAR_REGION="${GAR_REGION:-asia-south1}"
PROJECT_ID="${PROJECT_ID:-project-12f4b54b-d692-4583-83b}"
GAR_REPOSITORY="${GAR_REPOSITORY:-apps}"
ROLLING_TAG="${ROLLING_TAG:-branch-main}"

DO_PULL=false
PLAN_ONLY=false
AUTO_APPROVE=false
DO_WAIT=false
PREFER_HEAD=true

usage() {
  cat <<EOF
Usage: $0 [OPTIONS]

Deploy latest KDMS images to Cloud Run via Terraform.

Options:
  --pull       git pull origin main before resolving images (get CI-pinned tfvars too)
  --yes, -y    terraform apply without interactive approval
  --plan-only  run plan only; do not apply
  --wait       after apply, wait for Cloud Run services to report ready revisions
  --rolling    always use ${ROLLING_TAG} digests (skip local git HEAD SHA lookup)
  -h, --help   show this help

Examples:
  $0 --pull --yes
  $0 --plan-only
EOF
}

for arg in "$@"; do
  case "$arg" in
    --pull) DO_PULL=true ;;
    --yes|-y) AUTO_APPROVE=true ;;
    --plan-only) PLAN_ONLY=true ;;
    --wait) DO_WAIT=true ;;
    --rolling) PREFER_HEAD=false ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $arg" >&2
      usage >&2
      exit 1
      ;;
  esac
done

if [[ ! -f "$TFVARS" ]]; then
  echo "ERROR: terraform.tfvars not found at ${TFVARS}" >&2
  exit 1
fi

if ! command -v gcloud >/dev/null 2>&1; then
  echo "ERROR: gcloud CLI required" >&2
  exit 1
fi

if ! command -v terraform >/dev/null 2>&1; then
  echo "ERROR: terraform required" >&2
  exit 1
fi

cd "$ROOT"

if [[ "$DO_PULL" == "true" ]]; then
  echo "==> git pull origin main"
  git pull origin main
fi

normalize_digest() {
  local d="$1"
  d="${d#sha256:}"
  d="$(printf '%s' "$d" | tr '[:upper:]' '[:lower:]')"
  echo "sha256:${d}"
}

gar_has_tag() {
  local image="$1" tag="$2"
  local uri="${GAR_REGION}-docker.pkg.dev/${PROJECT_ID}/${GAR_REPOSITORY}/${image}"
  gcloud artifacts docker tags list "$uri" \
    --filter="tag:${tag}" \
    --format='value(tag)' \
    --limit=1 2>/dev/null | grep -qxF "$tag"
}

fetch_digest_for_tag() {
  local image="$1" tag="$2"
  local uri="${GAR_REGION}-docker.pkg.dev/${PROJECT_ID}/${GAR_REPOSITORY}/${image}"
  local raw
  raw="$(gcloud artifacts docker images describe "${uri}:${tag}" \
    --format='value(image_summary.digest)' 2>/dev/null || true)"
  if [[ -z "$raw" ]]; then
    return 1
  fi
  normalize_digest "$raw"
}

digest_var_for_image() {
  case "$1" in
    kdms-main) echo image_digest ;;
    kdms-api) echo api_image_digest ;;
    kdms-reports) echo reports_image_digest ;;
    kdms-ocr) echo ocr_image_digest ;;
    kdms-registration) echo registration_image_digest ;;
    *) echo "Unknown image: $1" >&2; return 1 ;;
  esac
}

tag_var_for_digest_var() {
  case "$1" in
    image_digest) echo image_tag ;;
    api_image_digest) echo api_image_tag ;;
    reports_image_digest) echo reports_image_tag ;;
    ocr_image_digest) echo ocr_image_tag ;;
    registration_image_digest) echo registration_image_tag ;;
    *) echo "" ;;
  esac
}

set_tfvar() {
  local key="$1" value="$2" file="$3"
  local tmp
  tmp="$(mktemp)"
  if grep -qE "^[[:space:]]*${key}[[:space:]]*=" "$file"; then
    sed -E "s|^[[:space:]]*${key}[[:space:]]*=.*|${key} = \"${value}\"|" "$file" >"$tmp"
  else
    cp "$file" "$tmp"
    echo "${key} = \"${value}\"" >>"$tmp"
  fi
  mv "$tmp" "$file"
}

clear_tfvar() {
  local key="$1" file="$2"
  set_tfvar "$key" "" "$file"
}

tfvar_is_true() {
  local key="$1"
  grep -E "^[[:space:]]*${key}[[:space:]]*=" "$TFVARS" 2>/dev/null | grep -qi 'true'
}

AR_IMAGES=(kdms-main kdms-api kdms-reports kdms-ocr kdms-registration)

HEAD_TAG=""
if [[ "$PREFER_HEAD" == "true" ]] && git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  HEAD_TAG="$(git rev-parse --short HEAD 2>/dev/null || true)"
fi

echo "==> Resolve Artifact Registry digests (project=${PROJECT_ID}, repo=${GAR_REPOSITORY})"

for image in "${AR_IMAGES[@]}"; do
  digest_var="$(digest_var_for_image "$image")"
  tag_var="$(tag_var_for_digest_var "$digest_var")"
  chosen_tag="$ROLLING_TAG"
  digest=""

  if [[ -n "$HEAD_TAG" ]] && gar_has_tag "$image" "$HEAD_TAG"; then
    if digest="$(fetch_digest_for_tag "$image" "$HEAD_TAG")"; then
      chosen_tag="$HEAD_TAG"
    fi
  fi

  if [[ -z "$digest" ]]; then
    if ! digest="$(fetch_digest_for_tag "$image" "$ROLLING_TAG")"; then
      echo "WARN: could not resolve ${image}:${ROLLING_TAG} — leaving ${digest_var} unchanged" >&2
      continue
    fi
    chosen_tag="$ROLLING_TAG"
  fi

  current="$(grep -E "^[[:space:]]*${digest_var}[[:space:]]*=" "$TFVARS" \
    | sed -E 's/.*=[[:space:]]*"?([^"]*)"?/\1/' | tr -d ' ' || true)"

  if [[ "$current" != "$digest" ]]; then
    set_tfvar "$digest_var" "$digest" "$TFVARS"
    echo "  ${image} @ ${chosen_tag} -> ${digest_var}"
  else
    echo "  ${image} @ ${chosen_tag} -> ${digest_var} (unchanged)"
  fi

  if [[ -n "$tag_var" ]]; then
    tag_current="$(grep -E "^[[:space:]]*${tag_var}[[:space:]]*=" "$TFVARS" \
      | sed -E 's/.*=[[:space:]]*"?([^"]*)"?/\1/' | tr -d ' ' || true)"
    if [[ -n "$tag_current" ]]; then
      clear_tfvar "$tag_var" "$TFVARS"
      echo "  cleared ${tag_var}"
    fi
  fi
done

REGION="$(grep -E '^[[:space:]]*region[[:space:]]*=' "$TFVARS" \
  | sed -E 's/.*=[[:space:]]*"?([^"]*)"?/\1/' | tr -d ' ')"
REGION="${REGION:-${GAR_REGION}}"

echo "==> terraform init (if needed)"
cd "${ROOT}/terraform"
terraform init -input=false >/dev/null

PLAN_FILE="plan.tfplan"
echo "==> terraform plan"
terraform plan -var-file=terraform.tfvars -out="$PLAN_FILE"

if [[ "$PLAN_ONLY" == "true" ]]; then
  echo "Plan saved to terraform/${PLAN_FILE} (--plan-only; not applying)."
  exit 0
fi

echo "==> terraform apply"
if [[ "$AUTO_APPROVE" == "true" ]]; then
  terraform apply -input=false "$PLAN_FILE"
else
  terraform apply "$PLAN_FILE"
fi

SERVICES=()
main_svc="$(grep -E '^[[:space:]]*service_name[[:space:]]*=' "$TFVARS" \
  | sed -E 's/.*=[[:space:]]*"?([^"]*)"?/\1/' | tr -d ' ')"
api_svc="$(grep -E '^[[:space:]]*api_service_name[[:space:]]*=' "$TFVARS" \
  | sed -E 's/.*=[[:space:]]*"?([^"]*)"?/\1/' | tr -d ' ')"
[[ -n "$main_svc" ]] && SERVICES+=("$main_svc")
[[ -n "$api_svc" ]] && SERVICES+=("$api_svc")
if tfvar_is_true enable_reports_service; then
  rep="$(grep -E '^[[:space:]]*reports_service_name[[:space:]]*=' "$TFVARS" \
    | sed -E 's/.*=[[:space:]]*"?([^"]*)"?/\1/' | tr -d ' ')"
  [[ -n "$rep" ]] && SERVICES+=("$rep")
fi
if tfvar_is_true enable_registration_service; then
  reg="$(grep -E '^[[:space:]]*registration_service_name[[:space:]]*=' "$TFVARS" \
    | sed -E 's/.*=[[:space:]]*"?([^"]*)"?/\1/' | tr -d ' ')"
  [[ -n "$reg" ]] && SERVICES+=("$reg")
fi

if [[ "$DO_WAIT" == "true" ]]; then
  echo "==> Waiting for Cloud Run services…"
  for svc in "${SERVICES[@]}"; do
    echo "  ${svc}…"
    for _ in $(seq 1 60); do
      rev="$(gcloud run services describe "$svc" \
        --region="$REGION" \
        --project="$PROJECT_ID" \
        --format='value(status.latestReadyRevisionName)' 2>/dev/null || true)"
      if [[ -n "$rev" ]]; then
        echo "    ready: ${rev}"
        break
      fi
      sleep 5
    done
  done
fi

echo "==> Deployed service URLs:"
for svc in "${SERVICES[@]}"; do
  url="$(gcloud run services describe "$svc" \
    --region="$REGION" \
    --project="$PROJECT_ID" \
    --format='value(status.url)' 2>/dev/null || true)"
  [[ -n "$url" ]] && echo "  ${svc}: ${url}"
done

echo "Done."
