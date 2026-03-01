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

# 5. Install composer inside container
docker exec --user root glpi-app bash -c "cd /tmp && php -r \"copy('https://getcomposer.org/installer', 'composer-setup.php');\" && php composer-setup.php --install-dir=/usr/local/bin --filename=composer && rm composer-setup.php"
docker exec --user root glpi-app composer install -d /var/www/glpi/plugins/cloudstorage

# 6. Install and activate the plugin
docker exec glpi-app php /var/www/glpi/bin/console plugin:install cloudstorage --username=glpi
docker exec glpi-app php /var/www/glpi/bin/console plugin:activate cloudstorage
```

Access GLPI at **http://localhost:8080** (admin: `glpi` / `glpi`).

### Services

| Service | Port | Description |
|---------|------|-------------|
| **glpi** | `localhost:8080` | GLPI with plugin mounted at `/var/www/glpi/plugins/cloudstorage` |
| **db** | 3306 (internal) | MariaDB 11.8 (`glpi/glpi/glpi`) |
| **azurite** | `localhost:10000` | Azure Blob Storage emulator |
| **azurite-init** | — | One-shot: creates `glpi-documents` container on startup |

### Docker Volumes

| Volume | Mount | Purpose |
|--------|-------|---------|
| `glpi_files` | `/var/www/glpi/files` | Persist GLPI documents across container restarts |
| `db_data` | `/var/lib/mysql` | Persist MariaDB data |
| `azurite_data` | `/data` | Persist Azurite blobs |

### Azurite Credentials (Dev Only)

These are [well-known Azurite default credentials](https://learn.microsoft.com/en-us/azure/storage/common/storage-use-azurite#well-known-storage-account-and-key):

| Field | Value |
|-------|-------|
| Account Name | `devstoreaccount1` |
| Account Key | `Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==` |
| Container | `glpi-documents` |
| Connection String | `DefaultEndpointsProtocol=http;AccountName=devstoreaccount1;AccountKey=Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==;BlobEndpoint=http://azurite:10000/devstoreaccount1;` |

---

## Project Structure

```
cloudstorage/
├── setup.php                  # Plugin registration, hooks, version
├── hook.php                   # Install/uninstall (DB table management, migration)
├── composer.json              # PHP dependencies
├── front/
│   ├── config.php             # Config page entry point
│   ├── config.form.php        # Config form POST handler (with validation)
│   └── document.send.php      # Download proxy/redirect endpoint
├── src/
│   ├── StorageClientInterface.php  # Cloud storage operations contract
│   ├── StorageClientFactory.php    # Singleton factory for providers
│   ├── AzureBlobClient.php         # Azure implementation (Flysystem + SAS)
│   ├── Config.php                  # Plugin configuration (CRUD + cache + decrypt)
│   ├── DocumentHook.php            # GLPI hook handlers (add/update/purge)
│   ├── DocumentTracker.php         # Tracking table ORM (CommonDBTM)
│   └── Console/
│       ├── MigrateCommand.php      # CLI: migrate local → cloud
│       └── MigrateLocalCommand.php # CLI: migrate cloud → local
├── public/js/
│   └── url-rewriter.js        # Frontend URL rewriting for downloads
├── templates/
│   └── config.html.twig       # Configuration UI (Twig template)
└── docs/                      # Documentation
```

---

## Code Conventions

### Namespace

All classes use `GlpiPlugin\Cloudstorage\` with PSR-4 autoloading:

```
src/AzureBlobClient.php    → GlpiPlugin\Cloudstorage\AzureBlobClient
src/Config.php             → GlpiPlugin\Cloudstorage\Config
src/Console/MigrateCommand → GlpiPlugin\Cloudstorage\Console\MigrateCommand
```

### GLPI Plugin Conventions

- `setup.php`: Plugin metadata, version, hook registration (`plugin_init_cloudstorage()`)
- `hook.php`: Install/uninstall functions (`plugin_cloudstorage_install()`, `plugin_cloudstorage_uninstall()`)
- `front/`: Web-accessible endpoints (config pages, form handlers)
- `templates/`: Twig templates (prefixed with `@cloudstorage/`)
- `public/js/`: JavaScript files injected via `Hooks::ADD_JAVASCRIPT`

### Logging (Two-Tier Strategy)

Every catch block must log at both levels:

```php
// 1. PHP error log — one-line summary
trigger_error(
    sprintf('[CloudStorage] Upload failed for document %d: %s', $docId, $e->getMessage()),
    E_USER_WARNING
);

// 2. Dedicated log file — structured detail + stack trace
\Toolbox::logInFile('cloudstorage', sprintf(
    "UPLOAD FAILED | doc_id=%d | filepath=%s | error=%s\n%s\n",
    $docId,
    $filepath,
    $e->getMessage(),
    $e->getTraceAsString()
));
```

Log file: `files/_log/cloudstorage.log`

### Error Handling Rules

1. **Never block GLPI core operations** — catch exceptions in hooks, don't re-throw
2. **Never silently swallow errors** — every catch block must log at both levels
3. **Notify admins on upload failure** — use `Session::addMessageAfterRedirect()` with `WARNING`
4. **Sanitize credentials** — use `sanitizeErrorMessage()` before any external-facing output

### Stream-Based Upload (Memory Safe)

```php
// CORRECT — stream, O(1) memory regardless of file size
$stream = fopen($localPath, 'rb');
$this->filesystem->writeStream($blobPath, $stream);

// WRONG — loads entire file in memory, risks memory_limit
$contents = file_get_contents($localPath);
$this->filesystem->write($blobPath, $contents);
```

---

## Testing

### Framework

- PHPUnit 11.5 + Paratest (parallel execution)
- Base class: `Glpi\Tests\DbTestCase` (transaction rollback per test)
- Filesystem mocking: `vfsStream`
- Cloud mocking: mock `StorageClientInterface` to avoid Azurite dependency in unit tests

### Running Tests

```bash
# Inside GLPI container
docker exec glpi-app bash

# Run plugin tests (from GLPI root)
vendor/bin/phpunit plugins/cloudstorage/tests/

# Or via Makefile
make phpunit
make paratest p=8
```

---

## Debugging

### Log Files

| Log | Location | Contents |
|-----|----------|----------|
| **Plugin log** | `files/_log/cloudstorage.log` | All plugin operations with stack traces |
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
-- Documents tracked in cloud
SELECT dt.*, d.filename, d.filepath, d.sha1sum
FROM glpi_plugin_cloudstorage_documenttrackers dt
JOIN glpi_documents d ON d.id = dt.documents_id;

-- Orphaned trackers (document deleted but tracker remains)
SELECT dt.*
FROM glpi_plugin_cloudstorage_documenttrackers dt
LEFT JOIN glpi_documents d ON d.id = dt.documents_id
WHERE d.id IS NULL;

-- Documents NOT yet in cloud
SELECT d.id, d.filename, d.filepath
FROM glpi_documents d
LEFT JOIN glpi_plugin_cloudstorage_documenttrackers dt ON dt.documents_id = d.id
WHERE dt.id IS NULL AND d.filepath != '';
```

## Build for Production

```bash
# Install production dependencies only
composer install --no-dev --optimize-autoloader

# The vendor/ directory must be included in the deployment
```
