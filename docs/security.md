# Security

## Credential Storage

- **Connection string**, **account key**, **S3 access key**, and **S3 secret key** are stored encrypted in the GLPI database using the native `SECURED_CONFIGS` mechanism (sodium encryption)
- Credentials are never exposed in logs or error messages
- Error messages from cloud SDKs are sanitized via regex to redact keys before display or logging
- The `testConnection()` method also sanitizes error output to prevent credential leakage in the admin UI

## Temporary URLs (SAS)

- Download URLs are temporary **Shared Access Signature (SAS)** URLs
- They expire after the configured period (default: 5 minutes)
- SAS URLs grant **read-only** access to a single blob
- SAS tokens use **HTTPS-only** protocol (`SasProtocol::HttpsOnly`) to prevent token leakage over plaintext
- After expiry, the URL becomes invalid and returns 403
- The redirect response includes `Referrer-Policy: no-referrer` to prevent SAS token leakage via referrer headers

## Access Control

- The plugin's download endpoint replicates the same permission checks as GLPI core (`Document::canViewFile()`)
- Users can only download documents they have permission to view
- Anonymous access follows the same rules as GLPI's public FAQ documents

## Proxy Mode Security Headers

When using proxy download method, responses include:
- `X-Content-Type-Options: nosniff` — prevents MIME type sniffing
- `X-Frame-Options: DENY` — prevents framing
- `Referrer-Policy: no-referrer` — prevents referrer leakage
- `Cache-Control: private, must-revalidate` — prevents caching sensitive documents

## Path Traversal Protection

`Config::validateLocalPath()` validates all filepaths against `GLPI_DOC_DIR`:
- For existing files: checks `realpath()` is inside `GLPI_DOC_DIR`
- For new files: validates parent directory or checks for `..` traversal sequences
- Throws `RuntimeException` on any traversal attempt

## Input Validation

- Configuration form validates all POST values before saving:
  - `storage_mode` and `download_method` are checked against a whitelist
  - `url_expiry_minutes` is clamped to range 1–1440
  - `enabled` is restricted to `0` or `1`
- Invalid values are silently rejected (not saved to database)

## CSRF Protection

- All configuration forms use GLPI's native CSRF tokens
- Form submissions are validated automatically by GLPI 11's `CheckCsrfListener`

## Azure Container Configuration

- The Azure Blob container **must be configured as Private** (no anonymous access)
- Access is exclusively through authenticated API calls or SAS URLs
- Enable **Azure Storage firewalls** to restrict access by IP

## Uninstall Protection

- The plugin **refuses to uninstall** if documents are still tracked in cloud
- Administrators must first run the reverse migration CLI command
- This prevents accidental data loss when the tracking table is dropped

## Deduplication Safety

- When deduplicating uploads (same SHA1), the plugin verifies the blob **actually exists** before skipping
- This guards against external blob deletion
- If verification fails, the file is re-uploaded as a safety measure

## Storage Tier & Lifecycle Management

The plugin uploads all blobs using the **default access tier**. Azure provides native **Lifecycle Management policies** for automatic tier transitions:

| Tier | Use Case | Cost (LRS, East US) |
|------|----------|---------------------|
| **Hot** | Frequently accessed documents | ~$0.023/GB/mo |
| **Cool** | Infrequently accessed (>30 days) | ~$0.013/GB/mo |
| **Archive** | Rarely accessed (>180 days) | ~$0.002/GB/mo |

Configure policies in the Azure Portal under **Data management > Lifecycle management**.

## Recommendations

1. Use **Azure RBAC** (Storage Blob Data Contributor) for the Storage Account
2. Rotate storage account keys periodically (every 90 days)
3. Enable **Azure Storage logging** to audit blob access
4. Consider **Azure Private Endpoints** for VNet traffic
5. Enable **soft delete** on the Azure container (7-30 days)
6. Configure **Lifecycle Management policies** to reduce storage costs
7. Set **Minimum TLS 1.2** and **Secure transfer required** on the storage account
