# Stream C — Production DB switchover and BLOB migration

**Gate:** Run only after Streams A, B, and D are stable in **production**. Do **not** run migration scripts until §0 (DB switchover) is complete.

**Target database:** Cloud SQL `mysql-kdms-prod`, schema `kdms_prod`, user `kdms_user`.

---

## 0. Critical: current deploy uses staging DB

As of Phase 6 implementation review, **kdms-api-prod** and **kdms-prod** are wired to **staging**, not production:

| Setting | Current (staging) | Required for Stream C (production) |
|---------|-------------------|----------------------------------|
| Cloud SQL instance | `mysql-skm-prod` | `mysql-kdms-prod` |
| `KDMS_DB_NAME` / `DB_DATABASE` | `kdms` | `kdms_prod` |
| `KDMS_DB_USER` / `DB_USERNAME` | `kdms` | `kdms_user` |
| `KDMS_DB_SOCKET` | `…:mysql-skm-prod` | `project-12f4b54b-d692-4583-83b:asia-south1:mysql-kdms-prod` |

Baseline counts quoted earlier (~3,004 photo BLOBs / ~2,642 ID BLOBs) are from **staging**. After switchover, re-run the count query on **mysql-kdms-prod** / `kdms_prod` before migrating.

### 0.1 Update Terraform and redeploy (before any migration script)

Edit `terraform/terraform.tfvars`:

```hcl
cloudsql_instance = "mysql-kdms-prod"
db_name           = "kdms_prod"
db_username       = "kdms_user"
# Optional explicit connection name (defaults from project_id:region:cloudsql_instance):
# cloudsql_connection_name = "project-12f4b54b-d692-4583-83b:asia-south1:mysql-kdms-prod"
```

Confirm `secret_db_password` in Terraform points at the Secret Manager secret that holds **kdms_user**’s password on `mysql-kdms-prod` (typically `kdms-db-password`).

Ensure staff OCR env is set (same file):

```hcl
registration_url = "https://kdms-registration-prod-zeqw3ha4ya-el.a.run.app"
```

That value becomes **`KDMS_REGISTRATION_URL`** on **kdms-api-prod** (see `terraform/main.tf`). Required for `api/staffOcrExtract.php`.

`enable_ocr_service` must remain **`false`** (kdms-ocr decommissioned; **`KDMS_OCR_BASE_URL`** is no longer set on Cloud Run).

Apply and deploy both app services:

```bash
cd terraform
terraform plan   # expect kdms-prod + kdms-api-prod revision env/Cloud SQL attachment changes
terraform apply
```

### 0.2 Verify switchover on live revisions

```bash
gcloud run services describe kdms-api-prod \
  --region=asia-south1 \
  --project=project-12f4b54b-d692-4583-83b \
  --format="yaml(spec.template.spec.containers[0].env)"

gcloud run services describe kdms-prod \
  --region=asia-south1 \
  --project=project-12f4b54b-d692-4583-83b \
  --format="yaml(spec.template.spec.containers[0].env)"
```

Confirm:

| Variable | Expected |
|----------|----------|
| `KDMS_DB_NAME` / `DB_DATABASE` | `kdms_prod` |
| `KDMS_DB_USER` / `DB_USERNAME` | `kdms_user` |
| `KDMS_DB_SOCKET` | `…:mysql-kdms-prod` |
| `KDMS_REGISTRATION_URL` | `https://kdms-registration-prod-zeqw3ha4ya-el.a.run.app` (no trailing slash) |
| `KDMS_OCR_BASE_URL` | **absent** (removed) |
| `KDMS_GCS_PHOTOS_BUCKET` | `kdms-photos` (or prod bucket name) |

Smoke-test: login, search grid (lazy photos), Add Devotee → Scan ID Card (OCR), one staff photo upload.

---

## 1. Reports module path (Stream B)

Lazy photo changes live in the **kdms monorepo** reports tree (built into **kdms-reports-prod**):

| Path | Role |
|------|------|
| `kdms/Services/kdms-reports/` | **Canonical** — Cloud Run / CI image source |
| `kdms/Services/kdms-reports/api/devoteePhotoProxy.php` | Same-host photo proxy for reports UI |
| `kdms/Services/kdms-reports/Reports/rptGenerator.php` | Lazy `<img>` for duty/acco reports |

Some local XAMPP setups also mount a sibling folder `htdocs/kmreports/` (if present). Keep it in sync with `Services/kdms-reports/` or use only the monorepo copy for deploys.

There is **no** `kmreports/` directory inside the `kdms` git root; do not assume `kdms/kmreports/`.

---

## 2. Pre-migration checklist (production DB only)

- [ ] §0 complete: both Cloud Run services on `mysql-kdms-prod` / `kdms_prod`.
- [ ] `mysql-kdms-prod` instance is **RUNNABLE**.
- [ ] Phase 1a columns exist on **kdms_prod**:
  - `devotee_photo.Devotee_Photo_Gcs_Path`
  - `devotee_id.Devotee_ID_Image_Gcs_Path`
- [ ] **Production** baseline counts (not staging):

```sql
SELECT
  (SELECT COUNT(*) FROM devotee_photo WHERE Devotee_Photo IS NOT NULL) AS blobs_photo,
  (SELECT COUNT(*) FROM devotee_photo WHERE Devotee_Photo_Gcs_Path IS NOT NULL) AS gcs_photo,
  (SELECT COUNT(*) FROM devotee_id WHERE Devotee_ID_Image IS NOT NULL) AS blobs_id,
  (SELECT COUNT(*) FROM devotee_id WHERE Devotee_ID_Image_Gcs_Path IS NOT NULL) AS gcs_id;
```

Record these numbers in your runbook; do not use staging ~3,004 / ~2,642 unless they match prod.

- [ ] GCS bucket + `kdms-api` SA **objectAdmin** on `kdms-photos`.
- [ ] Streams A + B + D validated on **production** data.
- [ ] On-demand backup:

```bash
gcloud sql backups create --instance=mysql-kdms-prod \
  --project=project-12f4b54b-d692-4583-83b
```

Wait until backup status is **SUCCESSFUL**.

---

## 3. Migration procedure

Run from Cloud Shell (or host with Cloud SQL Auth Proxy to **mysql-kdms-prod**). Env must use `kdms_prod` / `kdms_user` and GCS ADC.

```bash
# 1. Dry-run + report (production counts)
php scripts/migrate_photos_to_gcs.php --dry-run --report

# 2. Small live batch
php scripts/migrate_photos_to_gcs.php --limit=10 --report

# 3. Verify sample keys + GCS
gsutil ls gs://kdms-photos/devotee/PXXXXXXXXXX/photo.jpg

# 4. Full migration
php scripts/migrate_photos_to_gcs.php --report

# 5. Null BLOBs only after visual verification
php scripts/null_blobs_after_migration.php --dry-run
php scripts/null_blobs_after_migration.php --limit=10
php scripts/null_blobs_after_migration.php
```

**Do not** null BLOBs until photos display correctly via GCS / lazy endpoints.

---

## 4. Rollback

| Situation | Action |
|-----------|--------|
| Script stopped mid-run | Unmigrated rows still have BLOB; dual-read works. |
| GCS wrong, BLOB intact | Stop; fix GCS; do not run null script. |
| Wrong DB connected | Stop immediately; revert Terraform to previous instance; redeploy. |
| Data corruption | Restore Cloud SQL backup; redeploy prior revision if needed. |

---

## 5. Post-migration (manual, off-peak)

```sql
OPTIMIZE TABLE devotee_photo;
OPTIMIZE TABLE devotee_id;
```

Optional Phase 7+ (zero non-null BLOBs + verified backup):

```sql
-- ALTER TABLE devotee_photo DROP COLUMN Devotee_Photo;
-- ALTER TABLE devotee_id DROP COLUMN Devotee_ID_Image;
```
