# Migration Guide

## Migrating Existing Documents to Cloud

Use the CLI command to upload documents already stored locally to cloud storage.

### Dry Run (Simulate)

```bash
php bin/console plugins:cloudstorage:migrate --dry-run
```

### Migrate with Default Settings

```bash
php bin/console plugins:cloudstorage:migrate --batch-size=100
```

### Migrate and Remove Local Copies

```bash
php bin/console plugins:cloudstorage:migrate --delete-local
```

## Command Options

| Option | Default | Description |
|--------|---------|-------------|
| `--batch-size` | 100 | Number of documents processed per batch |
| `--delete-local` | false | Remove local file after successful upload |
| `--dry-run` | false | Simulate without making changes |

## Reverse Migration (Cloud to Local)

To download documents from cloud back to the local filesystem:

```bash
php bin/console plugins:cloudstorage:migrate-local
```

Options:
- `--batch-size=100` — Documents per batch
- `--dry-run` — Simulate without changes

## Recommended Migration Strategy

1. **Start in Cloud Backup mode** (keeps both local and cloud copies)
2. Run migration: `plugins:cloudstorage:migrate`
3. Verify documents are accessible in both locations
4. Switch to **Cloud Primary mode** in configuration
5. Run migration again with `--delete-local` to clean up local copies

This approach ensures zero downtime and provides a safe rollback path.

## Upgrading from v1.x (azureblobstorage)

When installing the v2.0 `cloudstorage` plugin over the old `azureblobstorage` plugin, the install hook automatically:

1. Renames table: `glpi_plugin_azureblobstorage_documenttrackers` → `glpi_plugin_cloudstorage_documenttrackers`
2. Renames column: `azure_blob_name` → `remote_path`
3. Migrates config keys with new prefixes (e.g., `connection_string` → `azure_connection_string`)
4. Updates enum values (e.g., `azure_primary` → `cloud_primary`, `sas_redirect` → `redirect`)

No manual intervention required.
