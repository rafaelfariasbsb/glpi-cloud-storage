# GLPI Cloud Storage - Azure Blob Storage Plugin

Store GLPI documents and attachments in Microsoft Azure Blob Storage instead of the local filesystem.

> **Zero core modifications**: This plugin works 100% via GLPI's native hook system. No GLPI core files are modified, added, or removed. Install and uninstall without any impact on your GLPI instance.

## Why?

GLPI stores all documents locally in `/files/`. This creates challenges in enterprise and cloud environments:

- **Scalability** - Local disk is limited and expensive to scale
- **Availability** - Server failure means documents are lost
- **Backup** - Requires separate filesystem backup
- **Multi-instance** - Can't share documents across GLPI instances
- **Containers** - Local storage is ephemeral in Docker/Kubernetes

This plugin redirects storage to Azure Blob Storage: unlimited capacity, high availability, geo-redundancy, and native Microsoft integration.

## Features

- Upload documents to Azure Blob Storage automatically via GLPI hooks
- Download via SAS URL redirect (fast, no server overhead) or proxy mode
- SHA1-based deduplication (same as GLPI core)
- Encrypted credential storage using GLPI's native `SECURED_CONFIGS`
- CLI migration commands for existing documents
- Graceful fallback - Azure failures never block GLPI operations
- Configuration UI integrated into GLPI's plugin settings

## Requirements

| Requirement | Minimum |
|-------------|---------|
| GLPI | 11.0 |
| PHP | 8.2 |
| Azure | Storage Account with Blob Service |

## Quick Start

```bash
# 1. Clone or copy to GLPI plugins directory
git clone https://github.com/rafaelfariasbsb/glpi-cloud-storage.git /path/to/glpi/plugins/azureblobstorage
# Or copy manually:
# cp -r glpi-cloud-storage /path/to/glpi/plugins/azureblobstorage

# 2. Install PHP dependencies
cd /path/to/glpi/plugins/azureblobstorage
composer install --no-dev

# 3. Install and enable
php /path/to/glpi/bin/console plugin:install azureblobstorage -u glpi
php /path/to/glpi/bin/console plugin:enable azureblobstorage
```

4. Go to **Setup > Plugins > Azure Blob Storage** and configure your credentials.

## Configuration

| Setting | Default | Description |
|---------|---------|-------------|
| **Storage Mode** | Azure Primary | `azure_primary` (delete local after upload) or `azure_backup` (keep both) |
| **Download Method** | SAS Redirect | `sas_redirect` (302 to temporary Azure URL) or `proxy` (stream through GLPI) |
| **SAS Expiry** | 10 min | Validity period for temporary download URLs |

## Migration

```bash
# Migrate existing documents to Azure
php bin/console plugins:azureblobstorage:migrate --batch-size=100

# Dry run (simulate)
php bin/console plugins:azureblobstorage:migrate --dry-run

# Migrate and remove local copies
php bin/console plugins:azureblobstorage:migrate --delete-local

# Reverse: download from Azure back to local
php bin/console plugins:azureblobstorage:migrate-local
```

## Local Development

A `docker-compose.yml` is included for local development using [Azurite](https://learn.microsoft.com/en-us/azure/storage/common/storage-use-azurite) (official Microsoft Azure Storage emulator):

```bash
docker compose up -d
```

This starts:
- **GLPI** on http://localhost:8080
- **MariaDB** with persistent data
- **Azurite** (Azure Storage emulator) with a pre-created `glpi-documents` container

The plugin is auto-mounted into GLPI's plugins directory. Azurite uses well-known development credentials (no setup needed).

## Infrastructure

A Terraform configuration is included in [`terraform/`](terraform/) to deploy the full stack on Azure:

- Azure Container Apps (GLPI + MariaDB)
- Azure Blob Storage (for documents)
- Log Analytics Workspace

```bash
cd terraform
cp terraform.tfvars.example terraform.tfvars
# Edit terraform.tfvars with your values
terraform init && terraform plan && terraform apply
```

## Documentation

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Architecture](docs/architecture.md)
- [Migration](docs/migration.md)
- [Security](docs/security.md)
- [FAQ](docs/faq.md)

## Project Structure

```
glpi-cloud-storage/
├── setup.php                  # Plugin registration and hooks
├── hook.php                   # Install/uninstall (DB table creation)
├── composer.json              # PHP dependencies
├── docker-compose.yml         # Local dev (GLPI + MariaDB + Azurite)
├── front/
│   ├── config.php             # Configuration page
│   ├── config.form.php        # Configuration form handler
│   └── document.send.php      # Download proxy endpoint
├── src/
│   ├── AzureBlobClient.php    # Flysystem + Azure SDK wrapper
│   ├── Config.php             # Plugin configuration management
│   ├── DocumentTracker.php    # Document tracking table (CommonDBTM)
│   ├── DocumentHook.php       # GLPI hook handlers
│   └── Console/
│       ├── MigrateCommand.php      # CLI: migrate to Azure
│       └── MigrateLocalCommand.php # CLI: migrate back to local
├── templates/
│   └── config.html.twig       # Configuration UI template
├── js/
│   └── url-rewriter.js        # Frontend URL rewriting
├── terraform/                 # Azure infrastructure (modular)
│   ├── main.tf
│   ├── variables.tf
│   ├── outputs.tf
│   ├── versions.tf
│   ├── backend.tf
│   └── modules/
│       ├── networking/
│       ├── storage/
│       ├── database/
│       └── glpi/
└── docs/                      # Full documentation
```

## License

GPL-3.0-or-later
