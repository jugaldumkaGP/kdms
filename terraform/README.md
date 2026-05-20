# Terraform — KDMS on GCP

Terraform root stack for split Cloud Run (v2) services in GCP. It manages service definitions (image, scaling, env vars, Cloud SQL volume where needed, ingress, labels) and optional **public invoker** IAM (`allUsers` → `roles/run.invoker`).

## What this stack manages

- `google_cloud_run_v2_service.kdms` — Cloud Run service **`kdms-prod`** (UI/web)
- `google_cloud_run_v2_service.kdms_api` — Cloud Run service **`kdms-api-prod`** (API)
- `google_cloud_run_v2_service.kdms_reports` — optional **`kdms-reports-prod`** when `enable_reports_service = true`
- `google_cloud_run_v2_service.kdms_ocr` — optional **`kdms-ocr-prod`** when `enable_ocr_service = true`
- matching IAM invoker bindings per service when `*_allow_unauthenticated = true`

## What it does **not** manage

State bucket **`gs://kdms-tf-state`**, Artifact Registry repo **`apps`**, runtime service accounts, Secret Manager secrets, MySQL instance or users, VPC, Workload Identity Federation, and CI are **out of scope** — created or maintained outside this stack. See **[Bootstrap, CI, and Artifact Registry](#bootstrap-ci-and-artifact-registry)** for one-time GCP / GitHub setup.

## Bootstrap, CI, and Artifact Registry

### Runtime access (production)

The **`run-kdms@...`** service account must have **`roles/cloudsql.client`** on the Cloud SQL instance and Secret Manager access to **`kdms-app-key`**, **`kdms-db-password`**, and **`kdms-service-key`** (usually granted when the SA and secrets were bootstrapped).

### GitHub Actions — build and push

1. In GCP, bind the Workload Identity **provider** under `projects/684080887473/locations/global/workloadIdentityPools/...` so the GitHub repository **`agupta73/kdms`** can impersonate **`ci-builder-kdms@project-12f4b54b-d692-4583-83b.iam.gserviceaccount.com`**.

2. Grant **`ci-builder-kdms`** Artifact Registry **writer** (and **Service Account User** if needed) on repository **`apps`** in **`asia-south1`**.

3. In this GitHub repo, add repository variable **`GCP_WIF_PROVIDER`** (Settings → Secrets and variables → Actions → Variables) with the full provider resource name, for example:  
   `projects/684080887473/locations/global/workloadIdentityPools/github-pool/providers/github-provider`

4. Pushes to **`main`** run **Build and push service images to Artifact Registry** and build/push all four images:
   - `kdms-main`
   - `kdms-api`
   - `kdms-reports`
   - `kdms-ocr`
   using the **short commit SHA** and **`branch-main`** tags.
   You can also run it manually: **Actions** → **Build and push service images to Artifact Registry** → **Run workflow**.

5. The monorepo includes **`Services/kdms-reports`** and **`Services/kdms-ocr`**; CI builds all four images from one push.

CI does **not** deploy Cloud Run automatically; after each push to **`main`**, the **pin-tfvars** job in **`.github/workflows/push-gar.yml`** resolves each service’s **`branch-main`** digest in Artifact Registry and commits updated **`image_digest`** / **`*_image_digest`** fields in **`terraform.tfvars`** (commit message includes **`[skip ci]`** so only one image build runs per code push). Run **`terraform apply`** locally or in a separate pipeline to roll out new revisions.

To pin digests manually without waiting for CI (requires `gcloud` auth; works on macOS bash 3.2):

```bash
cd terraform
bash scripts/ci-update-image-digests.sh terraform.tfvars
git diff terraform.tfvars
```

**You should not need this on every deploy** if `pin-tfvars` succeeds on GitHub — `git pull` before `terraform apply` instead.

### Artifact Registry

Repository: **`apps`** in **`asia-south1`**, image **`kdms`**:  
`asia-south1-docker.pkg.dev/project-12f4b54b-d692-4583-83b/apps/kdms`

Create the **`apps`** repository in GCP if it does not exist yet (once per project/region), or codify it in a separate bootstrap stack if you choose.

### Production images: rolling tag, explicit tag, or digest

Precedence for each service: **`image_digest` (or `api_image_digest`, etc.)** if set, else **`*_image_tag`** if set, else **`rolling_image_tag`** (default **`branch-main`**, the tag CI updates on every push to `main`).

- **Rolling (track CI):** leave **`image_digest`** and **`image_tag`** empty (and the same for optional per-service `*_image_digest` / `*_image_tag`). Terraform resolves images to **`…/SERVICE:branch-main`** (or **`rolling_image_tag`**). Because the URI string often stays identical while Artifact Registry moves the tag to a new manifest, **`terraform plan` may show no diff** after CI pushes. Bump **`revision_trigger`** in **`terraform.tfvars`** (or pin **`image_digest`**) so Terraform creates a new revision that pulls the current manifest.
- **Explicit tag:** set **`image_tag`** (or **`api_image_tag`**, …) to an **immutable tag** CI pushed (e.g. short git SHA) with digest empty.
- **Digest (pin / rollback):** set **`image_digest`** (or **`api_image_digest`**, …) to **`sha256:…`** and leave the matching **`image_tag`** empty. Overrides **`rolling_image_tag`**.

To use a different moving tag than **`branch-main`**, set **`rolling_image_tag`** (e.g. some teams publish **`latest`**).

See **`variables.tf`** and **`terraform.tfvars.example`** for **`app_url`** and other inputs.

## One-time setup

From the repository root:

```bash
cd terraform
cp terraform.tfvars.example terraform.tfvars
# set images: see terraform.tfvars.example (rolling vs tag vs digest)

terraform init
terraform workspace select prod || terraform workspace new prod
bash import.sh
terraform plan
```

## Day-to-day deploys (new image)

After CI builds and pushes images (and commits digests to **`terraform.tfvars`** on **`main`**):

1. **`git pull origin main`** so **`terraform/terraform.tfvars`** has the digests from **`pin-tfvars`** (do not apply from a stale checkout).
2. **`cd terraform`** and run **`terraform plan`** — you should see **`containers.image`** change when pins moved; if the plan only shows **`scaling`** drift, your tfvars are still stale (pull again or run **`scripts/ci-update-image-digests.sh`**).
3. Plan and apply (from **`terraform/`**):

```bash
cd terraform
terraform plan -out=plan.tfplan
terraform apply plan.tfplan
```

## Rollback

Set the affected service’s **`image_digest`** (or immutable **`image_tag`**) to a known-good value from Artifact Registry, then **`terraform plan`** and **`terraform apply`**.

## Teardown

```bash
cd terraform
terraform destroy
```

This removes only the Cloud Run service and the invoker IAM binding managed here. The database, secrets, service accounts, and registry remain.

## Troubleshooting

- **`cannot destroy service without setting deletion_protection=false`:** For **`google_cloud_run_v2_service`**, the provider’s effective default is to **block deletes** unless **`deletion_protection = false`**. This stack sets **`cloud_run_deletion_protection = false`** (see **`variables.tf`**) on all four services. **First apply** with that in place updates GCP so later destroys succeed. If a **destroy is already the only change** (e.g. **`enable_reports_service = false`**) and apply still fails, turn off **deletion protection** in the **Cloud Console** for that service (or use **`gcloud run services replace`** with a YAML that sets **`deletionProtection: false`**), then re-run apply. If you did **not** intend to remove **`kdms-reports-prod`**, set **`enable_reports_service = true`** and re-check **`terraform plan`**.
- **Env var order** in **`main.tf`** follows the live **`kdms-prod`** revision (plain vars, then **`APP_KEY`** / **`DB_PASSWORD`**, then **`APP_URL`**). Do not reorder without checking **`terraform plan`** against GCP.
- If **`terraform plan`** keeps changing **annotations** such as **`client_version`** or **`run.googleapis.com/client-name`** on the revision template, add those attributes to **`lifecycle.ignore_changes`** on **`google_cloud_run_v2_service.kdms`** (same pattern as **`run.googleapis.com/operation-id`**).
- If **`terraform plan`** shows churn on **`container`** fields Google normalizes server-side (for example **`image_pull_policy`** if it appears in your provider version), add them to **`lifecycle.ignore_changes`**.
- If the **invoker** binding drifts, confirm the service was not switched with **`gcloud run services update --no-allow-unauthenticated`** (or re-run **`import.sh`** after aligning with the live policy).
