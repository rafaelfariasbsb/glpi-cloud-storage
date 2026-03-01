# GLPI Cloud Storage Plugins

A collection of GLPI plugins for storing documents and attachments in cloud storage providers instead of the local filesystem.

> **Zero core modifications**: These plugins work 100% via GLPI's native hook system. No GLPI core files are modified, added, or removed.

## Available Plugins

| Plugin | Provider | Status |
|--------|----------|--------|
| [azureblobstorage](azureblobstorage/) | Microsoft Azure Blob Storage | Available |
| awss3storage | Amazon S3 | Planned |

## Why?

GLPI stores all documents locally in `/files/`. This creates challenges in enterprise and cloud environments:

- **Scalability** - Local disk is limited and expensive to scale
- **Availability** - Server failure means documents are lost
- **Backup** - Requires separate filesystem backup
- **Multi-instance** - Can't share documents across GLPI instances
- **Containers** - Local storage is ephemeral in Docker/Kubernetes

These plugins redirect storage to cloud providers: unlimited capacity, high availability, geo-redundancy, and native cloud integration.

## Architecture

All plugins share the same approach:

1. **Upload**: GLPI core writes files locally. The plugin hook uploads to cloud and optionally removes the local copy.
2. **Download**: Plugin endpoint serves files via temporary signed URLs (redirect) or proxy streaming.
3. **Delete**: Plugin hook removes cloud copy when document is purged, respecting SHA1 deduplication.

Each plugin is a standalone GLPI plugin that can be installed independently.

## Requirements

| Requirement | Minimum |
|-------------|---------|
| GLPI | 11.0 |
| PHP | 8.2 |

## License

GPL-3.0-or-later
