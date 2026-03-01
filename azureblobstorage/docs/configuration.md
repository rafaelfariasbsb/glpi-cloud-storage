# Configuration Guide

After installation, go to **Setup > Plugins > Azure Blob Storage**.

## Azure Credentials

| Field | Description | Example |
|-------|-------------|---------|
| **Connection String** | Azure Storage Account connection string | `DefaultEndpointsProtocol=https;AccountName=...` |
| **Account Name** | Storage account name | `mystorage` |
| **Account Key** | Account access key (encrypted in DB) | `AbCdEf123...==` |
| **Container Name** | Blob container name for documents | `glpi-documents` |

> Credentials `connection_string` and `account_key` are stored encrypted in the GLPI database using the native `SECURED_CONFIGS` mechanism.

### How to Get Azure Credentials

1. Go to the [Azure Portal](https://portal.azure.com)
2. Navigate to **Storage Accounts** > select (or create) your account
3. Under **Security + networking > Access keys**, copy the **Connection string** and **Key**
4. Under **Data storage > Containers**, create a container (e.g., `glpi-documents`)

## Storage Mode

| Mode | Behavior | Recommended Use |
|------|----------|-----------------|
| **Azure Primary** (default) | File is uploaded to Azure and local copy is deleted | Production - saves disk space |
| **Azure Backup** | File is kept both locally and in Azure | Transition - full redundancy |

## Download Method

| Method | Behavior | Advantages |
|--------|----------|------------|
| **SAS Redirect** (default) | Generates a temporary signed URL and redirects the browser directly to Azure | Fast, no GLPI overhead, ideal for large files |
| **Proxy** | GLPI downloads from Azure and relays to the user | Internal URLs stay hidden, more control |

## Additional Settings

| Field | Default | Description |
|-------|---------|-------------|
| **SAS Expiry (minutes)** | 10 | Validity period for generated SAS URLs |
| **Enabled** | Yes | Master switch - disabling reverts GLPI to local behavior |

## Test Connection

Use the **Test Connection** button on the configuration page to validate:
- Credentials are correct
- Container exists and is accessible
- Read/write permissions are OK
