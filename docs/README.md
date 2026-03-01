# Azure Blob Storage for GLPI

Store GLPI documents in Microsoft Azure Blob Storage instead of the local filesystem.

> **Zero core modifications**: This plugin works 100% via GLPI's native hook system. No GLPI core files are modified, added, or removed. The plugin can be installed and uninstalled without any impact on the original GLPI.

## The Problem

GLPI stores all documents (ticket attachments, contracts, knowledge base articles, etc.) on the server's local filesystem (`/files/`). This creates challenges in enterprise environments:

- **Scalability**: local disk is limited and expensive to scale
- **Availability**: if the server goes down, documents become inaccessible
- **Backup**: requires separate filesystem backup beyond the database
- **Multi-server**: impossible to share documents between multiple GLPI instances

This plugin redirects storage to Azure Blob Storage, offering virtually unlimited storage, high availability, geographic redundancy, and native Microsoft ecosystem integration.

## Requirements

| Requirement | Minimum Version |
|-------------|----------------|
| GLPI | 11.0 |
| PHP | 8.2 |
| Azure Account | Storage Account with Blob Service enabled |
| Composer | Installed on the server |

### Required PHP Extensions
- `ext-curl`
- `ext-json`
- `ext-openssl`

## Quick Start

```bash
# 1. Copy plugin to GLPI plugins directory
cp -r azureblobstorage /path/to/glpi/plugins/

# 2. Install PHP dependencies
cd /path/to/glpi/plugins/azureblobstorage
composer install --no-dev

# 3. Install and enable the plugin
php /path/to/glpi/bin/console plugin:install azureblobstorage -u glpi
php /path/to/glpi/bin/console plugin:enable azureblobstorage
```

4. Go to **Setup > Plugins > Azure Blob Storage** and configure your Azure credentials.

## Documentation

- [Installation Guide](installation.md)
- [Configuration Guide](configuration.md)
- [Architecture & Technical Details](architecture.md)
- [Migration Guide](migration.md)
- [Security](security.md)
- [FAQ](faq.md)

## License

GPL-3.0-or-later
