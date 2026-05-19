# Phase 1a validation report

**Date:** 2026-05-18  
**Status:** Code complete — **manual GCP/DB steps required** (see below)

---

## Design gaps addressed in this phase

| Gap | Resolution in Phase 1a |
|-----|-------------------------|
| `getPhoto.php` / `getIdImage.php` missing | **Created** with dual-read (GCS → BLOB) |
| GCS path convention | Bucket-relative paths in DB; bucket from `KDMS_GCS_PHOTOS_BUCKET` (default `kdms-photos`) |
| Unique index | `Phase_1a_unique_index_generated_column.sql` — `Devotee_ID_Unique_Key` (not raw columns) |
| Registration SA IAM | `run-kdms-registration@...` created in Terraform; bucket `objectAdmin` bound |
| Legacy `'Day visitor'` status | **Skipped** (no legacy rows expected); dashboard uses `'D'` |
| `addToPrintQueue` wrapper | Deferred to Phase 1.5 |
| Kitchen `print_log` | Deferred to Phase 3 |
| Separate buckets per status | **Single bucket** — see recommendation below |

---

## 1. GCS bucket (Terraform)

| Item | Value |
|------|--------|
| Bucket name | `kdms-photos` (variable `gcs_photos_bucket_name`) |
| Region | `asia-south1` (same as Cloud SQL / Cloud Run) |
| Uniform bucket-level access | Yes |
| Public access | Prevented (`public_access_prevention = enforced`) |
| IAM `roles/storage.objectAdmin` | `run-kdms@project-12f4b54b-d692-4583-83b.iam.gserviceaccount.com` |
| IAM `roles/storage.objectAdmin` | `run-kdms-registration@project-12f4b54b-d692-4583-83b.iam.gserviceaccount.com` |

**Operator checklist:**

- [ ] `gcloud services enable storage.googleapis.com --project=project-12f4b54b-d692-4583-83b`
- [ ] `cd terraform && terraform plan && terraform apply`
- [ ] Confirm outputs: `gcs_photos_bucket_name`, `kdms_registration_service_account_email`

---

## 2. Migration SQL

**File:** `api/config/DB Files/Phase_1a_gcs_and_dedup_tables.sql`

**Operator checklist:**

- [ ] Run duplicate pre-check (in SQL file comments)
- [ ] Execute migration on **staging** DB
- [ ] Execute on **production** when staging verified
- [ ] Confirm: `SHOW CREATE TABLE devotee_aliases;`
- [ ] Confirm: `SHOW CREATE TABLE devotee_merge_archive;`
- [ ] Confirm columns: `devotee_photo.Devotee_Photo_Gcs_Path`, `devotee_id.Devotee_ID_Image_Gcs_Path`
- [ ] Confirm index: `idx_devotee_id_type_number` on `devotee`

**Executed by:** _pending_  
**Environment:** _staging / prod_  
**Date:** _—_

---

## 3. Migration script dry-run

```bash
cd /path/to/kdms
composer install --no-dev
export KDMS_DB_HOST=... KDMS_DB_NAME=... KDMS_DB_USER=... KDMS_DB_PASSWORD=...
export KDMS_GCS_PHOTOS_BUCKET=kdms-photos
# ADC: gcloud auth application-default login  OR GOOGLE_APPLICATION_CREDENTIALS=...
php scripts/migrate_photos_to_gcs.php --dry-run
```

**Dry-run output (counts):**

| Metric | Count |
|--------|------:|
| `photo_would_migrate` | _pending_ |
| `id_would_migrate` | _pending_ |

**Note:** Live migration **not** run in Phase 1a.

---

## 4. Existing devotee photo (BLOB path)

| Test | Result |
|------|--------|
| Load devotee in KDMS UI with photo, `Devotee_Photo_Gcs_Path` NULL | _pending_ |
| Search/registration UI still shows base64 from BLOB (`devotees.php` unchanged) | Expected pass |

---

## 5. GCS dual-read via new API

```bash
# After login session cookie or service key:
curl -s "https://kdms-api-prod-.../api/getPhoto.php?devotee_key=P..." \
  -H "Cookie: ..." 
# Or: -H "X-KDMS-SERVICE-KEY: ..."
```

| Test | Expected | Result |
|------|----------|--------|
| Row with NULL Gcs_Path | `"source":"blob"` | _pending_ |
| Row with test object + path set | `"source":"gcs"` | _pending_ |

---

## 6. Dashboard day-visitor count

**Change:** `Temporary_Day_Visitors_Count` subquery now uses:

- `Devotee_Status = 'D'`
- `Devotee_Type = 'T'`
- `accommodation_master.Accomodation_Name = 'Other'`
- Requires `devotee_accomodation` for active event

**Phase 1.5:** PWA registration must assign accommodation **Other** so counts match.

| Test | Result |
|------|--------|
| Registration counts / dashboard reflects `D`+`T`+Other | _pending_ |

---

## 7. New tables

| Table | Created |
|-------|---------|
| `devotee_aliases` | _pending operator verify_ |
| `devotee_merge_archive` | _pending operator verify_ |

---

## Assumptions and manual review

1. **`accommodation_master` must contain `Accomodation_Name = 'Other'`** for the active event (capacity/availability initialized). Phase 1.5 registration will assign this accommodation to day visitors.
2. **Unique index** may fail with `Duplicate entry 'Aadhaar-'` when many rows have empty/`'-'` ID numbers — run `Phase_1a_normalize_id_before_unique_index.sql` (normalizes placeholders to NULL, then creates index). True duplicate Aadhaar numbers must be merged manually before index creation.
3. **`getPhoto.php` / `getIdImage.php`** are new; existing UI still uses inline base64 from `devotees.php` until later phases wire them in.
4. **Credentials:** DB credentials not stored in repo; operator runs SQL and dry-run with their own access.

---

## Single bucket vs multiple buckets (Q7)

**Recommendation: one bucket (`kdms-photos`), prefix isolation.**

| Approach | Verdict |
|----------|---------|
| Multiple buckets (staff vs PWA) | Extra IAM, migration, and env complexity; little gain |
| Single bucket + prefixes | `devotee/{Devotee_Key}/photo.jpg`; optional `id-staging/{date}/{uuid}.jpg` before register |
| Isolation | Separate **service accounts** and app logic (PWA only creates `D`/`T` rows); not bucket boundaries |

Staff and PWA “interference” is prevented by **application rules** (who can write which records), not separate buckets.

---

## Running KDMS locally after GCS + Document AI

| Component | Local approach |
|-----------|----------------|
| **KDMS main + API** | `docker compose -f docker-compose.split.yml up` (existing) |
| **MySQL** | XAMPP or Docker → `KDMS_DB_HOST=host.docker.internal:3306` |
| **GCS reads/writes** | Set `KDMS_GCS_PHOTOS_BUCKET=kdms-photos`; `gcloud auth application-default login` or service-account JSON via `GOOGLE_APPLICATION_CREDENTIALS` |
| **Without GCS** | Dual-read **falls back to BLOB** when path NULL or GCS unreachable |
| **Document AI (Phase 1.5)** | Registration container needs GCP credentials + processor ID; can mock OCR endpoint for UI-only local dev |
| **kmreports / ocr** | Reports: `localhost:8082`; OCR optional until retired |

Add to local `.env`:

```env
KDMS_GCS_PHOTOS_BUCKET=kdms-photos
# GOOGLE_APPLICATION_CREDENTIALS=/path/to/sa-key.json
```

---

## Code deliverables (implemented)

- `terraform/gcs.tf`
- `api/config/DB Files/Phase_1a_gcs_and_dedup_tables.sql`
- `includes/PhotoStorage.php`
- `api/getPhoto.php`, `api/getIdImage.php`
- `scripts/migrate_photos_to_gcs.php`
- `api/Interface/clsDashboard.php` (dashboard fix)
- `composer.json` + `composer.lock` (`google/cloud-storage`)
