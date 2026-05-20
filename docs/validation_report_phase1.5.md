# Phase 1.5 validation report — kdms-registration

**Date:** _fill after staging deploy_  
**Status:** Template — complete after deploy and manual tests  
**Production gate:** ⚠️ **NOT production-ready** until Phase 2 (DeduplicationService) is implemented and **Phase 1.5 + 2 + 3** validation passes together.

---

## 1. Cloud Run service URL

| Item | Value |
|------|--------|
| Service | `kdms-registration-prod` |
| Region | `asia-south1` |
| URL | _e.g. https://kdms-registration-prod-684080887473.asia-south1.run.app_ |

```bash
gcloud run services describe kdms-registration-prod \
  --region=asia-south1 \
  --project=project-12f4b54b-d692-4583-83b \
  --format='value(status.url)'
```

---

## 2. Terraform plan checks

```bash
cd terraform && terraform plan -out=/tmp/plan.out
```

Confirm in plan/output:

- [ ] `location = "asia-south1"` for `google_cloud_run_v2_service.kdms_registration`
- [ ] `ingress = "INGRESS_TRAFFIC_ALL"`
- [ ] `min_instance_count = 0`
- [ ] Service account `run-kdms-registration@...`
- [ ] No `us-central1` in new resources / `gcs.tf`

---

## 3. Document AI

| Item | Value |
|------|--------|
| Processor type | ID_DOCUMENT_PROCESSOR (Identity Document Parser) |
| Location | _us / eu_ |
| Resource name | _projects/.../processors/..._ |
| Secret id | `document-ai-processor-id` |

Setup: `docs/document-ai-setup.md`

---

## 4. Test A — Manual registration (minimum fields)

- [ ] Submit First Name, Last Name, ID Type, ID Number from phone browser
- [ ] Response `{ "success": true, "Devotee_Key": "P..." }`
- [ ] DB: `Devotee_Status='D'`, `Devotee_Type='T'`
- [ ] `devotee_accomodation` row for `Accomodation_Key='othr'` and active event
- [ ] `card_print_log` row with `Print_Status='A'`

---

## 5. Test B — ID scan (clear Aadhaar)

- [ ] Green/yellow borders per confidence thresholds
- [ ] `id_staging_gcs_path` in OCR JSON
- [ ] Object in GCS under `id-staging/YYYY-MM-DD/`

---

## 6. Test C — Blurry / angled ID

- [ ] HTTP 200 with null/low-confidence fields (no error page)
- [ ] Staging image still in GCS

---

## 7. Test D — Passport scan

- [ ] Name, ID number, DOB extracted where visible

---

## 8. Test E — Selfie

- [ ] Thumbnail after upload
- [ ] GCS path `devotee-selfies/YYYY-MM-DD/*.jpg`

---

## 9. Test F — Rate limit

- [ ] 35 rapid POSTs → HTTP 429 after 30 in 60s

---

## 10. Test G — Idempotency

- [ ] Same ID type + number within 60s → same `Devotee_Key`, no duplicate `devotee` row

---

## 11. Test H — Dedup stub

- [ ] Registration succeeds when dedup returns stub / 404
- [ ] Warning in Cloud Run logs

---

## 12. Test I — Mock Document AI

- [ ] `DOCUMENT_AI_PROCESSOR_ID=mock` → empty OCR fields, form usable
- [ ] ID image still stored when upload succeeds

---

## 13. ACTIVE_EVENT_ID not exposed

- [ ] Not in API JSON to PWA
- [ ] Not in HTML source / comments

---

## 14. Restricted DB user

- [ ] `kdms_reg` cannot `DROP` / `ALTER` (manual negative test)
- [ ] Grants match `scripts/create_registration_db_user.sql`

---

## 15. QR poster

| Item | Value |
|------|--------|
| File | `docs/qr-poster/registration-poster.html` |
| Scan test | _Pass / fail — distance ~50cm_ |

---

## Deploy checklist (operator)

1. Run `scripts/create_registration_db_user.sql` on Cloud SQL (`kdms` database).
2. Create secrets: `kdms-reg-db-password`, `document-ai-processor-id`.
3. CI: push image `kdms-registration` to Artifact Registry.
4. Set `enable_registration_service = true` in `terraform.tfvars`, apply.
5. Hit `GET /api/health` on registration URL.

---

## Sign-off

| Role | Name | Date |
|------|------|------|
| Dev | | |
| Operator | | |

**Reminder:** Public QR / production traffic only after Phase 2 dedup is live and validated.
