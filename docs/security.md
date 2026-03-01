# Security

## Credential Storage

- **Connection string** and **account key** are stored encrypted in the GLPI database using the native `SECURED_CONFIGS` mechanism (AES encryption)
- Credentials are never exposed in logs or error messages
- Error messages from Azure SDK are sanitized via regex to redact base64-encoded keys before display or logging
- The `testConnection()` method also sanitizes error output to prevent credential leakage in the admin UI

## SAS URLs

- Download URLs are temporary **Shared Access Signature (SAS)** URLs
- They expire after the configured period (default: 10 minutes)
- SAS URLs grant read-only access to a single blob
- After expiry, the URL becomes invalid and returns 403

## Access Control

- The plugin's download endpoint replicates the same permission checks as GLPI core (`Document::canViewFile()`)
- Users can only download documents they have permission to view
- Anonymous access follows the same rules as GLPI's public FAQ documents

## Input Validation

- Configuration form validates all POST values before saving:
  - `storage_mode` and `download_method` are checked against a whitelist of allowed values
  - `sas_expiry_minutes` is clamped to range 1–1440
  - `enabled` is restricted to `0` or `1`
- Invalid values are silently rejected (not saved to database)

## CSRF Protection

- All configuration forms use GLPI's native CSRF tokens
- Form submissions are validated automatically by GLPI 11's `CheckCsrfListener`

## Azure Container Configuration

- The Azure Blob container **must be configured as Private** (no anonymous access)
- Access is exclusively through authenticated API calls or SAS URLs
- Enable **Azure Storage firewalls** if you want to restrict access by IP

## Uninstall Protection

- The plugin **refuses to uninstall** if documents are still tracked in Azure
- Administrators must first run the reverse migration CLI command to download all documents back to local storage
- This prevents accidental data loss when the tracking table is dropped

## Deduplication Safety

- When deduplicating uploads (same SHA1), the plugin verifies the blob **actually exists** in Azure before skipping the upload
- This guards against external blob deletion (e.g., Azure retention policies, manual deletion in portal)
- If verification fails, the file is re-uploaded as a safety measure

## SAS URL Validation

- SAS expiry is enforced to a minimum of 1 minute to prevent immediately-expired URLs
- The `testConnection()` method uses paginated blob listing (`maxResults=1`) to prevent memory issues on large containers

## Recommendations

1. Use **Azure RBAC** (Role-Based Access Control) for the Storage Account
2. Rotate storage account keys periodically
3. Enable **Azure Storage logging** to audit blob access
4. Consider using **Azure Private Endpoints** for traffic that stays within your VNet
5. Enable **soft delete** on the Azure container for accidental deletion recovery
