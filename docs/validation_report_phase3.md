# Phase 3 validation report — staff UI (print queue, photos, kitchen)

**Date:** 2026-05-20  
**Scope:** Day-visitor print queue nav, GCS photos on search/cards, kitchen dashboard, `KD-KITCHEN`, TMP redirect fix.

---

## 1. Day-visitor card photo (was “intentionally omitted”)

**Previous behaviour:** For `Devotee_Status = 'D'` and `Devotee_Type = 'T'`, `rptCardsPrint.php` and `rptCardsPrintTemp.php` rendered name and prasad/accommodation text only — no thumbnail. Comment in code: “intentionally omitted”. That was a **layout choice** for a compact prasad card, not a data rule. It is **wrong for security verification** at gates.

**Fix:** Day-visitor branches now include Reg No., station/date where applicable, and a **photo** (same helper as resident cards). Print data still comes from PCD / `getDevoteeDetailsForPrint`, which now uses `PhotoStorage::legacyBase64Photo()` so **GCS-only** rows print correctly.

**Verify:**

- [ ] TMP queue → print temp card → photo visible for GCS-only devotee
- [ ] CTP/resident path unchanged
- [ ] Devesh referral special layout still shows photo

---

## 2. `KD-KITCHEN` permission

| Item | Detail |
|------|--------|
| Page map | `kitchenDashboard.php`, `getKitchenCounts.php` → `KD-KITCHEN` |
| Grant script | `scripts/mysql_grant_kdms_page_ids.sql` — `asset_list` + optional STEP 3 union |
| Kitchen-only roles | Grant **only** `KD-KITCHEN` (do not rely on `KD-DSBRD`) |

Example (kitchen staff role):

```sql
INSERT IGNORE INTO user_access (user_role_key, asset_key, access_value, access_updated_by, access_update_date_time)
VALUES ('KITCHEN', 'KD-KITCHEN', 'ALL', 'admin', NOW());
```

---

## 3. Kitchen counts (meals — no own-arrangement / local exclusion)

| Metric | Meaning |
|--------|---------|
| **Allocated devotees (event)** | Distinct **Allocated** devotees for the event (ashram, own arrangement, local) **excluding** day visitors (`Devotee_Status = 'D'` and `Devotee_Type = 'T'`). |
| **Day visitors printed today** | Distinct `print_log` rows **today** joined to `devotee` with `D` + `T`. |
| **Total for kitchen** | Sum of the two above — day visitors are **not** included in the allocated count, so no double-count. |

Kitchen does **not** split out own-arrangement vs local (that breakdown remains on the registration dashboard only, via `clsDashboard`).

---

## 4. `searchDevotee` / grid thumbnails (GCS)

`searchDevotee()` (SET modes: TMP, CTP, RPC, CUS, etc.) now hydrates `Devotee_Photo` and `Devotee_ID_Image` via `PhotoStorage` when the JOIN BLOB is empty. **API shape unchanged** (still base64 in JSON). Search screen should show GCS photos without client changes.

---

## 5. Nav ACL — **Option A** (confirmed)

Without `KD-DVT-SCR`, the sidebar hides: Search Devotees, with/without photo, CTP, **Day Visitor Print Queue** (TMP), RPC, KDMS OCR.

**Kitchen Dashboard** nav requires `KD-KITCHEN` only.

Dashboard, Registration, and Register New Devotee remain visible in nav; page ACL still applies on direct URL access.

---

## 6. Other deliverables

| Change | File(s) |
|--------|---------|
| Rename nav TMP label | `UI/nav.php` → “Day Visitor Print Queue” |
| After print/cancel, return to same queue | `UI/devoteeSearchResult.php` — `printQueueReturnKey()` |
| Kitchen UI + 5 min refresh | `UI/kitchenDashboard.php`, `UI/getKitchenCounts.php`, `api/Interface/clsKitchenDashboard.php` |

---

## 7. Deploy and validation

Full operator steps: **`docs/phase2-phase3-deploy-and-validation.md`**

Quick checklist:

- [ ] Run `scripts/mysql_grant_kdms_page_ids.sql` STEP 1–2 on prod if needed; grant `KD-KITCHEN` to kitchen roles
- [ ] Deploy `kdms-prod` + `kdms-api-prod` (+ `kdms-registration-prod` if Phase 2 PWA included)
- [ ] Staff with `KD-DVT-SCR`: TMP → print → returns to TMP; kitchen day-visitor count +1; allocated count unchanged for that devotee
- [ ] GCS photo in search grid and on printed day-visitor card
- [ ] User without `KD-KITCHEN`: nav hidden; direct URL → access denied
- [ ] User with `KD-KITCHEN` only: kitchen works; print queues hidden unless also granted `KD-DVT-SCR`

---

## 8. Not in Phase 3

- `clsDashboard.php` unchanged (registration dashboard metrics)
- `DeduplicationService` / registration PWA unchanged
- Nav does not hide Registration or main Dashboard
