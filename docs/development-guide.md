# Development Guide

## Local Development Environment

The project includes a Docker Compose setup with GLPI, MariaDB, and Azurite (official Microsoft Azure Storage emulator).

### Prerequisites

| Requirement | Version |
|-------------|---------|
| Docker | Latest |
| Docker Compose | v2+ |
| Composer | 2.x |
| PHP (optional, for IDE) | >= 8.2 |

#### Required PHP Extensions (when running locally)

- `ext-curl`
- `ext-json`
- `ext-openssl`

### Setup

```bash
# 1. Clone the repository
git clone https://github.com/rafaelfariasbsb/glpi-cloud-storage.git
cd glpi-cloud-storage

# 2. Install PHP dependencies
composer install

# 3. Start the environment
docker compose up -d

# 4. Wait for services (~30s). Azurite init container auto-creates the blob container.

# 5. Install and enable the plugin
docker compose exec glpi php bin/console plugin:install azureblobstorage -u glpi
docker compose exec glpi php bin/console plugin:enable azureblobstorage
```

Access GLPI at **http://localhost:8080** (admin: `glpi` / `glpi`).

### Services

| Service | Port | Description |
|---------|------|-------------|
| **glpi** | `localhost:8080` | GLPI with plugin mounted at `/var/www/glpi/plugins/azureblobstorage` |
| **db** | 3306 (internal) | MariaDB 11.8 (`glpi/glpi/glpi`) |
| **azurite** | `localhost:10000` | Azure Blob Storage emulator |
| **azurite-init** | — | One-shot: creates `glpi-documents` container on startup |

### Azurite Credentials (Dev Only)

These are [well-known Azurite default credentials](https://learn.microsoft.com/en-us/azure/storage/common/storage-use-azurite#well-known-storage-account-and-key), safe for local development:

| Field | Value |
|-------|-------|
| Account Name | `devstoreaccount1` |
| Account Key | `Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==` |
| Container | `glpi-documents` |
| Connection String | `DefaultEndpointsProtocol=http;AccountName=devstoreaccount1;AccountKey=Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==;BlobEndpoint=http://azurite:10000/devstoreaccount1;` |

Enter these in **Setup > Plugins > Azure Blob Storage** after enabling the plugin.

---

## Project Structure

```
azureblobstorage/
├── setup.php                  # Plugin registration, hooks, version
├── hook.php                   # Install/uninstall (DB table management)
├── composer.json              # PHP dependencies
├── docker-compose.yml         # Local dev environment (GLPI + MariaDB + Azurite)
├── front/
│   ├── config.php             # Config page entry point
│   ├── config.form.php        # Config form POST handler (with validation)
│   └── document.send.php      # Download proxy endpoint
├── src/
│   ├── AzureBlobClient.php    # Azure SDK wrapper (Flysystem + SAS + retry + timeouts)
│   ├── Config.php             # Plugin configuration (CRUD + cache + decrypt)
│   ├── DocumentHook.php       # GLPI hook handlers (add/update/purge)
│   ├── DocumentTracker.php    # Tracking table ORM (CommonDBTM)
│   └── Console/
│       ├── MigrateCommand.php      # CLI: migrate local → Azure
│       └── MigrateLocalCommand.php # CLI: migrate Azure → local
├── templates/
│   └── config.html.twig       # Configuration UI (Twig template)
├── public/js/
│   └── url-rewriter.js        # Frontend URL rewriting for downloads
└── docs/                      # Documentation
```

---

## Code Conventions

### Namespace

All classes use `GlpiPlugin\Azureblobstorage\` with PSR-4 autoloading:

```
src/AzureBlobClient.php    → GlpiPlugin\Azureblobstorage\AzureBlobClient
src/Config.php             → GlpiPlugin\Azureblobstorage\Config
src/Console/MigrateCommand → GlpiPlugin\Azureblobstorage\Console\MigrateCommand
```

### GLPI Plugin Conventions

- `setup.php`: Plugin metadata, version, hook registration (`plugin_init_azureblobstorage()`)
- `hook.php`: Install/uninstall functions (`plugin_azureblobstorage_install()`, `plugin_azureblobstorage_uninstall()`)
- `front/`: Web-accessible endpoints (config pages, form handlers)
- `templates/`: Twig templates (prefixed with `@azureblobstorage/`)
- `public/js/`: JavaScript files injected via `Hooks::ADD_JAVASCRIPT`

### Logging (Two-Tier Strategy)

Every catch block must log at both levels:

```php
// 1. PHP error log — one-line summary (GLPI standard pattern)
trigger_error(
    sprintf('[AzureBlobStorage] Upload failed for document %d: %s', $docId, $e->getMessage()),
    E_USER_WARNING
);

// 2. Dedicated log file — structured detail + stack trace
\Toolbox::logInFile('azureblobstorage', sprintf(
    "UPLOAD FAILED | doc_id=%d | filepath=%s | error=%s\n%s\n",
    $docId,
    $filepath,
    $e->getMessage(),
    $e->getTraceAsString()
));
```

Log message format: `OPERATION_TYPE | key=value | key=value\nstack trace\n`

Log file: `files/_log/azureblobstorage.log`

### Error Handling Rules

1. **Never block GLPI core operations** — catch exceptions in hooks, don't re-throw
2. **Never silently swallow errors** — every catch block must log at both levels
3. **Notify admins on upload failure** — use `Session::addMessageAfterRedirect()` with `WARNING`
4. **Sanitize credentials** — use `sanitizeErrorMessage()` before any external-facing output

### Azure SDK Patterns

- `BlobRestProxy::createBlobService()` with `http` options for Guzzle timeouts (`connect_timeout: 5`, `timeout: 30`)
- `RetryMiddlewareFactory` with exponential backoff (3 retries, 1s base, retries on 408/5xx + connection errors)
- Always use `writeStream()` (not `write()`) — streams avoid `memory_limit` issues
- SAS URL generation uses `BlobSharedAccessSignatureHelper` — requires `account_key`
- The SDK auto-chunks uploads >32MB into 4MB blocks via `createBlockBlobByMultipleUploadAsync`

---

## Key Design Decisions

### Stream-Based Upload (Memory Safe)

```php
// CORRECT — stream, O(1) memory regardless of file size
$stream = fopen($localPath, 'rb');
$this->filesystem->writeStream($blobPath, $stream);

// WRONG — loads entire file in memory, risks memory_limit
$contents = file_get_contents($localPath);
$this->filesystem->write($blobPath, $contents);
```

### Deferred Local Deletion

Local files are deleted at PHP shutdown (not immediately after upload) via `register_shutdown_function()`. This is because GLPI's post-processing (`convertTagToImage`, thumbnail generation) may still need the local file after the Document hook fires.

### Singleton AzureBlobClient

Avoids creating multiple Azure SDK connections per request. Reset via `resetInstance()` when config changes.

### Retry Configuration

```
Strategy:  Exponential backoff
Retries:   3 attempts
Backoff:   1s → 2s → 4s
Triggers:  HTTP 408, 500, 502, 503, 504, connection errors
Timeouts:  connect 5s, request 30s
Worst-case (Azure fully down): ~22s before giving up
```

---

## Testing

### Framework

- PHPUnit 11.5 + Paratest (parallel execution)
- Base class: `Glpi\Tests\DbTestCase` (transaction rollback per test)
- Filesystem mocking: `vfsStream`
- Azure mocking: mock `AzureBlobClient` to avoid Azurite dependency in unit tests

### Running Tests

```bash
# Inside GLPI container
docker compose exec glpi bash

# Run plugin tests (from GLPI root)
vendor/bin/phpunit plugins/azureblobstorage/tests/

# Or via Makefile
make phpunit
make paratest p=8
```

### Test Classes (Planned)

| Class | Coverage |
|-------|----------|
| `DocumentHookTest` | onItemAdd, onItemUpdate, onPreItemPurge, dedup, azure_primary vs backup, error logging |
| `DocumentTrackerTest` | track, isInAzure, sha1ExistsInAzure, countBySha1, removeByDocumentId |
| `ConfigTest` | getPluginConfig, isEnabled, isAzurePrimary, cache invalidation, decrypt failure |
| `AzureBlobClientTest` | upload, download, delete, exists, generateSasUrl, parseBlobEndpoint, retry behavior |
| `MigrateCommandTest` | batch processing, dedup, --dry-run, --delete-local, error recovery |

---

## Debugging

### Log Files

| Log | Location | Contents |
|-----|----------|----------|
| **Plugin log** | `files/_log/azureblobstorage.log` | All plugin operations with stack traces |
| PHP errors | `files/_log/php-errors.log` | PHP warnings from `trigger_error()` |
| GLPI log | `files/_log/glpi.log` | General GLPI application log |

### Browsing Azurite Blobs

Use [Azure Storage Explorer](https://azure.microsoft.com/products/storage/storage-explorer/) or the Azure CLI:

```bash
az storage blob list \
  --container-name glpi-documents \
  --connection-string "DefaultEndpointsProtocol=http;AccountName=devstoreaccount1;AccountKey=Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==;BlobEndpoint=http://localhost:10000/devstoreaccount1;" \
  --output table
```

### Useful SQL Queries

```sql
-- Documents tracked in Azure
SELECT dt.*, d.filename, d.filepath, d.sha1sum
FROM glpi_plugin_azureblobstorage_documenttrackers dt
JOIN glpi_documents d ON d.id = dt.documents_id;

-- Orphaned trackers (document deleted but tracker remains)
SELECT dt.*
FROM glpi_plugin_azureblobstorage_documenttrackers dt
LEFT JOIN glpi_documents d ON d.id = dt.documents_id
WHERE d.id IS NULL;

-- Documents NOT yet in Azure
SELECT d.id, d.filename, d.filepath
FROM glpi_documents d
LEFT JOIN glpi_plugin_azureblobstorage_documenttrackers dt ON dt.documents_id = d.id
WHERE dt.id IS NULL AND d.filepath != '';
```

## Build for Production

```bash
# Install production dependencies only (no dev tools)
composer install --no-dev --optimize-autoloader

# The vendor/ directory must be included in the deployment
# (GLPI does not have a central Composer setup for plugins)
```
