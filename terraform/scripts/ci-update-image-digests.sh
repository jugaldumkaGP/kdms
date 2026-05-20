#!/usr/bin/env bash
# Update terraform.tfvars image digest (and clear matching *_image_tag) from Artifact Registry.
# Used by .github/workflows/push-gar.yml after images are pushed.
# Portable: works on macOS /bin/bash 3.2 and Linux bash 4+ (no associative arrays).
set -euo pipefail

TFVARS="${1:-terraform/terraform.tfvars}"
REGION="${GAR_REGION:-asia-south1}"
PROJECT_ID="${PROJECT_ID:-project-12f4b54b-d692-4583-83b}"
REPO="${GAR_REPOSITORY:-apps}"
TAG="${ROLLING_TAG:-branch-main}"

if [[ ! -f "$TFVARS" ]]; then
  echo "Missing tfvars: $TFVARS" >&2
  exit 1
fi

# Artifact Registry image name -> terraform digest variable
AR_IMAGES=(
  kdms-main
  kdms-api
  kdms-reports
  kdms-ocr
  kdms-registration
)

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

normalize_digest() {
  local d="$1"
  d="${d#sha256:}"
  d="$(printf '%s' "$d" | tr '[:upper:]' '[:lower:]')"
  echo "sha256:${d}"
}

fetch_digest() {
  local image="$1"
  local uri="${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPO}/${image}"
  local raw
  raw="$(gcloud artifacts docker images describe "${uri}:${TAG}" \
    --format='value(image_summary.digest)' 2>/dev/null || true)"
  if [[ -z "$raw" ]]; then
    echo "Could not resolve digest for ${uri}:${TAG}" >&2
    return 1
  fi
  normalize_digest "$raw"
}

set_tfvar() {
  local key="$1"
  local value="$2"
  local file="$3"
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
  local key="$1"
  local file="$2"
  set_tfvar "$key" "" "$file"
}

for image in "${AR_IMAGES[@]}"; do
  digest_var="$(digest_var_for_image "$image")"
  echo "Resolving ${image} (${TAG}) -> ${digest_var}"
  digest="$(fetch_digest "$image")"
  tag_var="$(tag_var_for_digest_var "$digest_var")"

  current="$(grep -E "^[[:space:]]*${digest_var}[[:space:]]*=" "$TFVARS" | sed -E 's/.*=[[:space:]]*"?([^"]*)"?/\1/' | tr -d ' ' || true)"
  if [[ "$current" != "$digest" ]]; then
    set_tfvar "$digest_var" "$digest" "$TFVARS"
    echo "  ${digest_var} -> ${digest}"
  else
    echo "  ${digest_var} unchanged"
  fi

  if [[ -n "$tag_var" ]]; then
    tag_current="$(grep -E "^[[:space:]]*${tag_var}[[:space:]]*=" "$TFVARS" | sed -E 's/.*=[[:space:]]*"?([^"]*)"?/\1/' | tr -d ' ' || true)"
    if [[ -n "$tag_current" ]]; then
      clear_tfvar "$tag_var" "$TFVARS"
      echo "  cleared ${tag_var}"
    fi
  fi
done

echo "Done."
