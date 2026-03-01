# Migration Guide

## Migrating Existing Documents to Azure

Use the CLI command to upload documents already stored locally to Azure Blob Storage.

### Dry Run (Simulate)

```bash
php bin/console plugins:azureblobstorage:migrate --dry-run
```

### Migrate with Default Settings

```bash
php bin/console plugins:azureblobstorage:migrate --batch-size=100
```

### Migrate and Remove Local Copies

```bash
php bin/console plugins:azureblobstorage:migrate --delete-local
```

### Combined Options

```bash
php bin/console plugins:azureblobstorage:migrate --batch-size=50 --delete-local
```

## Command Options

| Option | Default | Description |
|--------|---------|-------------|
| `--batch-size` | 100 | Number of documents processed per batch |
| `--delete-local` | false | Remove local file after successful upload |
| `--dry-run` | false | Simulate without making changes |

## Reverse Migration (Azure to Local)

To download documents from Azure back to the local filesystem:

```bash
php bin/console plugins:azureblobstorage:migrate-local
```

Options:
- `--batch-size=100` - Documents per batch
- `--dry-run` - Simulate without changes

## Recommended Migration Strategy

1. **Start in Azure Backup mode** (keeps both local and Azure copies)
2. Run migration: `plugins:azureblobstorage:migrate`
3. Verify documents are accessible in both locations
4. Switch to **Azure Primary mode** in configuration
5. Run migration again with `--delete-local` to clean up local copies

This approach ensures zero downtime and provides a safe rollback path.
