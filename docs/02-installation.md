# Installation Guide

## Prerequisites

### GLPI Server

| Requirement | Minimum |
|-------------|---------|
| GLPI | 11.0 |
| PHP | 8.2 |
| Composer | 2.x |

### Azure Storage Account

Before installing the plugin, you need an Azure Storage Account with a Blob container.

#### 1. Create a Storage Account

Via Azure Portal:

1. Go to [Azure Portal](https://portal.azure.com) > **Storage accounts** > **Create**
2. Select your **Subscription** and **Resource group**
3. Choose a **Storage account name** (e.g., `glpidocsstorage`)
4. Select **Region** closest to your GLPI server
5. **Performance**: Standard (recommended for documents)
6. **Redundancy**: Choose based on your needs:
   - **LRS** (Locally Redundant) — cheapest, single datacenter
   - **GRS** (Geo-Redundant) — cross-region replication for disaster recovery
7. Click **Review + Create**

Via Azure CLI:

```bash
az storage account create \
  --name glpidocsstorage \
  --resource-group myResourceGroup \
  --location eastus \
  --sku Standard_LRS \
  --kind StorageV2
```

#### 2. Create a Blob Container

Via Azure Portal:

1. Go to your Storage Account > **Data storage > Containers**
2. Click **+ Container**
3. Name: `glpi-documents`
4. **Public access level**: Private (no anonymous access)

Via Azure CLI:

```bash
az storage container create \
  --name glpi-documents \
  --account-name glpidocsstorage \
  --auth-mode login
```

#### 3. Configure Access Permissions (RBAC)

| RBAC Role | Sufficient? | Why |
|-----------|-------------|-----|
| **Storage Blob Data Contributor** | **Yes (recommended)** | Read, write, and delete blobs |
| Storage Blob Data Reader | No | Read-only |
| Storage Account Contributor | No | Management plane only |

**For Access Key authentication** (current default), RBAC roles are not required — the access key grants full data plane access.

#### 4. Get Your Credentials

1. Go to **Storage Account > Security + networking > Access keys**
2. Copy:
   - **Storage account name**
   - **Key** (either key1 or key2)
   - **Connection string** (starts with `DefaultEndpointsProtocol=https;AccountName=...`)

> These are stored encrypted in the database via GLPI's `SECURED_CONFIGS` mechanism (sodium encryption).

#### 5. Recommended Security Settings

| Setting | Location | Recommendation |
|---------|----------|----------------|
| **Secure transfer** | Storage Account > Configuration | Enabled (HTTPS only) |
| **Minimum TLS** | Storage Account > Configuration | TLS 1.2 |
| **Soft delete** | Storage Account > Data protection | Enable for blobs (7-30 days) |
| **Versioning** | Storage Account > Data protection | Enable for point-in-time recovery |
| **Firewall** | Storage Account > Networking | Restrict to GLPI server IPs if possible |
| **Key rotation** | Security + networking > Access keys | Rotate periodically (every 90 days) |

---

## Plugin Installation

### Via Command Line (Recommended)

```bash
# 1. Clone or copy the plugin to GLPI plugins directory
git clone https://github.com/rafaelfariasbsb/glpi-cloud-storage.git /path/to/glpi/plugins/cloudstorage

# 2. Install PHP dependencies
cd /path/to/glpi/plugins/cloudstorage
composer install --no-dev

# 3. Install the plugin via GLPI console
php /path/to/glpi/bin/console plugin:install cloudstorage --username=glpi

# 4. Activate the plugin
php /path/to/glpi/bin/console plugin:activate cloudstorage
```

5. Go to **Setup > Plugins > Cloud Storage** and configure your credentials (see [Configuration Guide](03-configuration.md)).
6. Enable the plugin using the toggle on the configuration page.

### Via Web Interface

1. Copy/clone the plugin to `plugins/cloudstorage`
2. Run `composer install --no-dev` in the plugin directory
3. Go to **Setup > Plugins**
4. Find "Cloud Storage" in the list
5. Click **Install** then **Enable**
6. Configure credentials in **Setup > Plugins > Cloud Storage**

## Upgrading from v1.x (azureblobstorage)

See [Migration — Upgrading from v1.x](04-migration.md#upgrading-from-v1x-azureblobstorage) for details. The install hook handles all migration automatically.

## Uninstallation

> **Warning**: If using "Cloud Primary" mode, migrate documents back to local BEFORE uninstalling:
> ```bash
> php bin/console plugins:cloudstorage:migrate-local
> ```

```bash
# Deactivate the plugin
php bin/console plugin:deactivate cloudstorage

# Uninstall (removes tracker table and configuration)
php bin/console plugin:uninstall cloudstorage
```

The plugin **refuses to uninstall** if documents are still tracked in cloud storage.
