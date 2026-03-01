# Security

## Credential Storage

- **Connection string** and **account key** are stored encrypted in the GLPI database using the native `SECURED_CONFIGS` mechanism (AES encryption)
- Credentials are never exposed in logs or error messages

## SAS URLs

- Download URLs are temporary **Shared Access Signature (SAS)** URLs
- They expire after the configured period (default: 10 minutes)
- SAS URLs grant read-only access to a single blob
- After expiry, the URL becomes invalid and returns 403

## Access Control

- The plugin's download endpoint replicates the same permission checks as GLPI core (`Document::canViewFile()`)
- Users can only download documents they have permission to view
- Anonymous access follows the same rules as GLPI's public FAQ documents

## CSRF Protection

- All configuration forms use GLPI's native CSRF tokens
- Form submissions are validated with `Session::checkCSRF()`

## Azure Container Configuration

- The Azure Blob container **must be configured as Private** (no anonymous access)
- Access is exclusively through authenticated API calls or SAS URLs
- Enable **Azure Storage firewalls** if you want to restrict access by IP

## Recommendations

1. Use **Azure RBAC** (Role-Based Access Control) for the Storage Account
2. Rotate storage account keys periodically
3. Enable **Azure Storage logging** to audit blob access
4. Consider using **Azure Private Endpoints** for traffic that stays within your VNet
5. Enable **soft delete** on the Azure container for accidental deletion recovery
