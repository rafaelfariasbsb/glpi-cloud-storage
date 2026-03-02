# Cloud Storage Plugin — Roadmap & TODO

> Last updated: 2026-03-01
> Based on: two external senior dev reviews (`analise.txt`) cross-referenced with actual codebase audit.

---

## P0 — Critical (do before production / marketplace submission)

### 1. Add `declare(strict_types=1)` to all PHP files

**Status:** Missing in all 13 PHP files.
**Scope:** All files in `src/`, `front/`, `setup.php`, `hook.php`.
**Notes:**
- Parameter/return typing is already ~95% complete.
- Fix `StorageClientInterface::readStream()` — add return type `: mixed`.
- `hook.php` and `setup.php` hook functions may need flexible types for GLPI compatibility — evaluate case by case.

### 2. Unit & Integration Tests (PHPUnit)

**Status:** Zero tests exist.
**Framework:** PHPUnit 11.5 + GLPI's `DbTestCase` base class.
**Coverage targets:**

| Class | What to test |
|-------|-------------|
| `DocumentHook` | Dedup logic (SHA1 match skips upload), orphan blob cleanup, PRE_ITEM_PURGE order |
| `Config` | Encryption/decryption round-trip, `validateLocalPath()` path traversal blocking, cache reset |
| `DocumentTracker` | `track()`, `isTracked()`, `countBySha1()`, `getByDocumentId()` |
| `AzureBlobClient` | Upload/download/delete with mocked `StorageClientInterface` (vfsStream for filesystem) |
| `StorageClientFactory` | Singleton behavior, `resetInstance()`, unknown provider exception |
| `MigrateCommand` | Dry-run flag, dedup inline, excluded IDs on error |

**Additional test scenarios (from backlog):**
- Migration: test auto-migration from old `azureblobstorage` plugin (config mapping, table rename)
- End-to-end: both providers (Azure + S3) — upload, download, delete, dedup cycle

**Tools available:** vfsStream (filesystem mock), DbTestCase helpers (`createItem`, `login`, `createTxtDocument`).

### 3. CI/CD Pipeline (GitHub Actions)

**Status:** No workflows exist. Makefile only includes GLPI's `PluginsMakefile.mk`.
**Workflows to create (`.github/workflows/ci.yml`):**

- PHPStan (already in dev dependencies)
- GLPI Coding Standard (PHPCS — already in dev dependencies)
- PHPUnit test suite
- Plugin install/uninstall smoke test on GLPI 11.0+

**Reference:** Copy patterns from official GLPI plugins (e.g., `glpi-project/fields`, `glpi-project/formcreator`).

---

## P1 — High (before Phase 2 / S3 implementation)

### 4. Implement S3Client

**Status:** Architecture is 100% ready.
**What exists:**
- `StorageClientInterface` defines the contract (8 methods)
- `StorageClientFactory` has `match` expression with `throw` for S3
- `composer.json` already includes `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php`
- `config.form.php` already validates S3 fields (`s3_bucket`, `s3_region`, `s3_endpoint`, keys)
- `setup.php` already creates S3 config defaults

**To do:**
- Create `src/S3Client.php` implementing `StorageClientInterface`, mirror `AzureBlobClient` structure
- Add MinIO service to `docker-compose.yml` for local S3 testing

### 5. CLI Sync / Consistency Check Command

**Status:** Does not exist.
**Purpose:** Verify tracker database vs actual blobs in cloud storage.
**Detects:**
- Tracker entries pointing to non-existent blobs (orphan trackers)
- Blobs in cloud with no tracker reference (orphan blobs)
- SHA1 mismatches between tracker and actual file

**Implementation:** New `src/Console/SyncCheckCommand.php` — read-only by default, `--fix` flag for cleanup.

### 6. Makefile Targets

**Status:** Makefile only includes GLPI's shared makefile.
**Add:**

```makefile
test:
	vendor/bin/phpunit

stan:
	vendor/bin/phpstan analyse

cs:
	vendor/bin/phpcs

cs-fix:
	vendor/bin/phpcbf
```

---

## P2 — Medium (production hardening)

### 7. Async Upload via Background Jobs (GLPI Automatic Actions)

**Status:** Does not exist. Current upload is synchronous inside `ITEM_ADD` hook.
**Problem:** Large files + slow/unstable Azure connection = long HTTP request blocking the user's browser. A 50MB document on a degraded link can mean 30s+ of wait.
**Solution:**
1. `DocumentHook::onItemAdd()` saves the file locally + inserts a row in a new `glpi_plugin_cloudstorage_uploadqueue` table (status: `pending`)
2. A registered `CronTask` (GLPI Automatic Action) processes the queue in background, uploading files and updating `DocumentTracker`
3. On success: remove from queue, optionally delete local copy
4. On failure: increment retry counter, log error, re-queue with backoff delay

**New components needed:**
- `src/UploadQueue.php` (CommonDBTM — queue table ORM)
- `src/CronUploadTask.php` (CronTask handler)
- Registration in `setup.php` (`CronTask::register()`)
- Modified `DocumentHook` to enqueue instead of uploading directly

**Edge cases to handle:**
- Document deleted before cron processes it → skip + cleanup queue entry
- GLPI restart mid-queue → cron picks up `pending` entries on next run
- Deduplication: check SHA1 before upload (same as current logic)
- Config toggle: `async_upload` enabled/disabled (synchronous remains the default for simplicity)

**Impact:** Highest UX improvement for production environments with large files or unreliable connectivity.

### 8. Circuit Breaker (Simplified)

**Status:** Does not exist. Every upload attempt hits Azure regardless of recent failures.
**Problem:** If Azure is down, every document upload triggers a timeout + error. N users uploading = N timeout waits.
**Simplified approach:**
- Track consecutive failure count in a transient config key (or `$_SESSION` / APCu)
- After X consecutive failures (configurable, default 5), auto-switch to local-only mode for Y minutes
- Log warning: "Circuit breaker activated — uploads queued locally"
- Works best combined with item #7 (Async Upload): failed docs get queued for retry when circuit closes

**Note:** Full circuit breaker pattern (half-open state, gradual recovery) is over-engineering for a GLPI plugin. A simple "fail counter + cooldown timer" is sufficient.

### 9. CDN Endpoint Support

**Status:** Does not exist. SAS URLs always point directly to Azure Blob Storage hostname.
**Problem:** Users distributed geographically experience high latency downloading from a single Azure region.
**Solution:**
- Add `cdn_endpoint` config field (e.g., `https://myglpi.azureedge.net`)
- When set, `generateTemporaryUrl()` replaces the blob storage hostname with the CDN hostname in the generated URL
- SAS token remains valid (Azure CDN passes the query string to origin)

**Implementation:** ~10 lines of code in `AzureBlobClient::generateTemporaryUrl()` + 1 field in config form.
**Applies to:** Redirect mode only (proxy mode already streams through GLPI server).

### 10. Retry Logic (Exponential Backoff)

**Status:** Absent across entire codebase. Current design is fail-fast + log + fallback.
**Where it matters most:**
- `MigrateCommand` — batch processing thousands of documents; transient Azure errors can abort migration
- `AzureBlobClient::upload()` / `download()` — intermittent network issues

**Approach:** Wrap Flysystem calls with configurable retry (max attempts, base delay). Consider `league/flysystem` retry middleware or simple loop with `usleep()`.

**Lower priority for:** Single document operations (upload on hook) where fail-fast + user notification is acceptable. Even lower priority if Async Upload (#7) is implemented (cron retries naturally).

### 11. Azure AD / Managed Identity Authentication

**Status:** Currently uses connection string / account key only.
**Why:** Eliminates static credentials in config, follows Azure security best practices.
**Blocked by:** Requires `azure-oss/storage-blob-flysystem` support or custom adapter.
**Already in:** Security audit P2 backlog.

### 12. SAS URL IP Restriction

**Status:** SAS URLs generated without IP constraint.
**Improvement:** Add client IP to SAS token generation (`SignedIp` parameter) to restrict URL usage to the requesting user's IP.
**Already in:** Security audit P2 backlog.

### 13. Rate Limiting for Document Downloads

**Status:** No rate limiting on `document.send.php`.
**Purpose:** Prevent abuse of proxy/redirect download endpoint.
**Approach:** Simple per-user/per-IP counter with configurable threshold. GLPI session already identifies the user.
**Already in:** Security audit P2 backlog.

---

## P3 — Low (quality of life & polish)

### 14. SAS Token Caching

**Status:** Every download request in redirect mode generates a new SAS token via `generateTemporaryUrl()`.
**Problem:** Pages with many attachments (tickets with 20+ documents) generate one SAS per doc per page load.
**Reality check:** SAS generation is a **local cryptographic operation** (no network call to Azure), so overhead is low. However, caching avoids repeated computation and can benefit pages with many inline images.
**Approach:** Cache generated SAS URLs per `(remotePath, userId)` in GLPI Cache API (or `$_SESSION`) for `min(url_expiry - 1min, 3min)`.
**Low priority:** Marginal gain in most deployments.

### 15. CHANGELOG Format

**Status:** CHANGELOG.md exists but may not follow standard format.
**Improvement:** Adopt [Keep a Changelog](https://keepachangelog.com/) format for marketplace readiness.

### 16. README Badges

**Status:** No badges.
**Add after CI is working:** GLPI version compatibility, PHPStan level, tests passing, license.

### 17. Internationalization (i18n)

**Status:** Needs verification of `locales/` directory coverage.
**Minimum:** `en_GB` + `pt_BR`. Marketplace may require English as primary.

### 18. Structured Logging (JSON)

**Status:** Currently uses `Toolbox::logInFile()` with plain text.
**Improvement:** JSON-formatted log entries for better parsing/monitoring (ELK, Datadog, etc.).
**Low priority:** Current logging is already well-structured with error messages + stack traces + secret redaction.

### 19. Expand `.gitignore`

**Status:** Current `.gitignore` only excludes `**/vendor/`.
**Add:** `.env`, `.idea/`, `.vscode/`, `.DS_Store`, `*.cache`, `composer.lock` (plugin convention — lock file not committed).
**Source:** Security audit MEDIA-04.

### 20. Create `.dockerignore`

**Status:** Does not exist.
**Add:** `.git/`, `vendor/`, `docs/`, `*.md`, `.idea/`, `.vscode/`, `tests/` — standard exclusions to keep Docker context lean.
**Source:** Security audit MEDIA-05.

### 21. Credential Placeholder Pattern in Config Template

**Status:** Config form sends actual encrypted values to the browser for password fields.
**Improvement:** Use a placeholder pattern (e.g., `••••••••`) in the template for secret fields. Only update the DB value if the user submits a non-placeholder value. Prevents accidental credential exposure in browser DOM.
**Source:** Security audit BAIXA-06.

### 22. Storage Firewall Documentation

**Status:** No documentation on securing Azure Blob Storage at the network level.
**Scope:** This is infrastructure configuration, not plugin code. Document how to:
- Restrict blob storage to specific IP ranges (Azure Portal → Networking)
- Configure Private Endpoints for VNet-only access
- Combine with SAS tokens for defense-in-depth

**Add to:** `docs/05-security.md` as a "Recommended Azure Configuration" section.

---

## P4 — Future / Nice-to-Have

### 23. Dashboard with Statistics

Plugin settings page showing: total documents in cloud, total size, estimated cost, upload/download counts.

### 24. Email Notification on Repeated Upload Failures

Integrate with GLPI's `NotificationEvent` to alert admins when uploads fail X times in Y period.

### 25. Azure Storage Tiers (Hot/Cool/Archive) — Documentation Only

**Decision:** Won't implement as plugin feature. Azure Lifecycle Management policies handle this natively at the storage account level (move Hot→Cool→Archive by last modified date) — no code, no maintenance.
**Action:** Document as infrastructure recommendation in `docs/05-security.md` or README (e.g., "Configure Azure Lifecycle Management policy to move blobs to Cool tier after 30 days").
**SDK available:** `setBlobTier()` exists if ever justified, but lifecycle policy is the correct approach.

### 26. Multi-tenant Support (Multiple Containers/Prefixes)

Per-entity or per-profile storage containers. GLPI is typically single-tenant — only relevant for MSP deployments.

### 27. Key Vault Integration for Credentials

Store Azure/S3 credentials in Azure Key Vault or AWS Secrets Manager instead of GLPI database. Highest security tier.
**Already in:** Security audit P3 backlog.

### 28. Content-Length Header in Proxy Mode

Add `Content-Length` response header in proxy downloads for better browser progress indication.
**Already in:** Security audit P3 backlog.

---

## Rejected Suggestions

| Suggestion | Reason |
|-----------|--------|
| "Improve AzureBlobClient to use streams" | Already implemented — `writeStream()` / `readStream()` with `try-finally` |
| "Optimize proxy mode with readStream + fpassthru" | Already implemented — `StreamedResponse` with `stream_copy_to_stream()` |
| "Separate DocumentHook into DocumentUploader/DocumentCleaner" | Over-engineering — class is ~300 lines with well-separated static methods. Complexity doesn't justify extraction |
| "Create a StorageProviderInterface with Strategy pattern" | Already implemented — `StorageClientInterface` (8 methods) + `StorageClientFactory` (singleton with `match` expression). `DocumentHook` only knows the interface, never touches `AzureBlobClient` directly |
