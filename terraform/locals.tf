locals {
  # Optional: set var.revision_trigger when rolling tags so Terraform creates a new revision (see README).
  revision_template_annotations = trimspace(var.revision_trigger) == "" ? {} : {
    "terraform.io/revision-trigger" = var.revision_trigger
  }

  sql_connection_name = coalesce(
    var.cloudsql_connection_name,
    "${var.project_id}:${var.region}:${var.cloudsql_instance}"
  )

  # Resolve image: digest (immutable / rollback) > explicit tag > rolling tag (CI “latest”, e.g. branch-main).
  image_digest_hex = trimspace(var.image_digest) == "" ? "" : replace(trimspace(lower(var.image_digest)), "sha256:", "")
  image_base       = "${var.region}-docker.pkg.dev/${var.project_id}/${var.ar_repo}/${var.image_name}"
  image_uri = local.image_digest_hex != "" ? "${local.image_base}@sha256:${local.image_digest_hex}" : (
    trimspace(var.image_tag) != "" ? "${local.image_base}:${trimspace(var.image_tag)}" : "${local.image_base}:${var.rolling_image_tag}"
  )

  api_image_digest_hex = trimspace(var.api_image_digest) == "" ? "" : replace(trimspace(lower(var.api_image_digest)), "sha256:", "")
  api_image_base       = "${var.region}-docker.pkg.dev/${var.project_id}/${var.ar_repo}/${var.api_image_name}"
  api_image_uri = local.api_image_digest_hex != "" ? "${local.api_image_base}@sha256:${local.api_image_digest_hex}" : (
    trimspace(var.api_image_tag) != "" ? "${local.api_image_base}:${trimspace(var.api_image_tag)}" : "${local.api_image_base}:${var.rolling_image_tag}"
  )

  reports_image_digest_hex = trimspace(var.reports_image_digest) == "" ? "" : replace(trimspace(lower(var.reports_image_digest)), "sha256:", "")
  reports_image_base       = "${var.region}-docker.pkg.dev/${var.project_id}/${var.ar_repo}/${var.reports_image_name}"
  reports_image_uri = trimspace(var.reports_image_uri) != "" ? trimspace(var.reports_image_uri) : (
    local.reports_image_digest_hex != "" ? "${local.reports_image_base}@sha256:${local.reports_image_digest_hex}" : (
      trimspace(var.reports_image_tag) != "" ? "${local.reports_image_base}:${trimspace(var.reports_image_tag)}" : "${local.reports_image_base}:${var.rolling_image_tag}"
    )
  )

  ocr_image_digest_hex = trimspace(var.ocr_image_digest) == "" ? "" : replace(trimspace(lower(var.ocr_image_digest)), "sha256:", "")
  ocr_image_base       = "${var.region}-docker.pkg.dev/${var.project_id}/${var.ar_repo}/${var.ocr_image_name}"
  ocr_image_uri = trimspace(var.ocr_image_uri) != "" ? trimspace(var.ocr_image_uri) : (
    local.ocr_image_digest_hex != "" ? "${local.ocr_image_base}@sha256:${local.ocr_image_digest_hex}" : (
      trimspace(var.ocr_image_tag) != "" ? "${local.ocr_image_base}:${trimspace(var.ocr_image_tag)}" : "${local.ocr_image_base}:${var.rolling_image_tag}"
    )
  )

  registration_image_digest_hex = trimspace(var.registration_image_digest) == "" ? "" : replace(trimspace(lower(var.registration_image_digest)), "sha256:", "")
  registration_image_base       = "${var.region}-docker.pkg.dev/${var.project_id}/${var.ar_repo}/${var.registration_image_name}"
  registration_image_uri = trimspace(var.registration_image_uri) != "" ? trimspace(var.registration_image_uri) : (
    local.registration_image_digest_hex != "" ? "${local.registration_image_base}@sha256:${local.registration_image_digest_hex}" : (
      trimspace(var.registration_image_tag) != "" ? "${local.registration_image_base}:${trimspace(var.registration_image_tag)}" : "${local.registration_image_base}:${var.rolling_image_tag}"
    )
  )

  registration_pwa_cors_origins = distinct(compact(concat(
    var.registration_pwa_cors_origins,
    trimspace(var.registration_url) != "" ? [trimsuffix(trimspace(var.registration_url), "/")] : []
  )))

  # Public HTTPS URL without trailing slash (site_config derives WEBROOT / API URLs from these).
  # Cloud Run revision URL has no /kdms segment — Docker local uses kdms-prefix vhost separately.
  app_public_base = trimsuffix(trimspace(var.app_url), "/")
  api_public_base = trimspace(var.api_url) != "" ? trimsuffix(trimspace(var.api_url), "/") : "${local.app_public_base}/api"
  # PHP endpoints (manageAdmin.php, …) live under /api/ on the API host. Use this for KMREPORTS_API_BASE_URL etc.
  api_dir_http_base = trimspace(var.api_url) != "" ? "${trimsuffix(trimspace(var.api_url), "/")}/api" : "${local.app_public_base}/api"
  # Plain env vars before APP_URL (secrets follow; APP_URL applied last in main.tf).
  # KDMS_OCR_BASE_URL removed Phase 6/7 (kdms-ocr decommissioned; staff OCR via kdms-registration).
  env_vars_plain_prefix = {
    APP_ENV           = "production"
    APP_DEBUG         = "false"
    LOG_CHANNEL       = "stderr"
    SESSION_DRIVER    = "database"
    SESSION_LIFETIME  = "28800"
    CACHE_DRIVER      = "array"
    TRUSTED_PROXIES   = "*"
    KDMS_EVENT_ID     = var.kdms_event_id
    WEBROOT_URL       = "${local.app_public_base}/"
    # Must include /api/ when api_url is a separate Cloud Run host (PHP lives under /api/).
    API_BASE_URL      = "${local.api_dir_http_base}/"
    # Server-side curl (login/API) hits Apache on loopback — no /kdms prefix (production vhost is root DocRoot).
    KDMS_INTERNAL_ORIGIN = "http://127.0.0.1:${var.container_port}"
    # PDO expects KDMS_* vars (see api/config/database.php); Laravel-style DB_* are kept for Composer/tools.
    KDMS_DB_NAME   = var.db_name
    KDMS_DB_USER   = var.db_username
    KDMS_DB_SOCKET          = "/cloudsql/${local.sql_connection_name}"
    KDMS_GCS_PHOTOS_BUCKET  = var.gcs_photos_bucket_name
    DB_CONNECTION  = "mysql"
    DB_HOST        = "/cloudsql/${local.sql_connection_name}"
    DB_PORT        = "3306"
    DB_DATABASE    = var.db_name
    DB_USERNAME    = var.db_username
  }

  ordered_plain_prefix_keys = [
    "APP_ENV",
    "APP_DEBUG",
    "LOG_CHANNEL",
    "SESSION_DRIVER",
    "SESSION_LIFETIME",
    "CACHE_DRIVER",
    "TRUSTED_PROXIES",
    "KDMS_EVENT_ID",
    "WEBROOT_URL",
    "API_BASE_URL",
    "KDMS_INTERNAL_ORIGIN",
    "KDMS_DB_NAME",
    "KDMS_DB_USER",
    "KDMS_DB_SOCKET",
    "KDMS_GCS_PHOTOS_BUCKET",
    "DB_CONNECTION",
    "DB_HOST",
    "DB_PORT",
    "DB_DATABASE",
    "DB_USERNAME",
  ]

  # Full plain var set (APP_URL is applied after secret envs in main.tf).
  env_vars = merge(local.env_vars_plain_prefix, { APP_URL = var.app_url })

  secret_env_vars = {
    APP_KEY = {
      secret  = var.secret_app_key
      version = "latest"
    }
    DB_PASSWORD = {
      secret  = var.secret_db_password
      version = "latest"
    }
    KDMS_DB_PASSWORD = {
      secret  = var.secret_db_password
      version = "latest"
    }
    KDMS_SERVICE_KEY = {
      secret  = var.secret_service_key
      version = "latest"
    }
  }

  ordered_secret_env_keys = ["APP_KEY", "DB_PASSWORD", "KDMS_DB_PASSWORD", "KDMS_SERVICE_KEY"]
}
