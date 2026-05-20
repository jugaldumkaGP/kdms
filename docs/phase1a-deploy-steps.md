# Phase 1a — operator deploy steps

## Prerequisites

- `gcloud` CLI authenticated to project `project-12f4b54b-d692-4583-83b`
- `terraform` >= 1.x
- MySQL client or Cloud Shell for DB migration
- Permission: Storage Admin, Cloud Run Admin, Terraform state bucket

---

## Step 1 — Enable APIs (one-time)

```bash
gcloud services enable storage.googleapis.com \
  --project=project-12f4b54b-d692-4583-83b
```

---

## Step 2 — Terraform (bucket + registration SA + IAM)

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/kdms/terraform

terraform init

terraform plan \
  -var-file=terraform.tfvars \
  -target=google_service_account.kdms_registration \
  -target=google_storage_bucket.kdms_photos \
  -target=google_storage_bucket_iam_member.kdms_api_photos_object_admin \
  -target=google_storage_bucket_iam_member.kdms_registration_photos_object_admin

terraform apply \
  -var-file=terraform.tfvars \
  -target=google_service_account.kdms_registration \
  -target=google_storage_bucket.kdms_photos \
  -target=google_storage_bucket_iam_member.kdms_api_photos_object_admin \
  -target=google_storage_bucket_iam_member.kdms_registration_photos_object_admin
```

Or full apply if you prefer:

```bash
terraform apply -var-file=terraform.tfvars
```

Verify:

```bash
gcloud storage buckets describe gs://kdms-photos --project=project-12f4b54b-d692-4583-83b

gcloud iam service-accounts describe \
  run-kdms-registration@project-12f4b54b-d692-4583-83b.iam.gserviceaccount.com \
  --project=project-12f4b54b-d692-4583-83b
```

---

## Step 3 — Database migration

**Staging first**, then production. Use the **single combined file**:

`api/config/DB Files/Phase_1a_production_complete.sql`

```bash
# Example via Cloud SQL Auth Proxy (adjust instance/connection):
# cloud_sql_proxy -instances=project-12f4b54b-d692-4583-83b:asia-south1:mysql-skm-prod=tcp:3307

mysql -h 127.0.0.1 -P 3307 -u kdms -p YOUR_DB_NAME < \
  "api/config/DB Files/Phase_1a_production_complete.sql"
```

The script runs in order: schema → generated column → duplicate report → auto-resolve (~160 groups) → verify → unique index.

If re-running after partial success, see skip notes at the top of the SQL file.

---

## Step 4 — Build and deploy kdms-api image

CI usually pushes to Artifact Registry on merge. Manual build:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/kdms

composer install --no-dev --no-interaction

# Build & push (adjust tag):
gcloud builds submit --tag asia-south1-docker.pkg.dev/project-12f4b54b-d692-4583-83b/apps/kdms-api:phase1a \
  --project=project-12f4b54b-d692-4583-83b .

# Or use existing GitHub Actions workflow after push to main.
```

Deploy new revision (Terraform picks up tag/digest in `terraform.tfvars`):

```bash
cd terraform
terraform apply -var-file=terraform.tfvars
```

Confirm env on revision:

```bash
gcloud run services describe kdms-api-prod \
  --region=asia-south1 \
  --project=project-12f4b54b-d692-4583-83b \
  --format='yaml(spec.template.spec.containers[0].env)'
```

Expect `KDMS_GCS_PHOTOS_BUCKET=kdms-photos`.

**Also deploy `kdms-prod` (main)** if it shares the same image — same build artifact.

---

## Step 5 — Dry-run photo migration (optional)

From Cloud Shell or machine with DB + GCP access:

```bash
# Cloud Shell / proxy to Cloud SQL:
export KDMS_DB_SOCKET=/cloudsql/project-12f4b54b-d692-4583-83b:asia-south1:mysql-skm-prod
export KDMS_DB_NAME=kdms
export KDMS_DB_USER=kdms
export KDMS_DB_PASSWORD='...'   # from Secret Manager

# Mac/XAMPP from host (not Docker) — override host; .env often has host.docker.internal:
export KDMS_DB_HOST=127.0.0.1:3306

export KDMS_GCS_PHOTOS_BUCKET=kdms-photos
# gcloud auth application-default login   # for live run only

php scripts/migrate_photos_to_gcs.php --dry-run
```

Dry-run uses `COUNT(*)` only (no BLOBs loaded). Live mode processes **one BLOB per row** to avoid memory exhaustion.

Production live migration (batched):

```bash
# Repeat until pending counts are zero
php scripts/migrate_photos_to_gcs.php --limit=500
php scripts/migrate_photos_to_gcs.php --limit=500
```

Optional: `KDMS_MIGRATE_MAX_BLOB_MB=20` skips oversized images with a log line.

Do **not** run full live migration until Phase 6/7 unless explicitly approved.

---

## Step 6 — Smoke tests

1. Log into KDMS → open devotee with photo → still displays.
2. `GET /api/getPhoto.php?devotee_key=...` (session or service key) → JSON with `image_base64`, `source:blob`.
3. Upload test JPEG to `gs://kdms-photos/devotee/{KEY}/photo.jpg`; set `Devotee_Photo_Gcs_Path`; call API → `source:gcs`.
4. Dashboard / registration counts → day-visitor metric uses `D` + `T` + accommodation **Other**.

---

## Step 7 — Confirm accommodation “Other”

Ensure `accommodation_master` has **`Accomodation_Key = 'othr'`** (day visitor “Other”) and an `accommodation_availability` row for `KDMS_EVENT_ID` (e.g. `2026JB`). Phase 1.5 PWA assigns that key on register.

```sql
SELECT Accomodation_Key, Accomodation_Name FROM accommodation_master
WHERE Accomodation_Key = 'othr';
```

---

## Credentials

DB passwords are **not** required in the repo. Use Secret Manager:

```bash
gcloud secrets versions access latest --secret=kdms-db-password \
  --project=project-12f4b54b-d692-4583-83b
```

Share staging/prod connection details only via your secure channel if an implementer needs to run dry-run on your behalf.
