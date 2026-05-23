# =============================================================================
# KDMS production — Cloud Run (split services)
# =============================================================================
# Commit policy: This file holds non-secret inputs only (Secret Manager ids, not
# passwords).
#
# Images: digest > explicit *_image_tag > rolling_image_tag (default branch-main).
# Leave digest and tag empty to deploy whatever CI last pushed to branch-main;
# set *_image_digest when pinning / rollback is required.
#
# Rollout checklist:
# 1. Push images to Artifact Registry (CI tags branch-main + short SHA per service).
# 2. Either leave digests empty for rolling deploy, or set per-service digests to pin.
# 3. Set app_url + api_url (+ reports_url, ocr_url) to live HTTPS URLs (no trailing slash).
# 4. terraform plan && terraform apply
#
# Cloud Run URL shape (example): https://SERVICE-PROJECT_NUMBER.REGION.run.app
# Verify with: gcloud run services describe SERVICE --region REGION --format='value(status.url)'
# =============================================================================

project_id     = "project-12f4b54b-d692-4583-83b"
project_number = "684080887473"
region         = "asia-south1"

# -----------------------------------------------------------------------------
# UI (kdms) + API (kdms-api) — same container image, separate Cloud Run services
# -----------------------------------------------------------------------------
service_name     = "kdms-prod"
api_service_name = "kdms-api-prod"

ar_repo    = "apps"
image_name = "kdms-main"
# Optional: pin with sha256:… ; leave empty to use branch-main (or set image_tag).
image_digest = "sha256:1b28b405ce61b00d0fac4d983d7889547f31ee65d3c4355a3c02ff6730b4ad6b"
image_tag    = ""
# rolling_image_tag = "branch-main"  # when digest and image_tag are both empty

api_image_name   = "kdms-api"
api_image_digest = "sha256:9771250e46a8a65fb0808bf9cd0093f0616c36ec7c8ef5badd800769cb054343"
api_image_tag    = ""

runtime_sa_email  = "run-kdms@project-12f4b54b-d692-4583-83b.iam.gserviceaccount.com"

# --- Database (Cloud SQL) ---
# CURRENT DEPLOY: staging instance (kdms-api-prod / kdms-prod point here today).
# Staging/test env values
cloudsql_instance = "mysql-skm-prod"
db_name           = "kdms"
db_username       = "kdms"

# Production values
# cloudsql_instance = "mysql-kdms-prod"
# db_name           = "kdms_prod"
# db_username       = "kdms_user"

# PRODUCTION (required before Stream C BLOB migration — switch both kdms-prod + kdms-api-prod):
# cloudsql_instance        = "mysql-kdms-prod"
# db_name                  = "kdms_prod"
# db_username              = "kdms_user"
# cloudsql_connection_name = "project-12f4b54b-d692-4583-83b:asia-south1:mysql-kdms-prod"
# secret_db_password     = "kdms-db-password"   # Secret Manager: password for kdms_user on mysql-kdms-prod

# UI service — interactive pages; moderate concurrency.
min_instances         = 0
max_instances         = 5
cpu                   = "1"
memory                = "2Gi"
container_port        = 8080
container_concurrency = 80

# API service — JSON / DB heavier paths; higher concurrency cap.
api_min_instances         = 0
api_max_instances         = 10
api_cpu                   = "1"
api_memory                = "2Gi"
api_container_concurrency = 120

ingress                   = "INGRESS_TRAFFIC_ALL"
allow_unauthenticated     = true
api_allow_unauthenticated = true

labels = {
  app = "kdms"
  env = "prod"
}

kdms_event_id = "2026JB"

# Canonical public URLs (no trailing slash). Browser uses API_BASE_URL derived from api_url.
app_url = "https://kdms-prod-684080887473.asia-south1.run.app"
api_url = "https://kdms-api-prod-684080887473.asia-south1.run.app"

# Secret Manager secret *names* (values are not stored in this file).
secret_app_key     = "kdms-app-key"
secret_db_password = "kdms-db-password"
secret_service_key = "kdms-service-key"

# -----------------------------------------------------------------------------
# Optional: kdms-reports (enable after image exists in Artifact Registry)
# -----------------------------------------------------------------------------
# When true, set reports_image_uri to e.g.
# asia-south1-docker.pkg.dev/PROJECT/apps/kdms-reports@sha256:...
# and reports_url to the deployed service URL (see terraform output reports_service_url).
enable_reports_service = true

reports_service_name = "kdms-reports-prod"
reports_image_name   = "kdms-reports"
reports_image_uri    = ""
reports_image_digest = "sha256:ab83281cc81200fcbb5f7ab844f5cb025e32c9f9a2518e21de551d18df57c404"
reports_image_tag    = ""
# Placeholder — replace with actual URL after first deploy or from `gcloud run services describe`.
reports_url = "https://kdms-reports-prod-684080887473.asia-south1.run.app"

reports_min_instances         = 0
reports_max_instances         = 4
reports_cpu                   = "1"
reports_memory                = "2Gi"
reports_container_concurrency = 40
reports_allow_unauthenticated = true

# -----------------------------------------------------------------------------
# kdms-ocr — DECOMMISSIONED Phase 6/7 (enable_ocr_service must stay false)
# -----------------------------------------------------------------------------
enable_ocr_service = false
# ocr_url and KDMS_OCR_BASE_URL are no longer injected into Cloud Run env.

# -----------------------------------------------------------------------------
# kdms-registration (Phase 1.5) — enable after image in Artifact Registry + secrets
# -----------------------------------------------------------------------------
enable_registration_service = true

registration_service_name = "kdms-registration-prod"
registration_image_name   = "kdms-registration"
registration_image_uri    = ""
registration_image_digest = "sha256:5839e5455cc1f4fc0ca00e61e4bf184f069c2db06d9f0e2418b68bbaa8c6df1e"
registration_image_tag    = ""
# Public URL for kdms-registration-prod. Terraform sets KDMS_REGISTRATION_URL on kdms-api-prod
# (used by api/staffOcrExtract.php — required for staff "Scan ID Card" on addDevoteeI).
registration_url = "https://kdms-registration-prod-zeqw3ha4ya-el.a.run.app"

registration_max_instances         = 10
registration_cpu                   = "1"
registration_memory                = "1Gi"
registration_container_concurrency = 80
registration_allow_unauthenticated = true
registration_db_username           = "kdms_reg"

secret_registration_db_password  = "kdms-reg-db-password"
secret_document_ai_processor_id  = "document-ai-processor-id"

# Leave empty to use the processor default version set in Document AI Console.
# Set only when pinning a specific processorVersions/... id (e.g. for rollback).
document_ai_processor_version = ""

# Optional: only if the connection name must differ from project_id:region:instance
# cloudsql_connection_name = "project-12f4b54b-d692-4583-83b:asia-south1:mysql-skm-prod"
ocr_image_digest = "sha256:16068ad68c0269d90bf48e0332c728ce01cf4b82a15e62fcb1d32f87f13095c6"
