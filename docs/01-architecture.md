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
│  │     cloudstorage plugin    │  │
│  │                            │  │
│  │  StorageClientInterface    │  │
│  │  ├── AzureBlobClient       │  │
│  │  └── (S3Client — Phase 2)  │  │
│  │  StorageClientFactory      │  │
│  │  DocumentHook              │  │
│  │  DocumentTracker           │  │
│  │  Config                    │  │
│  └────────────┬───────────────┘  │
│               │                  │
└───────────────┼──────────────────┘
                │
    ┌───────────▼───────────┐
    │  Cloud Storage        │
    │  Azure Blob / S3      │
    │  (or Azurite locally) │
    └───────────────────────┘
```

## Core Classes

| Class | Responsibility | Pattern |
|-------|---------------|---------|
| `StorageClientInterface` | Defines cloud storage operations contract (9 methods) | Interface |
| `StorageClientFactory` | Creates/caches storage client by provider | Singleton Factory |
| `AzureBlobClient` | Azure Blob Storage via Flysystem + SAS URL generation | Implementation |
| `DocumentHook` | Handles ITEM_ADD, ITEM_UPDATE, PRE_ITEM_PURGE hooks | Static event handler |
| `Config` | Plugin configuration CRUD (wraps GLPI Config API) | Static utility with per-request cache |
| `DocumentTracker` | ORM for `glpi_plugin_cloudstorage_documenttrackers` table | CommonDBTM (GLPI ORM) |
| `MigrateCommand` | Batch migration: local → cloud | Symfony Console Command |
| `MigrateLocalCommand` | Reverse migration: cloud → local | Symfony Console Command |

## How It Works (Without Modifying GLPI Core)

This plugin does NOT modify any GLPI core files. It uses GLPI's native hook system.

| Action | Does it? | Why |
|--------|----------|-----|
| Modify GLPI core files | **NO** | 100% hook-based |
| Alter GLPI database tables | **NO** | Uses its own table |
| Change login/auth flow | **NO** | Only intercepts document operations |
| Prevent normal operation if uninstalled | **NO** | Local documents keep working |

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
| 1. Validates local path |
| 2. Checks SHA1 dedup    |
| 3. Uploads to cloud     |
| 4. Tracks in DB         |
+-------------------------+
  Local copy is kept (cleaned via CLI)
```

## Download Flow

```
User clicks download
        |
        v
+----------------------------+
| url-rewriter.js            |
| Rewrites URL to            |
| /plugins/cloudstorage/     |
| front/document.send.php    |
+----------+-----------------+
           |
           v
+----------------------------+
| Plugin endpoint:           |
| 1. Validates permissions   |
| 2. Queries tracker         |
| 3a. Redirect: 302 ->      |
|     temporary SAS URL      |
| 3b. Proxy: stream content  |
+----------------------------+
```

The endpoint supports both `?docid=ID` (standard downloads) and `?file=PATH` (inline images in rich text).

## Delete Flow

```
Admin purges document
        |
        v
+-----------------------------+
| Hook PRE_ITEM_PURGE         |
| 1. Removes tracker FIRST    |
|    (prevents race w/ dedup) |
| 2. Checks SHA1 references   |
| 3. If last ref: deletes     |
|    blob from cloud           |
+-----------------------------+
```

## Deduplication

GLPI uses SHA1 to deduplicate files. The plugin maintains this logic:

- On upload: if a blob with the same SHA1 already exists, it **verifies the blob actually exists** before skipping upload
- On delete: only removes the blob if no other document references the same SHA1

## Database Schema

```sql
CREATE TABLE `glpi_plugin_cloudstorage_documenttrackers` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `documents_id` int unsigned NOT NULL DEFAULT 0,
    `filepath` varchar(255) NOT NULL DEFAULT '',
    `sha1sum` char(40) NOT NULL DEFAULT '',
    `remote_path` varchar(512) NOT NULL DEFAULT '',
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
| `Hooks::CSRF_COMPLIANT` | - | CSRF compliance declaration (marketplace requirement) |
| `Hooks::ITEM_ADD` | Document | Upload file to cloud after creation |
| `Hooks::ITEM_UPDATE` | Document | Upload new version if file changed |
| `Hooks::PRE_ITEM_PURGE` | Document | Delete blob before DB purge |
| `Hooks::CONFIG_PAGE` | - | Plugin configuration page |
| `Hooks::SECURED_CONFIGS` | - | Encrypt sensitive config fields |
| `Hooks::ADD_JAVASCRIPT` | - | URL rewriter script |

## Dependencies (Composer)

| Package | Version | Purpose |
|---------|---------|---------|
| `league/flysystem` | ^3.28 | Filesystem abstraction |
| `azure-oss/storage-blob-flysystem` | ^1.4 | Azure Blob adapter for Flysystem |
| `league/flysystem-aws-s3-v3` | ^3.0 | AWS S3 adapter (Phase 2) |
| `aws/aws-sdk-php` | ^3.295 | AWS SDK (Phase 2) |

## Dependency Tree

```
DocumentHook
  ├── Config (checks enabled, storage mode)
  ├── StorageClientFactory → StorageClientInterface
  ├── DocumentTracker (track/check dedup)
  └── GLPI: Document, countElementsInTable, GLPI_DOC_DIR

StorageClientFactory (Singleton)
  ├── Config (gets provider + connection params)
  └── AzureBlobClient (or S3Client)

AzureBlobClient (implements StorageClientInterface)
  ├── League\Flysystem\Filesystem
  ├── AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter
  ├── AzureOss\Storage\Blob\BlobServiceClient
  └── AzureOss\Storage\Blob\Sas\BlobSasBuilder
```

## Error Handling

The plugin follows **graceful degradation** — cloud failures never block GLPI core operations. See [FAQ — Error Handling](06-faq.md#error-handling) for scenario details.

### Logging (Two-Tier)

Every catch block logs at both levels:

| Target | Method | Content |
|--------|--------|---------|
| PHP error log | `trigger_error(E_USER_WARNING)` | One-line with `[CloudStorage]` prefix |
| `files/_log/cloudstorage.log` | `Toolbox::logInFile()` | Structured detail + stack traces |

### Credential Sanitization

`AzureBlobClient::sanitizeErrorMessage()` redacts:
- `AccountKey=***REDACTED***`
- `SharedAccessSignature=***REDACTED***`
- `sig=***REDACTED***`
- Long base64 sequences

## Testing Strategy (Planned — Phase 3)

- PHPUnit 11.5 + Paratest
- Base class: `DbTestCase` (transaction rollback)
- vfsStream for filesystem mocking

Planned test classes:

| Class | Coverage |
|-------|----------|
| `DocumentHookTest` | Upload on add, update, delete, dedup, modes |
| `DocumentTrackerTest` | track(), isTracked(), sha1Exists(), countBySha1() |
| `ConfigTest` | isEnabled(), isCloudPrimary(), cache invalidation |
| `AzureBlobClientTest` | upload(), download(), generateTemporaryUrl(), testConnection() |
