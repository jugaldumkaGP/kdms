# Document AI setup (kdms-registration)

Cloud Run stays in `asia-south1`; processors are created in **`us`** or **`eu`** (~200–400ms extra latency).

> **Note:** There is no `gcloud documentai` subcommand in the standard Google Cloud CLI. Use the **Console**, **REST + curl** (below), or the Python client.

## Which processor for day-visitor ID scan?

Our PWA needs **structured fields** from ID photos (name, ID number, DOB) to pre-fill the form — mostly **Indian** documents (Aadhaar, PAN, Voter ID, Passport, Driving License).

| Console name | Use for KDMS registration? | Why |
|--------------|----------------------------|-----|
| **Utility Parser** | **No** | For utility bills (amounts, line items), not identity cards. |
| **Identity Document Proofing** | **No** (as primary OCR) | Focus is **fraud / validity signals** (“is this ID tampered?”), not reliable extraction of name/number/DOB for form pre-fill. Poor fit for Aadhaar/PAN-heavy traffic. |
| **Custom Extractor** | **Yes** (recommended for production) | You define fields (e.g. `first_name`, `last_name`, `id_number`, `dob`) and train on sample images of your real ID types. Best match for mixed Indian IDs. |
| **Enterprise Document OCR** | **Yes** (good staging / fallback) | Generic OCR (Hindi + English). Returns **text**, not `given_name` entities — requires extra parsing in PHP (not implemented in Phase 1.5 as-shipped). |
| **US Passport / US Driver License** (if shown in gallery) | **Partial** | Only for US-format passport/DL; not for Aadhaar/PAN. |

**Recommendation**

1. **Do not** create Utility Parser or rely on Identity Document Proofing for form pre-fill.
2. **Production (Indian IDs):** create a **Custom Extractor** processor, define your five fields, label ~20–50 samples per document type (or use foundation-model mode with fewer docs — see [custom extractor overview](https://cloud.google.com/document-ai/docs/custom-extractor-overview)).
3. **Staging / quick test:** use **`DOCUMENT_AI_PROCESSOR_ID=mock`** (manual entry) until Custom Extractor is trained; optionally add **Enterprise Document OCR** later with a small parsing layer.
4. After creating Custom Extractor, map its entity names in `Services/kdms-registration/includes/DocumentAiOcr.php` (today it expects types like `given_name`, `document_id` from US-style parsers).

The REST examples below still use `ID_PROOFING_PROCESSOR` only as a **smoke-test** processor type. Replace with your Custom Extractor processor resource name once trained.

## 1. Enable API

```bash
gcloud services enable documentai.googleapis.com \
  --project=project-12f4b54b-d692-4583-83b
```

## 2. List available processor types (optional)

```bash
export PROJECT_ID=project-12f4b54b-d692-4583-83b
export LOCATION=us

curl -s -H "Authorization: Bearer $(gcloud auth print-access-token)" \
  "https://${LOCATION}-documentai.googleapis.com/v1/projects/${PROJECT_ID}/locations/${LOCATION}:fetchProcessorTypes" \
  | python3 -m json.tool
```

Look for `CUSTOM_EXTRACTION_PROCESSOR` / Custom Extractor (production) or `OCR_PROCESSOR` (generic OCR).

## 3. Create processor (REST — smoke test only)

For a quick API test you can create **ID Proofing** (not recommended for production form fill):

```bash
export PROJECT_ID=project-12f4b54b-d692-4583-83b
export LOCATION=us

cat > /tmp/docai-processor.json <<'EOF'
{
  "type": "ID_PROOFING_PROCESSOR",
  "displayName": "KDMS ID Parser"
}
EOF

curl -s -X POST \
  -H "Authorization: Bearer $(gcloud auth print-access-token)" \
  -H "Content-Type: application/json; charset=utf-8" \
  -d @/tmp/docai-processor.json \
  "https://${LOCATION}-documentai.googleapis.com/v1/projects/${PROJECT_ID}/locations/${LOCATION}/processors" \
  | python3 -m json.tool
```

Copy the full **`name`** from the response, e.g.:

`projects/project-12f4b54b-d692-4583-83b/locations/us/processors/abc123def456`

That entire string is what `DOCUMENT_AI_PROCESSOR_ID` / Secret Manager must hold.

### Production: Custom Extractor (Console)

1. Open [Document AI → Processor gallery](https://console.cloud.google.com/ai/document-ai/processor-library?project=project-12f4b54b-d692-4583-83b).
2. Choose **Custom Extractor** (not Utility Parser, not Identity Document Proofing).
3. Region: **us** (or **eu**).
4. Define schema fields aligned with the registration form (first name, last name, ID number, DOB, optional address).
5. Import and label training documents (Aadhaar, PAN, etc.), train, deploy.
6. Copy the full processor resource `name` from the processor details page.

### Smoke test only: ID Proofing

Only use **Identity Document Proofing** to verify API wiring — expect weak or empty form pre-fill on Indian IDs.

## 4. Secret Manager

```bash
# Replace PROCESSOR_NAME with the full "name" from step 3
echo -n 'projects/project-12f4b54b-d692-4583-83b/locations/us/processors/YOUR_PROCESSOR_ID' | \
  gcloud secrets create document-ai-processor-id \
    --project=project-12f4b54b-d692-4583-83b \
    --data-file=-
```

If the secret already exists, add a version:

```bash
echo -n 'projects/.../processors/...' | \
  gcloud secrets versions add document-ai-processor-id \
    --project=project-12f4b54b-d692-4583-83b \
    --data-file=-
```

Terraform references this via `secret_document_ai_processor_id` in `terraform.tfvars`.

### Your processor (KDMS ID Parser, asia-south1)

Use this **exact** value in Secret Manager (project **ID**, not project number):

```text
projects/project-12f4b54b-d692-4583-83b/locations/asia-south1/processors/d3d78b619ba1d6b
```

```bash
export PROCESSOR_NAME='projects/project-12f4b54b-d692-4583-83b/locations/asia-south1/processors/d3d78b619ba1d6b'

echo -n "$PROCESSOR_NAME" | gcloud secrets versions add document-ai-processor-id \
  --project=project-12f4b54b-d692-4583-83b \
  --data-file=-
```

(If the secret does not exist yet, use `gcloud secrets create` instead of `versions add` — see step 4 above.)

**Custom Extractor schema:** When you define fields in the Console, prefer these names so the PWA mapper picks them up: `first_name`, `last_name`, `id_number`, `dob`, `address` (or `devotee_first_name`, etc.).

**Console “Use out-of-the-box”:** You can test the foundation model before labeling a full dataset. For production accuracy on varied Aadhaar layouts, use **Customize** and import labeled samples later.

**Dataset initializing:** Wait until the dataset finishes initializing before importing training documents.

**Deployed custom version:** Console display name `kdms_aadhaar_260519` maps to version id `93bab276fea4e9cc`. Wait until state is **DEPLOYED** (not `CREATING`). Set in Terraform:

```hcl
document_ai_processor_version = "93bab276fea4e9cc"
```

Or set that version as the **default deployment** in the Console so `DOCUMENT_AI_PROCESSOR_VERSION` can stay empty.

**Label names** (must match Custom Extractor schema): `first_name`, `last_name`, `dob`, `id_number`, `full_address`, `city` (optional: `state`, `zip_code`).

**asia-south1:** Cloud Run must use the regional API endpoint (`asia-south1-documentai.googleapis.com`). Redeploy `kdms-registration` after pulling latest code if OCR returns all-null while Document AI works in Console tests.

### Empty OCR fields but GCS path saved (common cause)

If `id_staging_gcs_path` is set but all field values are `null`, the API call often **succeeded** but returned **zero entities**.

After deploying custom version `kdms_aadhaar_260519`, it may become the **default** processor. That model can return **0 entities** while the **foundation** version still works.

**Fix:** Pin the foundation version in `terraform.tfvars`:

```hcl
document_ai_processor_version = "pretrained-foundation-model-v1.5-2025-08-06"
```

Then `terraform apply` (new Cloud Run revision). Switch to `93bab276fea4e9cc` only when custom model evaluation is acceptable.

**PHP extensions:** Cloud Run image must include `bcmath` (protobuf uses `bccomp()`). If logs show `Call to undefined function bccomp()`, rebuild `kdms-registration` after updating the Dockerfile.

**Verify locally:**

Phone photos and scans are often **>1MB**. Do **not** inline `$(base64 …)` in a `curl -d '…'` one-liner — zsh/bash hit **argument list too long** and `curl` never runs (Python then fails with `JSONDecodeError` on empty stdin). Build the JSON body in a file or with Python instead.

```bash
export PROJECT_ID=project-12f4b54b-d692-4583-83b
export LOCATION=asia-south1
export PROCESSOR_ID=d3d78b619ba1d6b
export VERSION=pretrained-foundation-model-v1.5-2025-08-06
export IMG=/path/to/id.jpg   # e.g. Services/kdms-ocr/test_images/shamsad.jpeg

python3 <<'PY'
import base64, json, mimetypes, os, subprocess, sys, urllib.request

project = os.environ["PROJECT_ID"]
location = os.environ["LOCATION"]
processor_id = os.environ["PROCESSOR_ID"]
version = os.environ["VERSION"]
path = os.environ["IMG"]

mime, _ = mimetypes.guess_type(path)
mime = mime or "image/jpeg"
with open(path, "rb") as f:
    content = base64.b64encode(f.read()).decode()

url = (
    f"https://{location}-documentai.googleapis.com/v1/"
    f"projects/{project}/locations/{location}/processors/{processor_id}"
    f"/processorVersions/{version}:process"
)
body = json.dumps({"rawDocument": {"content": content, "mimeType": mime}}).encode()
token = subprocess.check_output(["gcloud", "auth", "print-access-token"], text=True).strip()
req = urllib.request.Request(
    url,
    data=body,
    headers={"Authorization": f"Bearer {token}", "Content-Type": "application/json"},
    method="POST",
)
with urllib.request.urlopen(req) as resp:
    d = json.load(resp)

entities = d.get("document", {}).get("entities", [])
print("entities", len(entities))
for e in entities[:12]:
    print(" ", e.get("type"), ":", (e.get("mentionText") or "")[:60])
PY
```

Expected for repo test Aadhaar `shamsad.jpeg` with foundation version: **4** entities. `kundan.png`: **8**.

## 5. IAM for Cloud Run SA

```bash
gcloud projects add-iam-policy-binding project-12f4b54b-d692-4583-83b \
  --member="serviceAccount:run-kdms-registration@project-12f4b54b-d692-4583-83b.iam.gserviceaccount.com" \
  --role="roles/documentai.apiUser"
```

(Terraform also applies this in `terraform/gcs.tf`.)

## 6. Local dev without Document AI

Set `DOCUMENT_AI_PROCESSOR_ID=mock` (see `docker-compose.split.yml`). OCR returns empty fields; the PWA still works for manual entry.

## Troubleshooting

| Issue | Fix |
|-------|-----|
| `Invalid choice: 'documentai'` | Use REST/curl or Console — not `gcloud documentai`. |
| `PROCESSOR_TYPE_NOT_FOUND` | Run `fetchProcessorTypes` for your `LOCATION`; use exact `type` string (e.g. `ID_PROOFING_PROCESSOR`). |
| Permission denied | Your user needs `roles/documentai.editor` or `roles/owner` on the project. |
| `zsh: argument list too long: curl` | Image too large for inline base64 in shell; use the Python verify block above or `curl -d @request.json`. |
| `JSONDecodeError` after curl | Usually empty curl output because the previous command failed; fix the curl/body step first. |
