# Cloud Storage Plugin — Roadmap & TODO

> Last updated: 2026-03-01
> Based on: four external senior dev reviews (`analise.txt`, `analise.md`) cross-referenced with actual codebase audit.

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

### 4. Fix `fromParams()` credential leak in error messages

**Status:** Bug confirmed. Security issue.
**File:** `src/AzureBlobClient.php:66-73`
**Problem:** `fromConfig()` wraps the Azure SDK call in try/catch with `sanitizeErrorMessage()`, but `fromParams()` does not. Since `fromParams()` is used by "Test Connection" in the config form, an exception can expose the raw connection string in the UI.
**Fix:** Add try/catch with `sanitizeErrorMessage()` matching the `fromConfig()` pattern.
**Effort:** 15 minutes
**Source:** Code review `analise.md` #7

### 5. Fix MigrateCommand dedup missing blob existence check

**Status:** Bug confirmed. Data integrity issue.
**File:** `src/Console/MigrateCommand.php:175-194`
**Problem:** `DocumentHook::onItemAdd()` correctly checks `$client->exists($filepath)` before skipping upload on SHA1 match. `MigrateCommand` skips this check — if a blob was deleted externally (or by Azure Lifecycle policy), it creates a tracker for a non-existent blob, making the document inaccessible.
**Fix:** Add `$client->exists($filepath)` before skipping upload in the dedup branch.
**Effort:** 15 minutes
**Source:** Code review `analise.md` #8

### 6. Validate `$_GET['file']` format in document.send.php

**Status:** Bug confirmed. Information disclosure risk.
**File:** `front/document.send.php:37-38`
**Problem:** `$_GET['file']` goes directly to `$doc->find()` without format validation. No SQL injection risk (prepared statements), but allows enumeration of document paths in the database.
**Fix:** Add regex validation for expected GLPI document path format (`EXT/xx/sha1.EXT`).
**Effort:** 20 minutes
**Source:** Code review `analise.md` #5

### 7. Remove dead code `cleanEmptyDirs()`

**Status:** Confirmed dead code.
**File:** `src/DocumentHook.php:298-315`
**Problem:** Method is defined but never called anywhere in the codebase.
**Fix:** Remove the method entirely. If needed later, it can be restored from git history.
**Effort:** 5 minutes
**Source:** Code review `analise.md` #6

### 8. Move S3 dependencies to `suggest` until Phase 2

**Status:** Confirmed unnecessary weight.
**File:** `composer.json`
**Problem:** `league/flysystem-aws-s3-v3` + `aws/aws-sdk-php` add ~50MB to vendor directory. The factory throws `RuntimeException` when S3 is selected. Inflates install size and attack surface for zero functionality.
**Fix:** Move from `require` to `suggest` with a descriptive message. Re-add to `require` when `S3Client` is implemented.
**Effort:** 10 minutes
**Source:** Code review `analise.md` #3

### 9. Add `StorageClientFactory::setInstance()` for testability

**Status:** Missing. Pre-requisite for unit tests (#2).
**File:** `src/StorageClientFactory.php`
**Problem:** Factory has `resetInstance()` but no `setInstance()`. Without it, there's no way to inject a mock `StorageClientInterface` when testing `DocumentHook`, `MigrateCommand`, or `document.send.php`.
**Fix:** Add `public static function setInstance(StorageClientInterface $client): void`.
**Effort:** 5 minutes
**Source:** Code review `analise.md` #9

---

## P1 — High (before Phase 2 / S3 implementation)

### 10. Implement S3Client

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

### 11. CLI Sync / Consistency Check Command

**Status:** Does not exist.
**Purpose:** Verify tracker database vs actual blobs in cloud storage.
**Detects:**
- Tracker entries pointing to non-existent blobs (orphan trackers)
- Blobs in cloud with no tracker reference (orphan blobs)
- SHA1 mismatches between tracker and actual file

**Implementation:** New `src/Console/SyncCheckCommand.php` — read-only by default, `--fix` flag for cleanup.

### 12. Rename `--delete-azure` to `--delete-remote`

**Status:** Naming inconsistency with multi-cloud rename.
**File:** `src/Console/MigrateLocalCommand.php:30-34`
**Problem:** The plugin was renamed from `azureblobstorage` to `cloudstorage` for multi-cloud support, but the CLI flag remains Azure-specific. Also affects comments in `DocumentHook.php` ("Not tracked in Azure") and `document.send.php` ("Not in Azure").
**Fix:** Rename flag to `--delete-remote`, update all Azure-specific comments to cloud-generic language.
**Note:** This is a breaking change for any scripts using `--delete-azure`. Document in CHANGELOG.
**Effort:** 10 minutes
**Source:** Code review `analise.md` #11

### 13. Harden `plugin_cloudstorage_check_config()`

**Status:** Likely returns `true` unconditionally or does minimal validation.
**Problem:** GLPI calls `plugin_<key>_check_config()` on every page load to verify the plugin is functional. If it returns `false`, GLPI auto-disables the plugin. Currently it doesn't validate runtime prerequisites.
**Fix:** Check at minimum:
- Required config fields are populated (provider, connection string or keys, container/bucket)
- `composer autoload` is available (`vendor/autoload.php` exists)
- Optionally: cache the result for the request to avoid repeated checks
**Why P1:** Prevents the plugin from running in a broken state (e.g., after a config reset or incomplete migration).
**Effort:** 30 minutes
**Source:** Code review `analise.txt` review 4, section 2.2A

### 14. SemVer + GitHub Releases (Tags)

**Status:** No releases exist on GitHub. Code is only accessible via `main` branch clone.
**Problem:** Without releases, users can't pin versions, there's no upgrade path visibility, and marketplace submission requires versioned artifacts.
**Fix:**
- Tag current state as `v2.0.0` (`git tag v2.0.0 && git push --tags`)
- Create GitHub Release with notes summarizing Phase 1 features
- Future: automate via GitHub Actions on tag push
**Effort:** 15 minutes (manual), 1 hour (with CI automation)
**Source:** Code review `analise.txt` review 4, section 2.6A

### 15. Makefile Targets

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

### 16. Async Upload via Background Jobs (GLPI Automatic Actions)

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

### 17. Circuit Breaker (Simplified)

**Status:** Does not exist. Every upload attempt hits Azure regardless of recent failures.
**Problem:** If Azure is down, every document upload triggers a timeout + error. N users uploading = N timeout waits.
**Simplified approach:**
- Track consecutive failure count in a transient config key (or `$_SESSION` / APCu)
- After X consecutive failures (configurable, default 5), auto-switch to local-only mode for Y minutes
- Log warning: "Circuit breaker activated — uploads queued locally"
- Works best combined with item #16 (Async Upload): failed docs get queued for retry when circuit closes

**Note:** Full circuit breaker pattern (half-open state, gradual recovery) is over-engineering for a GLPI plugin. A simple "fail counter + cooldown timer" is sufficient.

### 18. CDN Endpoint Support

**Status:** Does not exist. SAS URLs always point directly to Azure Blob Storage hostname.
**Problem:** Users distributed geographically experience high latency downloading from a single Azure region.
**Solution:**
- Add `cdn_endpoint` config field (e.g., `https://myglpi.azureedge.net`)
- When set, `generateTemporaryUrl()` replaces the blob storage hostname with the CDN hostname in the generated URL
- SAS token remains valid (Azure CDN passes the query string to origin)

**Implementation:** ~10 lines of code in `AzureBlobClient::generateTemporaryUrl()` + 1 field in config form.
**Applies to:** Redirect mode only (proxy mode already streams through GLPI server).

### 19. Retry Logic (Exponential Backoff)

**Status:** Absent across entire codebase. Current design is fail-fast + log + fallback.
**Where it matters most:**
- `MigrateCommand` — batch processing thousands of documents; transient Azure errors can abort migration
- `AzureBlobClient::upload()` / `download()` — intermittent network issues

**Approach:** Wrap Flysystem calls with configurable retry (max attempts, base delay). Consider `league/flysystem` retry middleware or simple loop with `usleep()`.

**Lower priority for:** Single document operations (upload on hook) where fail-fast + user notification is acceptable. Even lower priority if Async Upload (#16) is implemented (cron retries naturally).

### 20. Azure AD / Managed Identity Authentication

**Status:** Currently uses connection string / account key only.
**Why:** Eliminates static credentials in config, follows Azure security best practices.
**Blocked by:** Requires `azure-oss/storage-blob-flysystem` support or custom adapter.
**Already in:** Security audit P2 backlog.

### 21. SAS URL IP Restriction

**Status:** SAS URLs generated without IP constraint.
**Improvement:** Add client IP to SAS token generation (`SignedIp` parameter) to restrict URL usage to the requesting user's IP.
**Already in:** Security audit P2 backlog.

### 22. Rate Limiting for Document Downloads

**Status:** No rate limiting on `document.send.php`.
**Purpose:** Prevent abuse of proxy/redirect download endpoint.
**Approach:** Simple per-user/per-IP counter with configurable threshold. GLPI session already identifies the user.
**Already in:** Security audit P2 backlog.

---

## P3 — Low (quality of life & polish)

### 23. SAS Token Caching

**Status:** Every download request in redirect mode generates a new SAS token via `generateTemporaryUrl()`.
**Problem:** Pages with many attachments (tickets with 20+ documents) generate one SAS per doc per page load.
**Reality check:** SAS generation is a **local cryptographic operation** (no network call to Azure), so overhead is low. However, caching avoids repeated computation and can benefit pages with many inline images.
**Approach:** Cache generated SAS URLs per `(remotePath, userId)` in GLPI Cache API (or `$_SESSION`) for `min(url_expiry - 1min, 3min)`.
**Low priority:** Marginal gain in most deployments.

### 24. CHANGELOG Format

**Status:** CHANGELOG.md exists but may not follow standard format.
**Improvement:** Adopt [Keep a Changelog](https://keepachangelog.com/) format for marketplace readiness.

### 25. README Badges

**Status:** No badges.
**Add after CI is working:** GLPI version compatibility, PHPStan level, tests passing, license.

### 26. Internationalization (i18n)

**Status:** Needs verification of `locales/` directory coverage.
**Minimum:** `en_GB` + `pt_BR`. Marketplace may require English as primary.

### 27. Structured Logging (JSON)

**Status:** Currently uses `Toolbox::logInFile()` with plain text.
**Improvement:** JSON-formatted log entries for better parsing/monitoring (ELK, Datadog, etc.).
**Low priority:** Current logging is already well-structured with error messages + stack traces + secret redaction.

### 28. Expand `.gitignore`

**Status:** Current `.gitignore` only excludes `**/vendor/`.
**Add:** `.env`, `.idea/`, `.vscode/`, `.DS_Store`, `*.cache`, `composer.lock` (plugin convention — lock file not committed).
**Source:** Security audit MEDIA-04.

### 29. Create `.dockerignore`

**Status:** Does not exist.
**Add:** `.git/`, `vendor/`, `docs/`, `*.md`, `.idea/`, `.vscode/`, `tests/` — standard exclusions to keep Docker context lean.
**Source:** Security audit MEDIA-05.

### 30. Credential Placeholder Pattern in Config Template

**Status:** Config form sends actual encrypted values to the browser for password fields.
**Improvement:** Use a placeholder pattern (e.g., `••••••••`) in the template for secret fields. Only update the DB value if the user submits a non-placeholder value. Prevents accidental credential exposure in browser DOM.
**Source:** Security audit BAIXA-06.

### 31. Centralize config keys (DRY)

**Status:** Same config key list duplicated in 3 files.
**Files:** `src/Config.php` (`CONFIG_KEYS` constant), `hook.php` (install defaults), `front/config.form.php` (form fields).
**Problem:** Adding or removing a config key requires editing 3 files manually. Risk of desynchronization.
**Fix:** Make `Config::CONFIG_KEYS` public and reuse it in `hook.php` and `config.form.php`. Add default values as a separate constant (`Config::CONFIG_DEFAULTS`).
**Effort:** 30 minutes
**Source:** Code review `analise.md` #17

### 32. Modernize JavaScript to ES6+

**Status:** `public/js/url-rewriter.js` uses pure ES5 syntax (`var`, `function()`, `for` loops).
**Problem:** GLPI 11 requires modern browsers (Chrome 80+, Firefox 78+). ES6+ (`const`, arrow functions, `for...of`) is safe and improves readability.
**Effort:** 30 minutes
**Source:** Code review `analise.md` #16

### 33. Deprecate or document `download()` method memory risk

**Status:** `AzureBlobClient::download()` uses `$this->filesystem->read()` which loads entire file into memory.
**Reality check:** No code currently calls `download()` — all paths use `readStream()` or `downloadToFile()`. However, the method exists in the public interface and could be used by future code.
**Fix:** Add `@deprecated` PHPDoc + `trigger_deprecation()` call, or document the memory limit clearly in the interface. Consider removing from `StorageClientInterface` if unused.
**Effort:** 15 minutes
**Source:** Code review `analise.md` #12

### 34. Document `filepath`/`remote_path` column intention

**Status:** In `DocumentTracker::track()`, `filepath` and `remote_path` are always set to the same value.
**Why it exists:** Intentional preparation for Phase 2 (S3 may use different path prefixes) and future multi-tenant support (per-entity prefixes).
**Fix:** Add PHPDoc comment in `DocumentTracker` and schema comment in `hook.php` explaining the intentional redundancy.
**Effort:** 10 minutes
**Source:** Code review `analise.md` #14

### 35. Storage Firewall Documentation

**Status:** No documentation on securing Azure Blob Storage at the network level.
**Scope:** This is infrastructure configuration, not plugin code. Document how to:
- Restrict blob storage to specific IP ranges (Azure Portal → Networking)
- Configure Private Endpoints for VNet-only access
- Combine with SAS tokens for defense-in-depth

**Add to:** `docs/05-security.md` as a "Recommended Azure Configuration" section.

### 36. SECURITY.md

**Status:** Does not exist.
**Purpose:** Provide a responsible disclosure policy for the plugin, following GLPI core's pattern (`SECURITY.md`).
**Content:** Contact email, preferred reporting method (GitHub Security Advisories), response time expectation.
**Effort:** 15 minutes
**Source:** Code review `analise.txt` review 4, section 2.6C

### 37. Compatibility Matrix in README

**Status:** README lists minimum requirements (GLPI 11.0, PHP 8.2) but no dependency version matrix or known limitations.
**Add:**
- Supported Azure SDK / Flysystem versions
- Known limitations (max file size for proxy mode, streaming caveats)
- Tested GLPI versions
**Effort:** 20 minutes
**Source:** Code review `analise.txt` review 4, section 2.6B

### 38. Add `.editorconfig`

**Status:** Does not exist.
**Purpose:** Enforce consistent indentation (4 spaces for PHP, 2 for JS/YAML), encoding (UTF-8), and line endings (LF) across IDEs.
**Effort:** 5 minutes
**Source:** Code review `analise.txt` review 4, section 2.5C

### 39. Use GLPI `Migration` class for future schema changes

**Status:** Current install uses raw SQL in `setup.php`. Works fine for initial table creation.
**Note:** For any future schema changes (new columns, indexes, constraint modifications), use GLPI's `Migration` class instead of raw SQL. This ensures predictable, versionable schema evolution.
**Not a task:** This is a development guideline, not an action item. Document in `docs/07-development-guide.md`.
**Source:** Code review `analise.txt` review 4, section 2.2B

---

## P4 — Future / Nice-to-Have

### 40. Dashboard with Statistics

Plugin settings page showing: total documents in cloud, total size, estimated cost, upload/download counts.

### 41. Email Notification on Repeated Upload Failures

Integrate with GLPI's `NotificationEvent` to alert admins when uploads fail X times in Y period.

### 42. Azure Storage Tiers (Hot/Cool/Archive) — Documentation Only

**Decision:** Won't implement as plugin feature. Azure Lifecycle Management policies handle this natively at the storage account level (move Hot→Cool→Archive by last modified date) — no code, no maintenance.
**Action:** Document as infrastructure recommendation in `docs/05-security.md` or README (e.g., "Configure Azure Lifecycle Management policy to move blobs to Cool tier after 30 days").
**SDK available:** `setBlobTier()` exists if ever justified, but lifecycle policy is the correct approach.

### 43. Multi-tenant Support (Multiple Containers/Prefixes)

Per-entity or per-profile storage containers. GLPI is typically single-tenant — only relevant for MSP deployments.

### 44. Key Vault Integration for Credentials

Store Azure/S3 credentials in Azure Key Vault or AWS Secrets Manager instead of GLPI database. Highest security tier.
**Already in:** Security audit P3 backlog.

### 45. Content-Length Header in Proxy Mode

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
| "`Html::back()` without `return` allows `Config::set()` to execute" | False positive — GLPI 11's `Html::back()` has return type `never` (calls `exit` internally). Code after it is unreachable |
| "JS path in setup.php may be incorrect" | False positive — GLPI automatically resolves `js/url-rewriter.js` to `plugins/cloudstorage/public/js/url-rewriter.js` |
| "Add FOREIGN KEY constraint on `documents_id`" | GLPI convention — GLPI core does not use FK constraints by design. Orphan cleanup is handled by CLI Sync command (#11) |
| "Optimize `testConnection()` — `getBlobs()` slow on large containers" | Marginal — `getBlobs()` with `break` after first result is lazy-evaluated. Overhead is minimal |
| "Optimize `DocumentTracker` — `new self()` per call" | GLPI pattern — `CommonDBTM` instances are lightweight. Only relevant in 10k+ batch operations where I/O is the real bottleneck |
| "Create UploadPolicy/DownloadPolicy strategy classes" | Over-engineering — storage_mode and download_method each have 2 branches in `document.send.php`. Extracting Strategy classes for 2-branch if/else adds complexity without benefit |
| "JS supply chain: add package-lock.json + npm audit" | Not applicable — plugin has zero npm dependencies. `url-rewriter.js` is a single vanilla JS file with no build step |
| "Add correlation IDs to logs" | Over-engineering for GLPI plugin — logs already include `doc_id`, `filepath`, full stack trace. GLPI has no correlation ID infrastructure |
| "Introduce StorageBackend interface/adapter" | Already implemented as `StorageClientInterface` — reviewer based this on README only, didn't see the code |
| "SAS read-only + blob-scoped" | Already implemented — `BlobSasPermissions(read: true)` + SAS generated per-blob via `getBlobClient($remotePath)` |
| "Proxy: revalidate user permission before streaming" | Already implemented — `document.send.php` calls `$doc->canViewFile()` before any stream/redirect |
| "Content-Disposition/filename sanitization" | Already implemented — regex sanitization + RFC 6266 compliant Content-Disposition in `document.send.php` |
| "Terraform separation from plugin repo" | Already done — `terraform/` removed from plugin repo (commit `7d981fd`), lives in separate infrastructure repo |
