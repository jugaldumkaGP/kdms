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
image_digest = "sha256:70c7f6d4af8cb538a3e5ce3f28b14d8352873e4ae94d6876783b2426fc01ac40"
image_tag    = ""
# rolling_image_tag = "branch-main"  # when digest and image_tag are both empty

api_image_name   = "kdms-api"
api_image_digest = "sha256:29a182653c3bd17ab22d306d3097b6c37b57e4ddbdb5f51002ed7c2d5628f194"
api_image_tag    = ""

runtime_sa_email  = "run-kdms@project-12f4b54b-d692-4583-83b.iam.gserviceaccount.com"
cloudsql_instance = "mysql-skm-prod"
db_name           = "kdms"
db_username       = "kdms"

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
reports_image_digest = "sha256:b66bc2a9dfc235ca7568841a5cddf6b52b2f1318ec8a7ff1e858b55d1e8ec616"
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
# Optional: kdms-ocr Python service (enable after image exists)
# -----------------------------------------------------------------------------
enable_ocr_service = true

ocr_service_name = "kdms-ocr-prod"
ocr_image_name   = "kdms-ocr"
ocr_image_uri    = ""
ocr_image_digest = "sha256:fbcb8467e892d161f2835e0aece059864e63170f825fd2c7ea4cb64081655fc6"
ocr_image_tag    = ""
ocr_url          = "https://kdms-ocr-prod-684080887473.asia-south1.run.app"

ocr_min_instances         = 0
ocr_max_instances         = 6
ocr_cpu                   = "1"
ocr_memory                = "2Gi"
ocr_container_port        = 5001
ocr_container_concurrency = 20
ocr_allow_unauthenticated = true

# -----------------------------------------------------------------------------
# kdms-registration (Phase 1.5) — enable after image in Artifact Registry + secrets
# -----------------------------------------------------------------------------
enable_registration_service = true

registration_service_name = "kdms-registration-prod"
registration_image_name   = "kdms-registration"
registration_image_uri    = ""
registration_image_digest = "sha256:14835575ef5093f75ffd745a539837e87267a2fba3f111363e2dc2bf248a72c7"
registration_image_tag    = ""
# Set after first deploy (QR poster / validation):
registration_url = "https://kdms-registration-prod-zeqw3ha4ya-el.a.run.app"

registration_max_instances         = 10
registration_cpu                   = "1"
registration_memory                = "1Gi"
registration_container_concurrency = 80
registration_allow_unauthenticated = true
registration_db_username           = "kdms_reg"

secret_registration_db_password  = "kdms-reg-db-password"
secret_document_ai_processor_id  = "document-ai-processor-id"

# Pin foundation model until custom kdms_aadhaar_260519 (93bab276fea4e9cc) F1 is production-ready.
# Without this, :process uses the custom default (0 entities on test Aadhaar as of 2026-05-20).
document_ai_processor_version = "pretrained-foundation-model-v1.5-2025-08-06"

# Optional: only if the connection name must differ from project_id:region:instance
# cloudsql_connection_name = "project-12f4b54b-d692-4583-83b:asia-south1:mysql-skm-prod"
