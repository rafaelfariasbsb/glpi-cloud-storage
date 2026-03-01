# Architecture & Technical Details

## How It Works (Without Modifying GLPI Core)

This plugin does NOT modify any GLPI core files. It uses GLPI's native hook system to intercept document lifecycle events.

| Action | Does it? | Why |
|--------|----------|-----|
| Modify GLPI core files | **NO** | 100% hook-based |
| Alter GLPI database tables | **NO** | Uses its own table (`glpi_plugin_azureblobstorage_documents`) |
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

- On upload: if a blob with the same SHA1 already exists in Azure, it skips the upload
- On delete: only removes the blob if no other document references the same SHA1

## Database Schema

```sql
CREATE TABLE `glpi_plugin_azureblobstorage_documents` (
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

## Dependencies

| Package | Purpose |
|---------|---------|
| `league/flysystem` | Filesystem abstraction |
| `league/flysystem-azure-blob-storage` | Azure Blob adapter for Flysystem |
| `microsoft/azure-storage-blob` | Native Azure SDK (for SAS URL generation) |
