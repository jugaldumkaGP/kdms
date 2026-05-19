# KDMS Day-Visitor Registration (v3) — Implementation Tracker

**Architecture reference:** KDMS Day-Visitor Registration — v3 Architecture (design agent document)  
**Last updated:** 2026-05-18  
**Status:** Not started — awaiting Phase 1a implementation kickoff

This document tracks **implementation progress** against v3. Phases are **delivery/order controls**, not separate production launches unless noted below.

---

## Production release model (agreed)

| Bundle | Phases | When to production |
|--------|--------|-------------------|
| **Early** | **1a** | Can deploy independently (dual-read, schema, dashboard bug fix — low risk) |
| **Main launch** | **1.5 + 2 + 3** | **Single production release** — PWA, dedup, print queue nav, kitchen view ship together |
| **Pre-event hardening** | **4** | Apply Terraform (LB + Armor) before QR goes public; `terraform destroy` after event |
| **Post-event** | **6/7, 7b, 8** | GCS cutover, OCR retirement, batch dedup, optional photo similarity |

**Phase 1.5 does not require its own production launch.** Use **dev/staging checkpoints** to validate Document AI, PWA, and GCS writes while Phase 2 is built. Production gate = **1.5 + 2 + 3 complete and verified together**.

---

## Global conventions (do not drift)

- PascalCase columns; table `devotee`; PK `Devotee_Key` VARCHAR(10)
- No `day_visitor_queue` — extend `card_print_log` / TMP search mode
- No `/api/internal/*` — `api/*.php` + `X-KDMS-SERVICE-KEY` (Secret Manager, rotate per event)
- Dedup via HTTP: `kdms-registration` → `kdms-api/api/deduplicateDevotee.php`
- Day visitor: `Devotee_Status='D'`, `Devotee_Type='T'`, `Devotee_ID_Type` = actual ID type
- PWA photos: GCS only (no new BLOBs); kdms-api serves base64 for print via `getPhoto.php` / `getIdImage.php`
- Retire `kdms-ocr` after Phase 1.5 stable (Phase 6/7)
- QR URL: **static** — no `eventId` query param

---

## Pre-implementation gaps to resolve in design (minor)

These do not block Phase 1a but should be fixed in v3.1 or during build:

| # | Gap | Recommendation |
|---|-----|----------------|
| 1 | **`api/addToPrintQueue.php`** not in repo today | Add thin wrapper endpoint **or** call existing `api/upsertDevotee.php` with `requestType=addToPrintQueue` from registration service (document chosen approach in Phase 1.5) |
| 2 | **GCS ID path inconsistency** | OCR staging: `id-photos/{date}/{uuid}.jpg` before `Devotee_Key` exists; after register: `devotee/{Devotee_Key}/id.jpg`. Document copy/rename on submit |
| 3 | **Kitchen count vs archive trim** | `card_print_archive` is trimmed to ~25 rows globally — **do not rely on it alone** for “printed today.” Primary source: **`print_log`** (`Print_Date_Time = CURDATE()`, `Devotee_Status='D'`, `Devotee_Type='T'`). Use archive only as secondary |
| 4 | **`devotee_remarks.remark`** is VARCHAR(250) | Long dedup audit may need **multiple rows** (`remark_type='dedup'`, suffix in type or split chunks) — same limit as `Comments` |
| 5 | **Diagram: kdms-api “internal ingress”** | Today all Run services are public (`INGRESS_TRAFFIC_ALL`). Treat as **future** hardening; not Phase 1–4 scope |
| 6 | **Dedup rule clarity** | Add explicit: **same `Devotee_ID_Type` + different `Devotee_ID_Number` → never auto-merge** (new record) |
| 7 | **Partial ID / last-4** | Not defined in v3 — decide before Phase 2 (recommend: no auto-merge on partial match) |
| 8 | **Phase table typo** | Design doc §10 has duplicate header row — cosmetic |
| 9 | **Security checklist** | Add to Phase 1.5: CSRF token, consent text, PII-free logging, ID image retention days, rate limit 30/min + ban |
| 10 | **`devotee_remarks` INSERT column names** | Use actual columns: `remark`, `remark_update_date_time`, `remark_updated_by` (not `Remarks` / `Remark_Date_Time`) |

---

## Phase 1a — GCS foundation + schema + dual-read

**Goal:** Infrastructure for photos and merge metadata; fix dashboard day-visitor count bug. Safe to deploy to production before PWA.

**Status:** Code complete — operator deploy: `docs/phase1a-deploy-steps.md`; validation: `docs/validation_report_phase1a.md`. **DB (all-in-one):** `api/config/DB Files/Phase_1a_production_complete.sql`.

**Decisions:** Day-visitor dashboard = `D` + `T` + accommodation **Other**; single bucket `kdms-photos`; unique index in migration; `run-kdms-registration` SA in Terraform; no legacy status migration.

### Schema / infra

- [ ] GCS bucket `kdms-photos` (region aligned with Cloud SQL, e.g. `asia-south1`)
- [ ] IAM: kdms-api SA + future kdms-registration SA (`objectCreator` / `objectViewer`)
- [ ] `ALTER TABLE devotee_photo ADD Devotee_Photo_Gcs_Path VARCHAR(512) NULL`
- [ ] `ALTER TABLE devotee_id ADD Devotee_ID_Image_Gcs_Path VARCHAR(512) NULL`
- [ ] `CREATE TABLE devotee_aliases` (per v3 DDL)
- [ ] `CREATE TABLE devotee_merge_archive` (per v3 DDL)
- [ ] `CREATE UNIQUE INDEX idx_devotee_id_type_number ON devotee (Devotee_ID_Type, Devotee_ID_Number)` — document NULL caveat; handle duplicate-key in registration path

### Application

- [x] kdms-api dual-read: `api/getPhoto.php`, `api/getIdImage.php`, `includes/PhotoStorage.php` (existing `devotees.php` unchanged in 1a)
- [x] Migration script: `scripts/migrate_photos_to_gcs.php` (dry-run only in 1a)
- [x] **Bug fix:** `clsDashboard.php` — `D` + `T` + `Accomodation_Name = 'Other'`
- [x] Removed `Devotee_ID_Type = 'Temporary'` filter from day-visitor count
- [x] Legacy status migration skipped (no legacy day visitors expected)

### Verification

- [ ] Existing devotee photo loads (BLOB fallback unchanged)
- [ ] Dashboard day-visitor count includes `Devotee_Status='D'` records
- [ ] `information_schema` table size baseline recorded for Phase 6/7

**Files (expected touch):**

- `api/config/DB Files/` — new migration SQL
- `api/Interface/devotees.php`, `api/Interface/Image.php`, `api/Interface/clsDashboard.php`
- `terraform/` — bucket + IAM (if in scope for Phase 1a)

---

## Phase 1.5 — kdms-registration + Document AI + PWA

**Goal:** Public registration path. **Validate on dev/staging only** until Phase 2 merged in same release.

### Service

- [ ] PWA registration assigns `devotee_accomodation` → accommodation **Other** (required for dashboard count)
- [ ] `Services/kdms-registration/` (or equivalent) — PHP Cloud Run, public ingress, separate SA (`run-kdms-registration` SA exists from Phase 1a Terraform)
- [ ] Terraform: Cloud Run service, secrets, env (`KDMS_API_BASE_URL`, `ACTIVE_EVENT_ID`, Document AI processor ID)
- [ ] `POST /api/register`, `POST /api/ocr-extract`, `GET /api/health`
- [ ] `GET /api/selfie-url` — signed GCS upload URL (optional selfie)
- [ ] PWA: scan ID + manual entry; confidence UI (green/yellow/blank)
- [ ] GCS: write ID image **before** OCR; devotee photos GCS-only on register
- [ ] ID normalization helpers (Aadhaar, PAN, Voter ID, Passport)
- [ ] On register: set `D` / `T` / actual `Devotee_ID_Type`; `generateId()` equivalent
- [ ] Call kdms-api print queue (wrapper TBD — see gap #1)
- [ ] CSRF, consent, rate limiting (30/min)

### Staging verification (before prod bundle)

- [ ] QR opens PWA on phone
- [ ] Clear + blurry Aadhaar / Voter ID / Passport — graceful degradation
- [ ] Manual path without OCR
- [ ] Entry appears in TMP queue (`devoteeSearchResult.php?mode=SET&key=TMP`)
- [ ] GCS objects present under expected paths

**Retire (plan only):** `kdms-ocr` — do not remove until Phase 6/7

---

## Phase 2 — Deduplication + merge

**Goal:** Real merge replaces stub `mergeDevoteeRecords()`. **Required for main production launch with 1.5.**

### Deliverables

- [ ] `includes/DeduplicationService.php`
- [ ] `api/deduplicateDevotee.php` — `X-KDMS-SERVICE-KEY`
- [ ] Rules: definite merge = same type + normalized number; never merge different type+number; fuzzy ≥80 with caution; when in doubt → new + `auto_fuzzy_review` alias
- [ ] Merge flow: archive JSON → migrate child FKs → `devotee_aliases` → hard delete TBM
- [ ] Child tables: `devotee_accomodation`, `devotee_seva`, `devotee_photo`, `devotee_id`, `devotee_remarks`, `card_print_log`, `print_log`
- [ ] `Comments` + `devotee_remarks` (`remark_type='dedup'`, `remark_event=ACTIVE_EVENT_ID`)
- [ ] kdms-registration: dedup **before** `addToPrintQueue` (survivor `Devotee_Key`)
- [ ] Admin UI: duplicate hints when editing devotee (optional but in v3)

### Verification

- [ ] Same Aadhaar → merge, one TMP entry, alias + archive rows
- [ ] Different ID numbers → two records
- [ ] Fuzzy below threshold → new record + review alias
- [ ] Unit/integration tests per v3 spec

---

## Phase 3 — KDMS UI integration

**Goal:** Staff workflows for print + kitchen. Ship with 1.5+2.

### Deliverables

- [ ] `UI/nav.php`: rename/replace **“Temporary Cards for Printing”** → **“Day Visitor Print Queue”** → `?mode=SET&key=TMP`
- [ ] Confirm `rptCardsPrint.php` branch for `status=D`, `type=T`
- [ ] Kitchen page/widget (logged-in): residents + day visitors + total; refresh ~5 min
- [ ] Kitchen SQL: residents = `clsDashboard` ashram logic; day visitors = **`print_log` today** (see gap #3)
- [ ] End-to-end: PWA register → TMP → print → leaves queue → kitchen count increments

---

## Phase 4 — Cloud Armor + Load Balancer

**Goal:** WAF + rate limit in front of **kdms-registration only**.

- [ ] Terraform: HTTPS LB, Armor policy (SQLi, XSS, 30 req/IP/min, geo optional India)
- [ ] Apply before public QR; `terraform destroy` after event
- [ ] Verify 429 on burst; PWA still works through LB URL

---

## Phase 6/7 — GCS cutover + OCR retirement

- [ ] Complete BLOB → GCS migration; verify; NULL LONGBLOBs; `OPTIMIZE TABLE`
- [ ] kdms-api: GCS primary, BLOB fallback removed when safe
- [ ] Remove `kdms-ocr` from Terraform, compose, CI
- [ ] Remove or deprecate `UI/nav.php` “KDMS OCR” link
- [ ] Research `temporary_registration` — decommission if unused

---

## Phase 7b — Batch dedup CLI

- [ ] `batch_deduplicate.php` — dry-run / live, same `DeduplicationService`
- [ ] Progress log; run after Phase 2 in production

---

## Phase 8 — Photo similarity (optional)

- [ ] Face embedding + cosine > 0.85 (+20 score) — only if time permits
- [ ] Not blocking go-live

---

## Key endpoints & routes (reference)

| Consumer | Endpoint / route | Notes |
|----------|------------------|-------|
| PWA | `kdms-registration` `/api/register`, `/api/ocr-extract` | Public |
| Registration → API | `kdms-api/api/deduplicateDevotee.php` | Service key |
| Registration → API | Print queue (TBD: `addToPrintQueue.php` or `upsertDevotee.php`) | Service key |
| Staff UI | `devoteeSearchResult.php?mode=SET&key=TMP` | Day visitor print queue |
| Staff UI | `rptCardsPrint.php` | Existing print flow |
| Staff UI | Kitchen view (new) | Session auth |
| Print | `api/getPhoto.php`, `api/getIdImage.php` | Base64 for cards |

---

## Canonical devotee row (PWA)

| Column | Value |
|--------|--------|
| `Devotee_Status` | `'D'` |
| `Devotee_Type` | `'T'` |
| `Devotee_ID_Type` | From OCR/manual (e.g. `Aadhaar`) |
| `Devotee_Key` | `P` + YYMMDD + random (existing algorithm) |

---

## Changelog (this tracker)

| Date | Change |
|------|--------|
| 2026-05-18 | Initial tracker from v3 design review |
