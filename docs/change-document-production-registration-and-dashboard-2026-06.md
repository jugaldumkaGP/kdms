# Change Document: Production Registration & Dashboard Fixes

**Project:** KDMS (Kainchi Dham Management System)  
**Environment:** Production (`mysql-kdms-prod` / `kdms_prod`, Cloud Run `kdms-prod`, `kdms-api-prod`, `kdms-registration-prod`)  
**Event context:** `2026JB`  
**Document date:** June 2026  
**Status:** Application changes in repository; some database steps are operator-run (not applied by deploy alone).

---

## 1. Executive summary

Staff and visitors using the **day-visitor registration PWA** (`kdms-registration-prod`) encountered registration failures and, after fixes allowed registration to succeed, **dashboard, kitchen, and search metrics** did not reflect expectations.

Root causes fell into three categories:

1. **Production database configuration** — missing MySQL definer user, outdated stored procedure bodies (table name casing), and restricted grants for `kdms_reg`.
2. **Legacy dashboard/reporting logic** — accommodation grid filters, mislabeled seva metric, wrong drill-down keys (`OWNAR` vs `othr`), and kitchen counts not tied to `print_log`.
3. **Registration service behaviour** — accommodation availability not updated when `kdms_reg` lacked `UPDATE` on `accommodation_availability`.

This document lists each reported issue, evidence from logs, changes made in **application code**, and **manual database** steps required in production.

---

## 2. Infrastructure context

| Item | Production value |
|------|------------------|
| Cloud SQL instance | `mysql-kdms-prod` |
| Schema | `kdms_prod` |
| App DB user | `kdms_user` |
| Registration DB user | `kdms_reg` |
| Definer on legacy procedures | `kdms`@`%` |
| Pre-prod (historical) | `mysql-skm-prod` / `kdms` / user `kdms` |

Production Cloud Run was switched from the staging database to `mysql-kdms-prod` per Stream C switchover (`docs/stream-c-db-switchover.md`). Behaviour that “worked in pre-prod” often reflected a **different database instance**, not identical procedure/grant state.

---

## 3. Issue A — Registration PWA: “Registration could not be verified”

### 3.1 Symptom

Users saw:

> Registration could not be verified. Please try again or ask for help at the counter.

### 3.2 Application behaviour

- PWA (`Services/kdms-registration`) calls `kdms-api` → `POST /api/deduplicateDevotee.php` before saving the devotee row.
- On dedup failure, `RegistrationService` returns the generic verification error (fail-closed).

**Code reference:** `Services/kdms-registration/includes/RegistrationService.php`, `KdmsApiClient::deduplicate()`.

### 3.3 Root causes (production logs)

| Phase | Time (IST, approx.) | API error |
|-------|---------------------|-----------|
| 1 | 28–29 May | `1449` — definer `'kdms'@'%'` does not exist |
| 2 | After creating `kdms` user | `1146` — `Table 'kdms_prod.Accommodation_Master' doesn't exist` |
| 3 | After procedure fix | Registration `POST /api/register` → **200** |

### 3.4 Resolution

**Database (operator, not deployed via Cloud Run):**

1. Create MySQL user `kdms`@`%` with privileges on `kdms_prod` (definer for stored procedures).
2. Recreate refresh/upsert procedures with **lowercase** table names using `api/config/DB Files/PROC_OPTIONS_CASE_SENSITIVE_TABLES.sql` (must run via **mysql CLI** — not Cloud SQL Studio; `DELIMITER` / `PREPARE` limitations documented in script header).

**Application code:** No change required for Issue A beyond existing registration flow; failures were database-side.

**Helper added:** `api/config/DB Files/apply_proc_options_case_sensitive.sh` — runs procedure script through Cloud SQL Auth Proxy.

---

## 4. Issue B — Accommodation availability not updating on PWA registration

### 4.1 Symptom

After successful registration, **accommodation allocated counts** on the dashboard did not increase until staff manually edited accommodation or ran “Refresh Accommodation Counts”.

### 4.2 Root cause (logs)

Every successful registration logged:

```text
[WARNING] accommodation_availability update skipped
SQLSTATE[42000]: ... 1142 UPDATE command denied to user 'kdms_reg'@'...' for table 'accommodation_availability'
```

`AccommodationAssigner::assignOther()` inserts `devotee_accomodation` and then increments `accommodation_availability`; the `UPDATE` failed silently (warning only).

### 4.3 Resolution

**Database (operator):**

```sql
USE kdms_prod;

GRANT SELECT, UPDATE ON kdms_prod.accommodation_availability TO 'kdms_reg'@'%';

FLUSH PRIVILEGES;
```

**Repository files:**

| File | Change |
|------|--------|
| `scripts/grant_kdms_reg_accommodation_availability.sql` | **New** — production grant script |
| `scripts/create_registration_db_user.sql` | **Updated** — include `accommodation_availability` grant for new installs |

**Application code:** No logic change; existing `AccommodationAssigner` is correct once grants exist.

---

## 5. Issue C — Dashboard: wrong “Own Arrangement” count and empty search

### 5.1 Symptoms

- **“Devotees with Own Arrangement”** showed a non-zero count (e.g. 4) but the link  
  `devoteeSearchResult.php?mode=CUS&key=devotee_accommodation_key=OWNAR` showed **no devotees**.
- Day visitors are assigned accommodation key **`othr`**, not `OWNAR`.

### 5.2 Root causes

1. **Drill-down key mismatch** — PWA assigns `othr` (`AccommodationAssigner::DAY_VISITOR_ACCOM_KEY`); dashboard linked to `OWNAR`.
2. **Count logic** — `DevoteesWithOwnArrangements` used `SUM(allocated_count) WHERE available_count > 1000` (legacy sentinel), not a devotee count for specific keys.

### 5.3 Application changes

| File | Change |
|------|--------|
| `api/Interface/clsReport.php` | **Own arrangement:** `COUNT(DISTINCT Devotee_Key)` where `Accomodation_Key IN ('LCL','OWNAR')` and status `Allocated`. |
| `api/Interface/clsReport.php` | **New metric:** `TotalDayVisitors` — `COUNT(DISTINCT Devotee_Key)` where `LOWER(Accomodation_Key) = 'othr'`. |
| `UI/dashboard.php` | New row **“Total Day Visitors”** → link `devotee_accommodation_key=othr`. |
| `UI/dashboard.php` | **Own Arrangement** link → `devotee_accommodation_key=LCL\|OWNAR`. |
| `api/Interface/devotees.php` | Custom search supports **pipe-separated** accommodation keys (`LCL\|OWNAR` → `IN (...)`). |

---

## 6. Issue D — “OTHR” missing from accommodation grid

### 6.1 Symptom

**Other** / day-visitor accommodation (`othr`) did not appear on the main dashboard accommodation table.

### 6.2 Root cause

`clsReport::getAccommodationCounts()` filtered with `aa.Available_Count <= 1000`. Special accommodations (including `othr`) often use `available_count > 1000` as a legacy sentinel and were excluded from the grid.

### 6.3 Application changes

| File | Change |
|------|--------|
| `api/Interface/clsReport.php` | Removed `Available_Count <= 1000` filter for grid modes; **All** uses `1=1`; **Available** / **Reserved** / **Occupied** use sensible predicates without the sentinel cap. |

---

## 7. Issue E — “Devotees Registered for Seva” showed 4 incorrectly

### 7.1 Symptom

Label read “Devotees Registered for Seva” but displayed **4** when only day-visitor PWA registrations existed (no seva assignment).

### 7.2 Root cause

Dashboard used `RegisteredDevoteesIncludingLocals` = **sum of all `Allocated_Count`** on `accommodation_availability` for the event — not seva registrations.

### 7.3 Application changes

| File | Change |
|------|--------|
| `api/Interface/clsReport.php` | Query index `[1]`: `COUNT(DISTINCT Devotee_Key)` from `devotee_seva` where `Seva_Status = 'Assigned'`, `Seva_ID <> 'UN'`, filtered by `Seva_Event`. |
| `api/Interface/clsReport.php` | Event filter loop: seva query uses `ds.Seva_Event` (not `acco.Accommodation_Event`). |
| `UI/dashboard.php` | Display field `DevoteesRegisteredForSeva` instead of `RegisteredDevoteesIncludingLocals`. |

---

## 8. Issue F — Kitchen count not matching expectations

### 8.1 Symptoms

- Kitchen showed a count (e.g. **1**) while **none** of the four PWA devotees had cards physically printed.
- One registration was a **duplicate merged** into an existing devotee.

### 8.2 Investigation findings

| Topic | Finding |
|-------|---------|
| **Previous “residents” metric** | Counted **allocated** accommodation, not `print_log`. Any existing allocated resident could show ≥1 independent of PWA. |
| **PWA `addToPrintQueue`** | Writes **`card_print_log`** only (queued). Does **not** insert **`print_log`**. |
| **`print_log`** | Written when cards are **actually printed** (`removeFromPrintQueue` with **`eventId`**). Append-only: never deleted on devotee removal. |
| **`print_log` idempotency** | At most one row per **devotee + event + calendar day** (`includes/PrintLog.php`); re-printing the same day does not add duplicate rows. |
| **`card_print_archive`** | Still trimmed to 25 rows on print complete; **`print_log` is not trimmed**. |
| **Merged duplicate** | Does **not** increment kitchen counts until the **survivor** has a `print_log` row (merge repoints `print_log`, does not delete). |

### 8.3 Decision (product)

- **Residents:** Count devotees with **`print_log` for the event** (any print date), excluding day visitors (status `D`, type `T`).
- **Day visitors:** Count D/T devotees with **`print_log` for the event on the current calendar day only**.
- **Queued-only** registrations do not count until printed.

### 8.4 Application changes

| File | Change |
|------|--------|
| `includes/PrintLog.php` | **New.** Idempotent `INSERT` per devotee + event + day; used from `manageCardPrinting` on `removeFromPrintQueue`. |
| `api/Interface/devotees.php` | Uses `PrintLog` instead of bulk `INSERT`; removed `DELETE FROM print_log` on full devotee delete. |
| `api/Interface/clsKitchenDashboard.php` | Rewritten queries to use `print_log` per above; renamed metric `Residents_Printed_For_Event`. |
| `UI/kitchenDashboard.php` | Updated labels and explanatory note. |
| `UI/getKitchenCounts.php` | JSON field maps to `Residents_Printed_For_Event`. |
| `api/config/DB Files/Phase_kitchen_print_log_idempotent.sql` | **Optional.** Unique index on `(Devotee_Key, Event_Id, Print_Day)` after deduping legacy duplicates. |

### 8.5 Future improvements (not implemented)

- Separate metric for **queued** day visitors (`card_print_log` status `A`).
- Policy decision: insert `print_log` on registration if “registered = meal eligible”.
- UI hint when queue non-empty but `print_log` empty for D/T.

---

## 9. Database procedure script (operator reference)

**File:** `api/config/DB Files/PROC_OPTIONS_CASE_SENSITIVE_TABLES.sql`

- Recreates procedures from **test environment** logic with **only table names** lowercased for Linux Cloud SQL.
- Includes `DEFINER=\`kdms\`@\`%\``.
- Procedures: `PROC_REFRESH_ACCO_COUNT_W_EVENT`, `PROC_UPSERT_ACCO_W_EVENT`, `PROC_REFRESH_AMENITIES_COUNT`, `PROC_UPSERT_AMENITY`, `PROC_REFRESH_SEVA_COUNT_I`, `PROC_UPSERT_SEVA_W_AVAIL_UPDATE_I`, `PROC_UPSERT_EVENT`.

**Apply via mysql CLI** (example):

```bash
cloud-sql-proxy project-12f4b54b-d692-4583-83b:asia-south1:mysql-kdms-prod --port 3307
mysql -h 127.0.0.1 -P 3307 -u root -p kdms_prod < "api/config/DB Files/PROC_OPTIONS_CASE_SENSITIVE_TABLES.sql"
```

Cloud SQL Studio cannot run this file (`DELIMITER` / error 1295 with `PREPARE`).

---

## 10. Complete list of repository file changes

| File | Type | Summary |
|------|------|---------|
| `api/Interface/clsKitchenDashboard.php` | Modified | Kitchen counts from `print_log`; residents = event prints (excl. D/T); day visitors = today only |
| `UI/kitchenDashboard.php` | Modified | Labels and help text |
| `UI/getKitchenCounts.php` | Modified | API response field name |
| `api/Interface/clsReport.php` | Modified | Grid filter; seva count; day visitors; own arrangement counts; event filter for seva |
| `UI/dashboard.php` | Modified | Total Day Visitors row; fixed links; seva metric field |
| `api/Interface/devotees.php` | Modified | Pipe-separated accommodation keys; idempotent append-only `print_log` via `PrintLog` |
| `includes/PrintLog.php` | **New** | Idempotent, append-only `print_log` writes |
| `api/config/DB Files/Phase_kitchen_print_log_idempotent.sql` | **New** | Optional DB unique key for print_log |
| `scripts/grant_kdms_reg_accommodation_availability.sql` | **New** | Production grant for `kdms_reg` |
| `scripts/create_registration_db_user.sql` | Modified | Grant template for new environments |
| `api/config/DB Files/PROC_OPTIONS_CASE_SENSITIVE_TABLES.sql` | Modified | Test-env logic, lowercase tables, `DELIMITER` format for mysql CLI |
| `api/config/DB Files/apply_proc_options_case_sensitive.sh` | **New** | Helper to apply procedure script via proxy |
| `scripts/deploy-kdms-latest.sh` | **New** | One-command deploy: resolve latest GAR digests + `terraform apply` |
| `.github/workflows/push-gar.yml` | Modified | Removed `pin-tfvars` job (digests resolved at deploy time by script) |
| `Services/kdms-registration/pwa/index.html` | Modified | Header/title: “2026 June Bhandara Registration” |

**Not changed (by design):** `RegistrationService.php`, `AccommodationAssigner.php` (logic already correct; DB grants required).

---

## 11. Deployment checklist

### 11.1 Database (once per production instance)

- [ ] `kdms`@`%` user exists with adequate privileges on `kdms_prod`
- [ ] `PROC_OPTIONS_CASE_SENSITIVE_TABLES.sql` applied via mysql CLI
- [ ] `GRANT SELECT, UPDATE ON kdms_prod.accommodation_availability TO 'kdms_reg'@'%'`
- [ ] Optional: run **Refresh Accommodation Counts** on dashboard to reconcile counts after grants

### 11.2 Application deploy

After code is merged to **`main`** and CI has built/pushed images to Artifact Registry, deploy all Cloud Run services from the **kdms repo root**:

```bash
./scripts/deploy-kdms-latest.sh --pull --yes
```

**What the script does**

1. Optionally **`git pull origin main`** (`--pull`)
2. Resolves the newest image **digests** from Artifact Registry (`branch-main`, or local **`git` HEAD** short SHA when that tag exists)
3. Updates **`terraform/terraform.tfvars`** and runs **`terraform plan`** + **`apply`**

**Useful flags:** `--plan-only` (preview only), `--wait` (poll until Cloud Run revisions are ready), `--rolling` (always use `branch-main`, skip HEAD SHA). See `./scripts/deploy-kdms-latest.sh --help`.

**Prerequisites:** `gcloud` (authenticated to `project-12f4b54b-d692-4583-83b`), `terraform`, and CI images already in GAR.

**Services rolled out** (per `terraform.tfvars`): **`kdms-prod`**, **`kdms-api-prod`**, **`kdms-registration-prod`**, and **`kdms-reports-prod`** when enabled. This replaces the former manual flow (`git pull` → edit/wait for CI-pinned tfvars → `terraform plan` → `terraform apply`).

**Note:** CI still **builds and pushes** images on push to `main`; it no longer commits digest pins to `terraform.tfvars`. Digests are resolved at deploy time by the script.

Confirm which Cloud Run service hosts `api/Interface/*.php` vs `UI/*.php` for your build; both main/API images typically include the full tree.

### 11.3 Verification

- [ ] PWA registration succeeds end-to-end
- [ ] `accommodation_availability` increments for `othr` without manual refresh (check logs: no `update skipped`)
- [ ] Dashboard grid shows `othr`
- [ ] **Total Day Visitors** and drill-down list `othr` devotees
- [ ] **Own Arrangement** lists `LCL` / `OWNAR` only
- [ ] **Seva** count matches `devotee_seva` (excl. `UN`)
- [ ] Kitchen: residents increase only after `print_log` entries; day visitors only when printed **today**

---

## 12. Related behaviour (discussion only — no code change)

### 12.1 Photo and ID on duplicate merge (PWA)

When dedup returns **merged**:

1. **API dedup** (`DeduplicationService`) repoints/consolidates `devotee_photo` / `devotee_id` onto the survivor; prefers incoming ID scan GCS when applicable; deletes duplicate devotee row.
2. **Registration** then `attachChildRows()` for survivor; GCS paths must match `devotee/{SURVIVOR_KEY}/id.jpg|photo.jpg` or they are skipped (`RegistrationGcs::isAllowedPath`).
3. If survivor key ≠ reserved candidate key, uploads under candidate paths may rely entirely on dedup merge; orphan GCS under candidate key is possible.

### 12.2 Pre-prod vs production

Same application images can behave differently when pointed at `mysql-skm-prod` vs `mysql-kdms-prod` due to procedure definers, table name casing, grants, and accommodation sentinel values.

---

## 13. References

- `docs/stream-c-db-switchover.md` — production DB switchover
- `docs/phase6-operational-findings.md` — staging vs production notes
- `Services/kdms-registration/includes/AccommodationAssigner.php` — day visitor key `othr`
- `includes/DeduplicationService.php` — merge and `PROC_REFRESH_*` after merge
- Production logs: GCP Cloud Logging, services `kdms-registration-prod`, `kdms-api-prod`, project `project-12f4b54b-d692-4583-83b`

---
Deploy Latest images:

cd /Applications/XAMPP/xamppfiles/htdocs/kdms

./scripts/deploy-kdms-latest.sh --pull --yes

*End of document*
