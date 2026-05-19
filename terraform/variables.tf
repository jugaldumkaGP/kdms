variable "project_id" {
  description = "GCP project ID hosting Cloud Run and related resources."
  type        = string
  default     = "project-12f4b54b-d692-4583-83b"
}

variable "project_number" {
  description = "GCP project number (for documentation and cross-references)."
  type        = string
  default     = "684080887473"
}

variable "region" {
  description = "Region for the Cloud Run service."
  type        = string
  default     = "asia-south1"
}

variable "service_name" {
  description = "Cloud Run service name."
  type        = string
  default     = "kdms-prod"
}

variable "ar_repo" {
  description = "Artifact Registry repository id (short name)."
  type        = string
  default     = "apps"
}

variable "image_name" {
  description = "Artifact Registry image name within the repository."
  type        = string
  default     = "kdms-main"
}

variable "image_digest" {
  description = <<-EOT
    Optional sha256 digest of the pushed image — pins the exact artifact; avoids tag reuse/mismatch between registries.
    Accept 64 hex chars or "sha256:hex". When non-empty, it overrides image_tag in local.image_uri.
    After push: gcloud artifacts docker images describe REGION-docker.pkg.dev/PROJECT/apps/kdms:TAG \
      --format="value(image_summary.digest)"
  EOT
  type        = string
  default     = ""

  validation {
    condition = (
      trimspace(var.image_digest) == ""
      || can(regex("^sha256:[a-f0-9]{64}$", trimspace(lower(var.image_digest))))
      || can(regex("^[a-f0-9]{64}$", trimspace(lower(var.image_digest))))
    )
    error_message = "image_digest must be empty, 64 hex chars, or sha256: plus 64 hex chars."
  }
}

variable "image_tag" {
  description = <<-EOT
    Explicit container tag when not pinning by digest (e.g. short git SHA).
    Leave empty to deploy the rolling tag from CI (see rolling_image_tag), or set image_digest to pin an exact manifest for rollback.
  EOT
  type        = string
  default     = ""

  validation {
    condition     = !(trimspace(var.image_digest) != "" && trimspace(var.image_tag) != "")
    error_message = "When image_digest is set, leave image_tag empty (digest wins)."
  }
}

variable "rolling_image_tag" {
  description = <<-EOT
    Tag applied when image_digest and image_tag are both empty — typically the tag CI updates on each push (e.g. branch-main).
    Override per environment if your pipeline uses a different moving tag (some teams use "latest").
  EOT
  type        = string
  default     = "branch-main"

  validation {
    condition     = trimspace(var.rolling_image_tag) != ""
    error_message = "rolling_image_tag must be non-empty."
  }
}

variable "revision_trigger" {
  description = <<-EOT
    Bump when releasing a new image behind the same rolling tag (e.g. branch-main). Terraform compares literal image URIs;
    if the tag string is unchanged, plan may be empty unless this value changes or you pin image_digest.
    Set to a build id, date, or git SHA from CI. Ignored when empty (no extra annotation).
  EOT
  type        = string
  default     = ""
}

variable "runtime_sa_email" {
  description = "Service account email the revision runs as."
  type        = string
  default     = "run-kdms@project-12f4b54b-d692-4583-83b.iam.gserviceaccount.com"
}

variable "cloudsql_instance" {
  description = "Cloud SQL instance id (short name); full connection name is derived in locals."
  type        = string
  default     = "mysql-skm-prod"
}

variable "cloudsql_connection_name" {
  description = "Optional override for instance connection name (project:region:instance). Leave default null to derive from project_id, region, and cloudsql_instance."
  type        = string
  default     = null
}

variable "db_name" {
  description = "MySQL database name (DB_DATABASE)."
  type        = string
  default     = "kdms"
}

variable "db_username" {
  description = "MySQL username (non-secret)."
  type        = string
  default     = "kdms"
}

variable "min_instances" {
  description = "Minimum Cloud Run instances."
  type        = number
  default     = 0
}

variable "max_instances" {
  description = "Maximum Cloud Run instances."
  type        = number
  default     = 5
}

variable "cpu" {
  description = "CPU limit for the container (Cloud Run units)."
  type        = string
  default     = "1"
}

variable "memory" {
  description = "Memory limit for the container."
  type        = string
  default     = "2Gi"
}

variable "container_port" {
  description = "Primary HTTP container port."
  type        = number
  default     = 8080
}

variable "container_concurrency" {
  description = "Maximum concurrent requests per instance (containerConcurrency)."
  type        = number
  default     = 80
}

variable "ingress" {
  description = "Ingress traffic configuration for the service."
  type        = string
  default     = "INGRESS_TRAFFIC_ALL"
}

variable "cloud_run_deletion_protection" {
  description = <<-EOT
    Passed to google_cloud_run_v2_service.deletion_protection. The provider treats protection as enabled when unset,
    which blocks terraform destroy / removing a service from config. Default false so applies can replace or drop services.
    Set true only for extra safety; you must set false and apply before Terraform can destroy a managed service.
  EOT
  type        = bool
  default     = false
}

variable "allow_unauthenticated" {
  description = "If true, bind roles/run.invoker to allUsers."
  type        = bool
  default     = true
}

variable "labels" {
  description = "Labels applied to the Cloud Run service."
  type        = map(string)
  default = {
    app = "kdms"
    env = "prod"
  }
}

variable "kdms_event_id" {
  description = "KDMS_EVENT_ID env value (e.g. calendar year)."
  type        = string
  default     = "2026"
}

variable "app_url" {
  description = "Canonical public HTTPS URL of this Cloud Run service (no trailing slash), e.g. https://myservice-xxxx.asia-south1.run.app — must NOT include a /kdms path; production DocRoot maps to /. Used for WEBROOT_URL/API_BASE_URL derivation."
  type        = string
  default     = "https://kdms-prod-zeqw3ha4ya-el.a.run.app"
}

variable "secret_app_key" {
  description = "Secret Manager secret id holding the Laravel APP_KEY."
  type        = string
  default     = "kdms-app-key"
}

variable "secret_db_password" {
  description = "Secret Manager secret id holding the MySQL password."
  type        = string
  default     = "kdms-db-password"
}

variable "secret_service_key" {
  description = "Secret Manager secret id holding KDMS_SERVICE_KEY for trusted service calls."
  type        = string
  default     = "kdms-service-key"
}

variable "api_service_name" {
  description = "Cloud Run service name for split KDMS API."
  type        = string
  default     = "kdms-api-prod"
}

variable "api_image_name" {
  description = "Artifact Registry image name for split KDMS API."
  type        = string
  default     = "kdms-api"
}

variable "api_image_digest" {
  description = "Optional digest for API image; if empty, falls back to main image_digest/tag settings."
  type        = string
  default     = ""
}

variable "api_image_tag" {
  description = "Optional tag for API image when api_image_digest is empty."
  type        = string
  default     = ""
}

variable "api_url" {
  description = "Canonical public HTTPS URL for kdms-api service (no trailing slash)."
  type        = string
  default     = ""
}

variable "api_min_instances" {
  description = "Minimum Cloud Run instances for kdms-api."
  type        = number
  default     = 0
}

variable "api_max_instances" {
  description = "Maximum Cloud Run instances for kdms-api."
  type        = number
  default     = 10
}

variable "api_cpu" {
  description = "CPU limit for kdms-api container."
  type        = string
  default     = "1"
}

variable "api_memory" {
  description = "Memory limit for kdms-api container."
  type        = string
  default     = "2Gi"
}

variable "api_container_concurrency" {
  description = "Maximum concurrent requests per instance for kdms-api."
  type        = number
  default     = 120
}

variable "api_allow_unauthenticated" {
  description = "If true, bind roles/run.invoker to allUsers for kdms-api."
  type        = bool
  default     = true
}

variable "enable_reports_service" {
  description = "Create split Cloud Run service for kdms-reports."
  type        = bool
  default     = true
}

variable "reports_service_name" {
  description = "Cloud Run service name for kdms-reports."
  type        = string
  default     = "kdms-reports-prod"
}

variable "reports_image_uri" {
  description = "Optional fully qualified container image URI for kdms-reports. If empty, built from reports_image_name + reports_image_digest/tag."
  type        = string
  default     = ""
}

variable "reports_image_name" {
  description = "Artifact Registry image name for kdms-reports."
  type        = string
  default     = "kdms-reports"
}

variable "reports_image_digest" {
  description = "Optional digest for reports image. If set, preferred over tag."
  type        = string
  default     = ""
}

variable "reports_image_tag" {
  description = "Optional tag for reports image when reports_image_digest is empty."
  type        = string
  default     = ""
}

variable "reports_url" {
  description = "Canonical public HTTPS URL for kdms-reports service (no trailing slash)."
  type        = string
  default     = ""
}

variable "reports_min_instances" {
  description = "Minimum Cloud Run instances for kdms-reports."
  type        = number
  default     = 0
}

variable "reports_max_instances" {
  description = "Maximum Cloud Run instances for kdms-reports."
  type        = number
  default     = 4
}

variable "reports_cpu" {
  description = "CPU limit for kdms-reports container."
  type        = string
  default     = "1"
}

variable "reports_memory" {
  description = "Memory limit for kdms-reports container."
  type        = string
  default     = "2Gi"
}

variable "reports_container_concurrency" {
  description = "Maximum concurrent requests per instance for kdms-reports."
  type        = number
  default     = 40
}

variable "reports_allow_unauthenticated" {
  description = "If true, bind roles/run.invoker to allUsers for kdms-reports."
  type        = bool
  default     = true
}

variable "enable_ocr_service" {
  description = "Create split Cloud Run service for kdms-ocr."
  type        = bool
  default     = true
}

variable "ocr_service_name" {
  description = "Cloud Run service name for kdms-ocr."
  type        = string
  default     = "kdms-ocr-prod"
}

variable "ocr_image_uri" {
  description = "Optional fully qualified container image URI for kdms-ocr. If empty, built from ocr_image_name + ocr_image_digest/tag."
  type        = string
  default     = ""
}

variable "ocr_image_name" {
  description = "Artifact Registry image name for kdms-ocr."
  type        = string
  default     = "kdms-ocr"
}

variable "ocr_image_digest" {
  description = "Optional digest for OCR image. If set, preferred over tag."
  type        = string
  default     = ""
}

variable "ocr_image_tag" {
  description = "Optional tag for OCR image when ocr_image_digest is empty."
  type        = string
  default     = ""
}

variable "ocr_url" {
  description = "Canonical public HTTPS URL for kdms-ocr service (no trailing slash)."
  type        = string
  default     = ""
}

variable "ocr_min_instances" {
  description = "Minimum Cloud Run instances for kdms-ocr."
  type        = number
  default     = 0
}

variable "ocr_max_instances" {
  description = "Maximum Cloud Run instances for kdms-ocr."
  type        = number
  default     = 6
}

variable "ocr_cpu" {
  description = "CPU limit for kdms-ocr container."
  type        = string
  default     = "1"
}

variable "ocr_memory" {
  description = "Memory limit for kdms-ocr container."
  type        = string
  default     = "2Gi"
}

variable "ocr_container_port" {
  description = "Container HTTP port for kdms-ocr service."
  type        = number
  default     = 5001
}

variable "ocr_container_concurrency" {
  description = "Maximum concurrent requests per instance for kdms-ocr."
  type        = number
  default     = 20
}

variable "ocr_allow_unauthenticated" {
  description = "If true, bind roles/run.invoker to allUsers for kdms-ocr."
  type        = bool
  default     = true
}

variable "gcs_photos_bucket_name" {
  description = "Private GCS bucket for devotee photos and ID images (Phase 1a+)."
  type        = string
  default     = "kdms-photos"
}
