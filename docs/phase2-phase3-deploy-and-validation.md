# Phase 2 + Phase 3 — pre-deploy, deploy, and validation

Operator checklist for production (or staging first). Adjust hostnames, DB names, and role keys to match your environment.

**Related docs:** `docs/validation_report_phase2.md`, `docs/validation_report_phase3.md`, `docs/phase1a-deploy-steps.md`, `docs/phase2-deduplication-spec.md`

---

## What ships in this release

| Component | Cloud Run service | Repo path |
|-----------|-------------------|-----------|
| Staff UI (nav, kitchen, print cards, search photos) | `kdms-prod` | `UI/`, `includes/`, `Logic/` |
| API (searchDevotee GCS hydrate, dedup, print queue) | `kdms-api-prod` | `api/`, `includes/` |
| Registration PWA (dedup-first, `othr` accommodation) | `kdms-registration-prod` | `Services/kdms-registration/` |

`kdms-main` and `kdms-api` use the **same root image** (two services, one build). Registration is a **separate** image.

---

## A. Pre-deployment

### A1. Code and config

- [ ] Merge Phase 2 + Phase 3 (and registration `othr` fix if not already on `main`) to the branch CI builds from (`main`).
- [ ] Confirm `terraform/terraform.tfvars`:
  - `kdms_event_id` matches active event (e.g. `2026JB`)
  - `app_url` / `api_url` are correct HTTPS URLs (no trailing slash)
- [ ] Confirm GCS photos work in target env: `KDMS_GCS_PHOTOS_BUCKET=kdms-photos` on **both** `kdms-prod` and `kdms-api-prod` (Phase 1a).
- [ ] Secret `kdms-service-key` matches registration service env (dedup API).

### A2. Database — run on **staging first**, then production

Run in order; skip steps already applied.

| Order | Script | Required for |
|-------|--------|----------------|
| 1 | `api/config/DB Files/Phase_1a_production_complete.sql` | GCS columns, indexes (if not done) |
| 2 | `api/config/DB Files/Phase_2_remarks_column.sql` | Dedup remarks (`remark_type='dedup'`) |
| 3 | `scripts/mysql_grant_kdms_page_ids.sql` STEP 1–2 | Widen `asset_key`, register keys including **`KD-KITCHEN`** |

Example (Cloud SQL via proxy — adjust instance/user/db):

```bash
mysql -h 127.0.0.1 -P 3307 -u kdms -p kdms < "api/config/DB Files/Phase_2_remarks_column.sql"

mysql -h 127.0.0.1 -P 3307 -u kdms -p kdms < scripts/mysql_grant_kdms_page_ids.sql
```

### A3. Permissions (manual DB)

**Kitchen staff** — minimal access:

```sql
INSERT IGNORE INTO asset_list (asset_key, asset_name, asset_updated_by, asset_update_date_time)
VALUES ('KD-KITCHEN', 'KDMS.kitchenDashboard', 'deploy', NOW());

INSERT IGNORE INTO user_access (user_role_key, asset_key, access_value, access_updated_by, access_update_date_time)
VALUES ('YOUR_KITCHEN_ROLE', 'KD-KITCHEN', 'ALL', 'deploy', NOW());
```

**Print / search staff** — ensure role `Access` CSV includes `KD-DVT-SCR` (and existing keys as today). Option A nav: without `KD-DVT-SCR`, print/search/OCR links are hidden.

- [ ] Users must **log out and log in** after `user_access` changes so `$_SESSION['Access']` refreshes.

### A4. DB user grants (Phase 2 — if not already done)

Registration DB user and `kdms` app user need rights per `docs/phase2-deduplication-spec.md` (DELETE on `devotee` for merge, child table UPDATE, etc.). See `scripts/create_registration_db_user.sql` for registration-only user.

### A5. Staging smoke (recommended)

- [ ] Deploy to staging URLs first (same steps as Section B).
- [ ] Complete Section C on staging before production apply.

---

## B. Deployment

### B1. Build images (CI — preferred)

```bash
# On laptop: push to main (or workflow_dispatch)
git push origin main
```

GitHub Actions (`.github/workflows/push-gar.yml`) builds and pushes:

- `kdms-main` → Artifact Registry `apps/kdms-main`
- `kdms-api` → `apps/kdms-api`
- `kdms-registration` → `apps/kdms-registration`

Wait for workflow **green** and note commit SHA.

### B1b. Pin digests (recommended for production)

After CI completes, either:

- Let the **`pin-tfvars`** job commit digests to `terraform/terraform.tfvars`, then:

```bash
git pull origin main
```

- Or run locally (macOS-safe script):

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/kdms
./terraform/scripts/ci-update-image-digests.sh
```

Verify `terraform.tfvars` shows new `image_digest` / `api_image_digest` / `registration_image_digest` matching the build you intend to ship.

### B2. Terraform apply

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/kdms/terraform
terraform init
terraform plan -var-file=terraform.tfvars
terraform apply -var-file=terraform.tfvars
```

This rolls **kdms-prod**, **kdms-api-prod**, and (if enabled) **kdms-registration-prod** to pinned images.

### B3. Post-apply verification

```bash
gcloud run services describe kdms-prod --region=asia-south1 \
  --project=project-12f4b54b-d692-4583-83b \
  --format='value(status.latestReadyRevisionName,status.url)'

gcloud run services describe kdms-api-prod --region=asia-south1 \
  --project=project-12f4b54b-d692-4583-83b \
  --format='value(status.latestReadyRevisionName,status.url)'

gcloud run services describe kdms-registration-prod --region=asia-south1 \
  --project=project-12f4b54b-d692-4583-83b \
  --format='value(status.latestReadyRevisionName,status.url)'
```

- [ ] New revision timestamp matches deploy time.
- [ ] `KDMS_EVENT_ID` / `kdms_event_id` on revision matches event in DB.

### B4. Local XAMPP (optional dev only)

Not required for Cloud Run production. Sync code + `composer install`; ensure `.env` has DB and optional GCS credentials for photo tests.

---

## C. Validation — Phase 3 (staff UI + kitchen)

Use a test user per role. Fill `docs/validation_report_phase3.md` checkboxes when done.

### C1. Navigation and ACL

| Test | Steps | Expected |
|------|--------|----------|
| Option A — print staff | Login with `KD-DVT-SCR` | Sidebar: Search, CTP, **Day Visitor Print Queue**, RPC, OCR visible |
| Kitchen only | Login with **only** `KD-KITCHEN` | Kitchen link visible; print/search/OCR **hidden** |
| Kitchen URL | Open `/UI/kitchenDashboard.php` without `KD-KITCHEN` | Access denied |
| Kitchen URL | With `KD-KITCHEN` | Page loads |

### C2. Day visitor print queue

| Test | Steps | Expected |
|------|--------|----------|
| Queue label | Nav link | **Day Visitor Print Queue** (not “Temporary Cards…”) |
| TMP list | `devoteeSearchResult.php?mode=SET&key=TMP` | Only `Print_Status=A`, status D, type T |
| Print + return | Select card → Print → confirm queue removal | Returns to **TMP** (not CTP) |
| Card photo | Print day visitor with GCS-only photo | Photo on card (security) |
| Search grid | Same devotee in TMP/search | Thumbnail shows (not placeholder) |

### C3. Kitchen counts

Open **Kitchen Dashboard**; note three numbers. Refresh after 5 minutes (auto) or reload.

| Test | Steps | Expected |
|------|--------|----------|
| Allocated | Compare to manual SQL: Allocated for event, **not** D+T | Matches **Allocated devotees** |
| No double-count | Day visitor with allocation: print card today | Increases **day visitors printed today** only; **allocated** unchanged |
| Own arrangement / local | Allocated non–day-visitor with own-arrangement or local | **Included** in allocated count |
| After print | Print one day visitor card (with `eventId` in queue removal) | **Day visitors printed today** +1; total = allocated + day visitors |

Manual SQL (replace event id):

```sql
-- Allocated (excludes day visitors)
SELECT COUNT(DISTINCT d.Devotee_Key)
FROM devotee d
JOIN devotee_accomodation da ON d.Devotee_Key = da.Devotee_Key
WHERE da.Accommodation_Event = '2026JB'
  AND da.Accomodation_Status = 'Allocated'
  AND NOT (d.Devotee_Status = 'D' AND d.Devotee_Type = 'T');

-- Day visitors printed today
SELECT COUNT(DISTINCT pl.Devotee_Key)
FROM print_log pl
JOIN devotee d ON pl.Devotee_Key = d.Devotee_Key
WHERE pl.Event_Id = '2026JB'
  AND DATE(pl.Print_Date_Time) = CURDATE()
  AND d.Devotee_Status = 'D'
  AND d.Devotee_Type = 'T';
```

### C4. Resident print (regression)

| Test | Expected |
|------|----------|
| CTP queue + print via `rptCardsPrint.php` | Unchanged; photos work |
| RPC list | Recently printed loads |

---

## D. Validation — Phase 2 (deduplication)

Complete `docs/validation_report_phase2.md` on the **same** staging/prod environment.

### D1. API smoke

```bash
export API="https://kdms-api-prod-684080887473.asia-south1.run.app"
export KDMS_SERVICE_KEY="<from Secret Manager>"

# Invalid key → 401
curl -s -o /dev/null -w "%{http_code}\n" -X POST "$API/api/deduplicateDevotee.php" \
  -H "Content-Type: application/json" \
  -H "X-KDMS-SERVICE-KEY: wrong" \
  -d '{}'
```

### D2. Registration path

- [ ] New day visitor via PWA → appears in **TMP** queue
- [ ] `devotee_accomodation` row created (`othr` / Other) after register
- [ ] Duplicate Aadhaar → merge/survivor behaviour per spec

### D3. Staff merge UI

- [ ] `addDevoteeI.php` — dedup hints load; admin merge works (not legacy stub)

---

## E. Validation — registration + photos (cross-cutting)

| Test | Expected |
|------|----------|
| `GET /api/getPhoto.php?devotee_key=...` | JPEG for GCS or BLOB row |
| Register with photo | GCS path in `devotee_photo`; card print/search show image |

---

## F. Rollback

1. Restore previous `image_digest` / `api_image_digest` / `registration_image_digest` in `terraform.tfvars` (last known good).
2. `terraform apply -var-file=terraform.tfvars`
3. DB migrations are **not** auto-reverted; Phase 2 remarks column and `KD-KITCHEN` asset rows are safe to leave in place.

---

## G. Production sign-off

- [ ] Staging Section C + D passed
- [ ] Production deploy (Section B) completed
- [ ] Production Section C + D passed
- [ ] `validation_report_phase3.md` and `validation_report_phase2.md` dated and signed off
- [ ] Kitchen and print roles granted; staff notified to re-login

**Gate:** Do not enable public registration QR at scale until Phase 2 dedup + Phase 3 print/kitchen checks pass on production.
