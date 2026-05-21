# Phase 6 — Operational findings (post-implementation)

Recorded from production/staging review. Use with `docs/stream-c-db-switchover.md`.

## 1. Reports directory

| Location | Use |
|----------|-----|
| **`kdms/Services/kdms-reports/`** | Canonical source for **kdms-reports-prod** image (CI/Terraform). Stream B lazy photos implemented here. |
| **`htdocs/kmreports/`** (sibling repo/folder, if present) | Optional local XAMPP mount; keep in sync with `Services/kdms-reports` or deploy from monorepo only. |

There is no `kdms/kmreports/` path inside the kdms git tree.

Photo-related report PHP files (both trees when synced):

- `Reports/rptGenerator.php` (shared lazy rendering)
- `Reports/rptDutyReport.php`, `rptOfficeDuty.php`, `rptAcco.php`, `rptAttendanceReport.php`
- `Reports/rptCardsPrint.php` — **eager** base64 (print; unchanged)
- `Reports/excel/excelRptDutyReport.php` — photos omitted

## 2. Database: staging vs production

**Current Terraform (`terraform.tfvars`):** `mysql-skm-prod` / `kdms` / `kdms`.

**Stream C requires:** `mysql-kdms-prod` / `kdms_prod` / `kdms_user` on **kdms-prod** and **kdms-api-prod** before any migration script.

Staging baseline (~3,004 / ~2,642 BLOBs) is **not** authoritative for production migration planning.

## 3. `KDMS_REGISTRATION_URL`

- Terraform variable: **`registration_url`** in `terraform.tfvars`
- Cloud Run env on **kdms-api-prod**: **`KDMS_REGISTRATION_URL`** (`terraform/main.tf`)
- Consumer: **`api/staffOcrExtract.php`** (`getenv('KDMS_REGISTRATION_URL')`)

After `terraform apply`, verify env on live `kdms-api-prod` revision.

## 4. `KDMS_OCR_BASE_URL` removed

Phase 6/7 removes **`KDMS_OCR_BASE_URL`** from `terraform/locals.tf` (no longer injected into kdms-prod / kdms-api-prod). `enable_ocr_service = false` destroys **kdms-ocr-prod**.

Legacy `assets/js/ocr_reader/` remains in tree but nav/OCRReaderView are retired; do not re-enable OCR URL env.
