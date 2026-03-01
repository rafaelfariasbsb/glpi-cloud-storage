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

## Advantages of Azure Blob Storage

| Feature | Local File Server | Azure Blob Storage |
|---|---|---|
| **Capacity** | Limited by disk/RAID | Virtually unlimited |
| **Redundancy** | Manual (RAID + offsite backup) | Built-in LRS, GRS, RA-GRS |
| **Availability** | Single point of failure | 99.9% SLA (99.99% RA-GRS) |
| **Geo-replication** | Complex and expensive | Native (GRS replicates across regions) |
| **Backup** | Separate infrastructure needed | Soft delete + versioning + immutability |
| **Maintenance** | OS patches, disk replacements, monitoring | Zero - fully managed by Microsoft |
| **Scaling** | Buy hardware, plan capacity | Automatic, pay-per-use |
| **Containers/K8s** | Persistent volumes, NFS mounts | Native SDK - no mount needed |
| **Multi-instance** | NFS/SMB share (latency, locking issues) | Single storage, multiple GLPI instances |
| **Security** | Filesystem ACLs, firewall | SAS tokens, RBAC, encryption at rest, private endpoints |
| **Disaster Recovery** | Tape/offsite replication | Cross-region replication in minutes |

### Cost Comparison

Estimated monthly cost for storing GLPI documents (storage only, East US region):

| Volume | File Server (on-prem)¹ | Azure Blob Hot (LRS)² | Azure Blob Hot (GRS)² | Azure Blob Cool (LRS)² |
|---|---|---|---|---|
| **100 GB** | ~$150/mo | ~$2.30/mo | ~$4.60/mo | ~$1.30/mo |
| **500 GB** | ~$170/mo | ~$11.50/mo | ~$23.00/mo | ~$6.50/mo |
| **1 TB** | ~$200/mo | ~$23.00/mo | ~$46.00/mo | ~$13.00/mo |
| **5 TB** | ~$350/mo | ~$115.00/mo | ~$230.00/mo | ~$65.00/mo |

> ¹ **On-prem estimate** includes amortized hardware ($3-5K server over 36 months), Windows Server license, backup software, partial IT admin time, power/cooling. Does not include rack space, network, or disaster recovery infrastructure.
>
> ² **Azure estimate** based on [published pay-as-you-go pricing](https://azure.microsoft.com/en-us/pricing/details/storage/blobs/) (~$0.023/GB Hot LRS, ~$0.046/GB Hot GRS, ~$0.013/GB Cool LRS). Operations and egress costs are additional but negligible for typical GLPI usage (documents are written once, read occasionally). Ingress (uploads) is free.
>
> **Tip:** Most GLPI documents (old tickets, closed attachments) are rarely accessed. Using the **Cool** tier or [lifecycle management policies](https://learn.microsoft.com/en-us/azure/storage/blobs/lifecycle-management-overview) can reduce costs by 40-60%.

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
glpi-cloud-storage/
├── setup.php                  # Plugin registration and hooks
├── hook.php                   # Install/uninstall (DB table creation)
├── composer.json              # PHP dependencies
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
├── public/js/
│   └── url-rewriter.js        # Frontend URL rewriting
└── docs/                      # Full documentation
```

## License

GPL-3.0-or-later
