# Configuration Guide

After installation, go to **Setup > Plugins > Cloud Storage**.

## Provider

| Provider | Status | Description |
|----------|--------|-------------|
| **Azure Blob Storage** | Available | Microsoft Azure cloud storage |
| **AWS S3** | Planned (Phase 2) | Amazon S3 / S3-compatible storage |

## Azure Credentials

| Field | Description | Example |
|-------|-------------|---------|
| **Connection String** | Azure Storage Account connection string | `DefaultEndpointsProtocol=https;AccountName=...` |
| **Account Name** | Storage account name | `mystorage` |
| **Account Key** | Account access key (encrypted in DB) | `AbCdEf123...==` |
| **Container Name** | Blob container name for documents | `glpi-documents` |

> Credentials `azure_connection_string` and `azure_account_key` are stored encrypted in the GLPI database using the native `SECURED_CONFIGS` mechanism.

### How to Get Azure Credentials

See [Installation — Azure Storage Account](02-installation.md#azure-storage-account) for step-by-step instructions on creating and configuring your Azure Storage Account.

## Storage Mode

| Mode | Behavior | Recommended Use |
|------|----------|-----------------|
| **Cloud Primary** (default) | File is uploaded to cloud; local copies can be removed via `plugins:cloudstorage:migrate --delete-local` | Production - saves disk space |
| **Cloud Backup** | File is kept both locally and in cloud | Transition - full redundancy |

> **Note**: In Cloud Primary mode, local files are NOT deleted automatically during the HTTP request. Use `plugins:cloudstorage:migrate --delete-local` to remove local copies of confirmed cloud-stored files.

## Download Method

| Method | Behavior | Advantages |
|--------|----------|------------|
| **Redirect** (default) | Generates a temporary signed URL and redirects the browser directly to cloud storage | Fast, no GLPI overhead, ideal for large files |
| **Proxy** | GLPI downloads from cloud and streams to the user | Internal URLs stay hidden, more control |

## Additional Settings

| Field | Default | Description |
|-------|---------|-------------|
| **URL Expiry (minutes)** | 5 | Validity period for generated temporary URLs (SAS) |
| **Enabled** | No | Master switch - must be enabled for hooks to work |

## Test Connection

Use the **Test Connection** button on the configuration page to validate:
- Credentials are correct
- Container exists and is accessible
- Read permissions are OK
