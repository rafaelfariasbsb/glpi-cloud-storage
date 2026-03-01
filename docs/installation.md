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

The identity accessing the storage account needs the correct RBAC role. The required role depends on the authentication method:

| RBAC Role | Sufficient? | Why |
|-----------|-------------|-----|
| **Storage Blob Data Contributor** | **Yes (recommended)** | Read, write, and delete blobs — exactly what the plugin needs |
| Storage Blob Data Reader | No | Read-only — plugin also needs write and delete |
| Storage Account Contributor | No | Management plane only — does not grant data plane access |
| Owner / Contributor | No | Management plane — does not include blob data operations |

**For Access Key authentication** (current default), RBAC roles are not required for the plugin to function — the access key grants full data plane access. However, assigning RBAC roles is still recommended as preparation for future Managed Identity support and for other tools accessing the same storage.

**For Service Principals** (Azure AD app registrations):

```bash
az role assignment create \
  --role "Storage Blob Data Contributor" \
  --assignee <app-id-or-object-id> \
  --scope /subscriptions/<sub-id>/resourceGroups/<rg>/providers/Microsoft.Storage/storageAccounts/<account>
```

**For future Managed Identity support** (see [Security](security.md)):

```bash
az role assignment create \
  --role "Storage Blob Data Contributor" \
  --assignee <managed-identity-principal-id> \
  --scope /subscriptions/<sub-id>/resourceGroups/<rg>/providers/Microsoft.Storage/storageAccounts/<account>
```

#### 4. Get Your Credentials

1. Go to **Storage Account > Security + networking > Access keys**
2. Copy:
   - **Storage account name** (e.g., `glpidocsstorage`)
   - **Key** (either key1 or key2)
   - **Connection string** (starts with `DefaultEndpointsProtocol=https;AccountName=...`)

> These will be entered in the GLPI plugin configuration page. They are stored encrypted in the database via GLPI's `SECURED_CONFIGS` mechanism (sodium encryption).

#### 5. Recommended Security Settings

| Setting | Location | Recommendation |
|---------|----------|----------------|
| **Secure transfer** | Storage Account > Configuration | Enabled (HTTPS only) |
| **Minimum TLS** | Storage Account > Configuration | TLS 1.2 |
| **Soft delete** | Storage Account > Data protection | Enable for blobs (7-30 days) |
| **Versioning** | Storage Account > Data protection | Enable for point-in-time recovery |
| **Firewall** | Storage Account > Networking | Restrict to GLPI server IPs if possible |
| **Private endpoint** | Storage Account > Networking | Use if GLPI runs in Azure VNet |
| **Key rotation** | Security + networking > Access keys | Rotate periodically (every 90 days) |

---

## Plugin Installation

### Via Command Line (Recommended)

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

5. Go to **Setup > Plugins > Azure Blob Storage** and configure your Azure credentials (see [Configuration Guide](configuration.md)).

### Via Web Interface

1. Copy/clone the plugin to `plugins/azureblobstorage`
2. Run `composer install --no-dev` in the plugin directory
3. Go to **Setup > Plugins**
4. Find "Azure Blob Storage" in the list
5. Click **Install** then **Enable**
6. Configure credentials in **Setup > Plugins > Azure Blob Storage**

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
