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
image_digest = "sha256:d20592f41ae0e8c62d83ca81bf4659afe0ad6ba3cf5b423a38efb382e32cac4a"
image_tag    = ""
# rolling_image_tag = "branch-main"  # when digest and image_tag are both empty

api_image_name   = "kdms-api"
api_image_digest = "sha256:1f60d42cb2897f1cc9117a06e9137b9c321259f07496ba45858e57614a9d5ee0"
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
reports_image_digest = "sha256:c5b533dbcf976e9e86e378a68799b31ea5188507163c06071eadbd93a711664d"
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
ocr_image_digest = "sha256:16bc23b6725a9921676cc4bf83e244f129e0a328d8ba75ec85bac41dec229537"
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
registration_image_digest = "sha256:66585e8a4a4637c51a5fcfdb718fdc6c037f90081e21c2708192fd214960aa28"
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

# Leave empty to use the processor default version set in Document AI Console.
# Set only when pinning a specific processorVersions/... id (e.g. for rollback).
document_ai_processor_version = ""

# Optional: only if the connection name must differ from project_id:region:instance
# cloudsql_connection_name = "project-12f4b54b-d692-4583-83b:asia-south1:mysql-skm-prod"
