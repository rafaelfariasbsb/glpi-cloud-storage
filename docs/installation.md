# Installation Guide

## Via Command Line (Recommended)

```bash
# 1. Clone or copy the plugin to GLPI plugins directory
git clone https://github.com/rafaelfariasbsb/glpi-cloud-storage.git /path/to/glpi/plugins/azureblobstorage
# Alternative: symlink (ideal for development)
# ln -s /path/to/glpi-cloud-storage /path/to/glpi/plugins/azureblobstorage

# 2. Install PHP dependencies
cd /path/to/glpi/plugins/azureblobstorage
composer install --no-dev

# 3. Install the plugin via GLPI console
php /path/to/glpi/bin/console plugin:install azureblobstorage -u glpi

# 4. Enable the plugin
php /path/to/glpi/bin/console plugin:enable azureblobstorage
```

## Via Docker Compose (Local Development)

A `docker-compose.yml` is included with GLPI, MariaDB, and [Azurite](https://learn.microsoft.com/en-us/azure/storage/common/storage-use-azurite) (official Azure Storage emulator):

```bash
git clone https://github.com/rafaelfariasbsb/glpi-cloud-storage.git
cd glpi-cloud-storage

# Start all services
docker compose up -d

# Install and enable the plugin
docker compose exec glpi php bin/console plugin:install azureblobstorage -u glpi
docker compose exec glpi php bin/console plugin:enable azureblobstorage
```

- **GLPI**: http://localhost:8080
- **Azurite**: Blob service on port 10000 (well-known dev credentials, no setup needed)
- The plugin is auto-mounted into GLPI's plugins directory
- A `glpi-documents` container is auto-created on startup

## Via Web Interface

1. Go to **Setup > Plugins**
2. Find "Azure Blob Storage" in the list
3. Click **Install** then **Enable**

## Uninstallation

> **Warning**: If using "Azure Primary" mode, migrate documents back to local BEFORE uninstalling:
> ```bash
> php bin/console plugins:azureblobstorage:migrate-local
> ```

```bash
# Disable the plugin
php bin/console plugin:disable azureblobstorage

# Uninstall (removes tracker table and configuration)
php bin/console plugin:uninstall azureblobstorage
```
