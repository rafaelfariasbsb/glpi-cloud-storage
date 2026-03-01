# GLPI Cloud Storage Plugin

Store GLPI documents and attachments in cloud storage (Azure Blob Storage, AWS S3 planned) instead of the local filesystem.

> **Zero core modifications**: This plugin works 100% via GLPI's native hook system. No GLPI core files are modified, added, or removed.

## Why?

GLPI stores all documents locally in `/files/`. This creates challenges in enterprise and cloud environments:

- **Scalability** - Local disk is limited and expensive to scale
- **Availability** - Server failure means documents are lost
- **Backup** - Requires separate filesystem backup
- **Multi-instance** - Can't share documents across GLPI instances
- **Containers** - Local storage is ephemeral in Docker/Kubernetes

This plugin redirects storage to cloud: unlimited capacity, high availability, geo-redundancy.

## Features

- Upload documents to cloud storage automatically via GLPI hooks
- Download via temporary URL redirect (fast) or proxy mode (private)
- SHA1-based deduplication (same as GLPI core)
- Encrypted credential storage using GLPI's native `SECURED_CONFIGS`
- CLI migration commands for existing documents
- Graceful fallback — cloud failures never block GLPI operations
- Path traversal protection and credential sanitization
- Configuration UI integrated into GLPI's plugin settings

## Supported Providers

| Provider | Status | Package |
|----------|--------|---------|
| **Azure Blob Storage** | Available | `azure-oss/storage-blob-flysystem` |
| **AWS S3** | Planned (Phase 2) | `league/flysystem-aws-s3-v3` |

## Requirements

| Requirement | Minimum |
|-------------|---------|
| GLPI | 11.0 |
| PHP | 8.2 |
| Cloud | Azure Storage Account (or Azurite for dev) |

## Quick Start

```bash
# 1. Clone to GLPI plugins directory
git clone https://github.com/rafaelfariasbsb/glpi-cloud-storage.git /path/to/glpi/plugins/cloudstorage

# 2. Install PHP dependencies
cd /path/to/glpi/plugins/cloudstorage
composer install --no-dev

# 3. Install and activate
php /path/to/glpi/bin/console plugin:install cloudstorage --username=glpi
php /path/to/glpi/bin/console plugin:activate cloudstorage
```

4. Go to **Setup > Plugins > Cloud Storage**, configure credentials, and enable the plugin.

## Configuration

| Setting | Default | Description |
|---------|---------|-------------|
| **Provider** | Azure | `azure` (Azure Blob Storage) |
| **Storage Mode** | Cloud Primary | `cloud_primary` (upload to cloud, clean local via CLI) or `cloud_backup` (keep both) |
| **Download Method** | Redirect | `redirect` (302 to temporary URL) or `proxy` (stream through GLPI) |
| **URL Expiry** | 5 min | Validity period for temporary download URLs |

## Migration

```bash
# Migrate existing documents to cloud
php bin/console plugins:cloudstorage:migrate --batch-size=100

# Dry run (simulate)
php bin/console plugins:cloudstorage:migrate --dry-run

# Reverse: download from cloud back to local
php bin/console plugins:cloudstorage:migrate-local
```

## Documentation

- [Documentation Index](docs/index.md)
- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Architecture](docs/architecture.md)
- [Security](docs/security.md)
- [Migration](docs/migration.md)
- [Development Guide](docs/development-guide.md)
- [FAQ](docs/faq.md)

## Project Structure

```
cloudstorage/
├── setup.php                       # Plugin registration and hooks
├── hook.php                        # Install/uninstall (DB + migration)
├── composer.json                   # PHP dependencies
├── front/
│   ├── config.php                  # Configuration page
│   ├── config.form.php             # Configuration form handler
│   └── document.send.php           # Download proxy/redirect endpoint
├── src/
│   ├── StorageClientInterface.php  # Cloud storage contract (9 methods)
│   ├── StorageClientFactory.php    # Singleton factory for providers
│   ├── AzureBlobClient.php         # Azure implementation (Flysystem + SAS)
│   ├── Config.php                  # Plugin configuration management
│   ├── DocumentTracker.php         # Document tracking table (CommonDBTM)
│   ├── DocumentHook.php            # GLPI hook handlers (add/update/purge)
│   └── Console/
│       ├── MigrateCommand.php      # CLI: migrate to cloud
│       └── MigrateLocalCommand.php # CLI: migrate back to local
├── public/js/
│   └── url-rewriter.js             # Frontend URL rewriting
├── templates/
│   └── config.html.twig            # Configuration UI template
└── docs/                           # Full documentation
```

## License

GPL-3.0-or-later
