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

## Supported Providers

| Provider | Status |
|----------|--------|
| **Azure Blob Storage** | Available |
| **AWS S3** | Planned (Phase 2) |

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

Then go to **Setup > Plugins > Cloud Storage**, configure your cloud credentials, and enable the plugin.

## Documentation

Full documentation is available in the [docs/](docs/index.md) directory:

- [Installation](docs/02-installation.md) — Prerequisites, Azure setup, CLI/web install
- [Configuration](docs/03-configuration.md) — Credentials, storage modes, download methods
- [Migration](docs/04-migration.md) — Migrate existing documents to/from cloud
- [Security](docs/05-security.md) — Encryption, SAS URLs, access control
- [FAQ](docs/06-faq.md) — Common questions and troubleshooting
- [Architecture](docs/01-architecture.md) — System design, hooks, DB schema
- [Development Guide](docs/07-development-guide.md) — Setup, conventions, testing

## License

GPL-3.0-or-later
