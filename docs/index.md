# Azure Blob Storage for GLPI — Documentation

## Overview

- **Type:** GLPI Plugin (hook-based, zero core modifications)
- **Language:** PHP >= 8.2
- **Repository:** [github.com/rafaelfariasbsb/glpi-cloud-storage](https://github.com/rafaelfariasbsb/glpi-cloud-storage)
- **License:** GPL-3.0-or-later

## Documentation

### For Users

- [Installation](installation.md) — CLI install, Docker Compose, web interface, uninstall
- [Configuration](configuration.md) — Azure credentials, storage modes, download methods
- [Migration](migration.md) — CLI migration commands and recommended strategy
- [Security](security.md) — Credential encryption, SAS URLs, access control
- [FAQ](faq.md) — Common questions, troubleshooting, error handling

### For Developers

- [Architecture](architecture.md) — System design, hook flows, DB schema, dependencies, Terraform infrastructure
- [Development Guide](development-guide.md) — Local setup, project conventions, testing, Terraform setup, source tree

## Quick Start

```bash
# Clone and install
git clone https://github.com/rafaelfariasbsb/glpi-cloud-storage.git /path/to/glpi/plugins/azureblobstorage
cd /path/to/glpi/plugins/azureblobstorage
composer install --no-dev

# Enable
php /path/to/glpi/bin/console plugin:install azureblobstorage -u glpi
php /path/to/glpi/bin/console plugin:enable azureblobstorage
```

Then go to **Setup > Plugins > Azure Blob Storage** to configure your Azure credentials.
