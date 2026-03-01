# Architecture & Technical Details

## System Context

```
┌──────────────────────────────────┐
│          GLPI Application        │
│                                  │
│  ┌─────────┐    ┌─────────────┐  │
│  │Document  │───▸│Plugin Hooks │  │
│  │Lifecycle │    │(setup.php)  │  │
│  └─────────┘    └──────┬──────┘  │
│                        │         │
│  ┌─────────────────────▼──────┐  │
│  │   azureblobstorage plugin  │  │
│  │                            │  │
│  │  DocumentHook              │  │
│  │  AzureBlobClient           │  │
│  │  DocumentTracker           │  │
│  │  Config                    │  │
│  └────────────┬───────────────┘  │
│               │                  │
└───────────────┼──────────────────┘
                │
    ┌───────────▼───────────┐
    │  Azure Blob Storage   │
    │  (or Azurite locally) │
    └───────────────────────┘
```

## Core Classes

| Class | Responsibility | Pattern |
|-------|---------------|---------|
| `DocumentHook` | Handles ITEM_ADD, ITEM_UPDATE, PRE_ITEM_PURGE hooks | Static event handler |
| `AzureBlobClient` | Azure SDK wrapper (Flysystem + SAS URL generation) | Singleton |
| `Config` | Plugin configuration CRUD (wraps GLPI Config API) | Static utility with per-request cache |
| `DocumentTracker` | ORM for `glpi_plugin_azureblobstorage_documenttrackers` table | CommonDBTM (GLPI ORM) |
| `MigrateCommand` | Batch migration: local → Azure | Symfony Console Command |
| `MigrateLocalCommand` | Reverse migration: Azure → local | Symfony Console Command |

## How It Works (Without Modifying GLPI Core)

This plugin does NOT modify any GLPI core files. It uses GLPI's native hook system to intercept document lifecycle events.

| Action | Does it? | Why |
|--------|----------|-----|
| Modify GLPI core files | **NO** | 100% hook-based |
| Alter GLPI database tables | **NO** | Uses its own table (`glpi_plugin_azureblobstorage_documenttrackers`) |
| Change login/auth flow | **NO** | Only intercepts document operations |
| Interfere with pictures/inventories | **NO** | Only acts on managed documents (`?docid=`) |
| Prevent normal operation if uninstalled | **NO** | Local documents keep working normally |
| Send data to third parties | **NO** | Exclusive communication with the configured Storage Account |

## Upload Flow

```
User attaches file in GLPI
        |
        v
+-------------------------+
| GLPI Core:              |
| prepareInputForAdd()    |
| Writes to GLPI_DOC_DIR/ |
| Calculates SHA1         |
| Inserts into DB         |
+----------+--------------+
           | Hook ITEM_ADD
           v
+-------------------------+
| Plugin:                 |
| DocumentHook::onItemAdd |
| 1. Reads local file     |
| 2. Uploads to Azure     |
| 3. Tracks in DB         |
| 4. Deletes local copy*  |
+-------------------------+
  * Only in Azure Primary mode
```

## Download Flow

```
User clicks download
        |
        v
+----------------------------+
| url-rewriter.js            |
| Rewrites URL to            |
| /plugins/azureblobstorage/ |
| front/document.send.php    |
+----------+-----------------+
           |
           v
+----------------------------+
| Plugin endpoint:           |
| 1. Validates permissions   |
| 2. Queries tracker         |
| 3a. SAS Redirect: 302 ->  |
|     temporary Azure URL    |
| 3b. Proxy: stream content  |
+----------------------------+
```

## Delete Flow

```
Admin purges document
        |
        v
+-----------------------------+
| Hook PRE_ITEM_PURGE         |
| 1. Checks if in Azure      |
| 2. Checks SHA1 dedup       |
| 3. If last ref: deletes    |
|    blob from Azure          |
| 4. Removes tracker record   |
+----------+------------------+
           |
           v
+-----------------------------+
| GLPI Core:                  |
| cleanDBonPurge()            |
| (tries unlink local - OK   |
|  if already removed)        |
+-----------------------------+
```

## Deduplication

GLPI uses SHA1 to deduplicate files: two documents with the same content point to the same physical file. The plugin maintains this same logic:

- On upload: if a blob with the same SHA1 already exists in Azure, it **verifies the blob actually exists** before skipping the upload (guards against external deletion)
- On delete: only removes the blob if no other document references the same SHA1

## Configuration Caching

`Config::getPluginConfig()` caches decrypted configuration values in a static property for the duration of the request. This avoids repeated DB queries when multiple hooks check `isEnabled()`, `isAzurePrimary()`, etc. in the same request cycle. The cache is automatically invalidated when `Config::set()` is called.

## Input Validation

The configuration form handler (`front/config.form.php`) validates all POST values before saving:

- `storage_mode`: must be `azure_primary` or `azure_backup`
- `download_method`: must be `sas_redirect` or `proxy`
- `sas_expiry_minutes`: clamped to range 1–1440
- `enabled`: must be `0` or `1`

Invalid values are silently rejected (not saved).

## Uninstall Safety

The plugin **refuses to uninstall** (`return false`) if there are still documents tracked in Azure. The administrator must first run `php bin/console plugins:azureblobstorage:migrate-local` to download all documents back to local storage before uninstalling.

## Database Schema

```sql
CREATE TABLE `glpi_plugin_azureblobstorage_documenttrackers` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `documents_id` int unsigned NOT NULL DEFAULT 0,
    `filepath` varchar(255) NOT NULL DEFAULT '',
    `sha1sum` char(40) NOT NULL DEFAULT '',
    `azure_blob_name` varchar(512) NOT NULL DEFAULT '',
    `uploaded_at` timestamp NULL DEFAULT NULL,
    `file_size` bigint unsigned DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `documents_id` (`documents_id`),
    KEY `filepath` (`filepath`),
    KEY `sha1sum` (`sha1sum`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## GLPI Hooks Used

| Hook | Target | Purpose |
|------|--------|---------|
| `Hooks::ITEM_ADD` | Document | Upload file to Azure after creation |
| `Hooks::ITEM_UPDATE` | Document | Upload new version if file changed |
| `Hooks::PRE_ITEM_PURGE` | Document | Delete blob from Azure before DB purge |
| `Hooks::CONFIG_PAGE` | - | Plugin configuration page |
| `Hooks::SECURED_CONFIGS` | - | Encrypt connection_string and account_key |
| `Hooks::ADD_JAVASCRIPT` | - | URL rewriter script |

## URL Rewriting

The `url-rewriter.js` script dynamically derives its base path from its own `<script src>` URL, supporting GLPI installations in subdirectories (e.g., `/glpi/plugins/...`). It falls back to `/plugins/azureblobstorage/front/document.send.php` if detection fails.

## CLI Commands

### Migrate to Azure
```bash
php bin/console plugins:azureblobstorage:migrate [--batch-size=100] [--delete-local] [--dry-run]
```

### Migrate back to Local
```bash
php bin/console plugins:azureblobstorage:migrate-local [--batch-size=100] [--delete-azure] [--dry-run]
```

The reverse migration verifies SHA1 integrity of existing local files before removing tracker records. If a local file has a different SHA1 than expected, it downloads the correct version from Azure.

## Dependency Tree

```
DocumentHook
  ├── Config (checks enabled, storage mode)
  ├── AzureBlobClient (upload/delete)
  ├── DocumentTracker (track/check dedup)
  └── GLPI: Document, countElementsInTable, GLPI_DOC_DIR

AzureBlobClient (Singleton)
  ├── Config (gets connection params)
  ├── League\Flysystem\Filesystem
  ├── League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter
  ├── MicrosoftAzure\Storage\Blob\BlobRestProxy
  └── MicrosoftAzure\Storage\Blob\BlobSharedAccessSignatureHelper

DocumentTracker (extends CommonDBTM)
  └── GLPI: CommonDBTM, countElementsInTable

Config
  └── GLPI: \Config (getConfigurationValues, setConfigurationValues)

MigrateCommand / MigrateLocalCommand
  ├── GLPI: Glpi\Console\AbstractCommand
  ├── AzureBlobClient
  ├── DocumentTracker
  └── Symfony\Component\Console\*
```

## Dependencies (Composer)

| Package | Purpose |
|---------|---------|
| `league/flysystem` | Filesystem abstraction |
| `league/flysystem-azure-blob-storage` | Azure Blob adapter for Flysystem |
| `microsoft/azure-storage-blob` | Native Azure SDK (for SAS URL generation) |

## Error Handling Strategy

The plugin follows a **graceful degradation** pattern:

| Scenario | Behavior |
|----------|----------|
| Azure unavailable during upload | File stays local, error logged. Upload can be retried via migration CLI. |
| Azure unavailable during download | Returns error. In backup mode, falls back to local file. |
| Azure unavailable during delete | Document purge proceeds normally. Orphan blob cleaned later. |
| Invalid credentials | "Test Connection" button alerts. Uploads fail gracefully. |

All errors are logged via `trigger_error()` with `E_USER_WARNING` — they never prevent GLPI core operations from completing.

## Testing Strategy

- PHPUnit 11.5 + Paratest (via GLPI test infrastructure)
- Plugin tests extend `DbTestCase` (transaction rollback per test)
- vfsStream available for filesystem mocking

| Class | What to Test |
|-------|-------------|
| `DocumentHook` | Upload on add, update on file change, delete on purge, deduplication, azure_primary vs backup mode |
| `DocumentTracker` | track(), isInAzure(), sha1ExistsInAzure(), countBySha1(), removeByDocumentId() |
| `Config` | getPluginConfig(), isEnabled(), isAzurePrimary(), getDownloadMethod(), cache invalidation |
| `AzureBlobClient` | upload(), download(), generateSasUrl(), testConnection(), parseBlobEndpoint() |
| `MigrateCommand` | Batch processing, dedup handling, --dry-run, --delete-local, error recovery |
