# Changelog

All notable changes to the Cloud Storage plugin for GLPI will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-03-01

### Added
- `StorageClientInterface` and `StorageClientFactory` for multi-provider architecture
- Azure Blob Storage provider via `azure-oss/storage-blob-flysystem` SDK
- Provider-prefixed configuration keys (`azure_*`, `s3_*`)
- Provider selector in configuration page
- Auto-migration from legacy `azureblobstorage` plugin (table + config)
- Security: SAS URLs restricted to HTTPS-only via `SasProtocol::HttpsOnly`
- Security: `Referrer-Policy: no-referrer` on redirect and proxy responses
- Security: `X-Content-Type-Options: nosniff` and `X-Frame-Options: DENY` on proxy responses
- Security: improved `sanitizeErrorMessage()` with regex for unquoted base64 keys
- URL expiry default reduced from 10 to 5 minutes
- GLPI marketplace conformity (CSRF_COMPLIANT, XML metadata, LICENSE)

### Changed
- Renamed plugin from `azureblobstorage` to `cloudstorage`
- Replaced abandoned `microsoft/azure-storage-blob` SDK with `azure-oss/storage-blob-flysystem ^1.4`
- Refactored `AzureBlobClient` to implement `StorageClientInterface`
- Configuration context changed from `plugin:azureblobstorage` to `plugin:cloudstorage`
- Database table renamed from `glpi_plugin_azureblobstorage_documenttrackers` to `glpi_plugin_cloudstorage_documenttrackers`
- Column `azure_blob_name` renamed to `remote_path`

### Removed
- Direct dependency on abandoned Microsoft Azure SDK

## [1.0.0] - 2026-02-15

### Added
- Initial release as `azureblobstorage` plugin
- Azure Blob Storage integration for GLPI documents
- Two storage modes: Azure Primary and Azure Backup
- Two download methods: SAS Redirect and Proxy
- SHA1-based deduplication
- CLI commands: `migrate` (to Azure) and `migrate-local` (back to local)
- Configuration page with connection test
- Encrypted credential storage via GLPI SECURED_CONFIGS
- Path traversal prevention
- Credential sanitization in error messages
- Comprehensive logging to `files/_log/cloudstorage.log`

[2.0.0]: https://github.com/rafaelfariasbsb/glpi-cloud-storage/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/rafaelfariasbsb/glpi-cloud-storage/releases/tag/v1.0.0
