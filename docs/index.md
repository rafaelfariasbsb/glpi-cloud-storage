# Cloud Storage for GLPI — Documentation

## Overview

- **Type:** GLPI Plugin (hook-based, zero core modifications)
- **Language:** PHP >= 8.2
- **Version:** 2.0.0
- **Repository:** [github.com/rafaelfariasbsb/glpi-cloud-storage](https://github.com/rafaelfariasbsb/glpi-cloud-storage)
- **License:** GPL-3.0-or-later
- **Providers:** Azure Blob Storage (S3 planned for Phase 2)

## Documentation

### For Users

- [Installation](installation.md) — CLI install, web interface, uninstall
- [Configuration](configuration.md) — Cloud credentials, storage modes, download methods
- [Migration](migration.md) — CLI migration commands and recommended strategy
- [Security](security.md) — Credential encryption, SAS URLs, access control
- [FAQ](faq.md) — Common questions, troubleshooting, error handling

### For Developers

- [Architecture](architecture.md) — System design, hook flows, DB schema, dependencies
- [Development Guide](development-guide.md) — Setup, project conventions, testing, source tree

### References

- [Migration from azure-oss](migration-azure-oss.md) — Dependency migration notes
- [Security Audit (2026-03-01)](security-audit-2026-03-01.md) — Security audit report

## Quick Start

```bash
# Clone and install
git clone https://github.com/rafaelfariasbsb/glpi-cloud-storage.git /path/to/glpi/plugins/cloudstorage
cd /path/to/glpi/plugins/cloudstorage
composer install --no-dev

# Enable
php /path/to/glpi/bin/console plugin:install cloudstorage --username=glpi
php /path/to/glpi/bin/console plugin:activate cloudstorage
```

Then go to **Setup > Plugins > Cloud Storage** to configure your cloud credentials and enable the plugin.
