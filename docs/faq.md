# FAQ

## General

**Q: What happens if I disable the plugin?**
A: Documents stored only in Azure (Primary mode) will be temporarily inaccessible. Documents in Backup mode remain accessible from local. Re-enable the plugin or run reverse migration.

**Q: Can I use it with multiple GLPI instances?**
A: Yes! All instances can point to the same Azure container, as long as they share the same database.

**Q: Does the plugin work with GLPI Cloud?**
A: No, only with self-hosted installations where you have access to the plugins directory.

**Q: Can I use AWS S3 instead of Azure?**
A: An AWS S3 plugin is planned as a separate repository (e.g., `glpi-s3-storage`). The architecture uses Flysystem, which makes adding new cloud adapters straightforward.

## Costs

**Q: How much does Azure Blob Storage cost?**
A: Azure charges per GB stored and per operation. For most GLPI installations, the cost is cents per month. Check the [Azure pricing calculator](https://azure.microsoft.com/pricing/calculator/).

**Q: Which Azure Blob tier should I use?**
A: **Hot** for frequently accessed documents, **Cool** for rarely accessed documents (cheaper). Configure this in the Azure Portal, not in the plugin.

## Troubleshooting

**Q: Upload to Azure failed, what happens?**
A: The local file is preserved, the admin sees a warning message after redirect, and the error is logged with full stack trace to `files/_log/azureblobstorage.log`. The document creation in GLPI is NOT blocked. The plugin retries automatically up to 3 times with exponential backoff (1s → 2s → 4s) before giving up. You can re-upload later using the migration command.

**Q: Where are the plugin logs?**
A: Two locations:
- **PHP error log** — one-line summaries prefixed with `[AzureBlobStorage]`
- **`files/_log/azureblobstorage.log`** — detailed logs with document IDs, blob paths, error messages, and full PHP stack traces. This is the primary log for diagnosing issues.

**Q: Downloads are returning 404 for some documents.**
A: Check if those documents were migrated to Azure. Run `plugins:azureblobstorage:migrate` to upload any missing documents. Also verify the plugin is enabled. Check `files/_log/azureblobstorage.log` for download errors.

**Q: The "Test Connection" button fails.**
A: Verify your connection string and account key in the Azure Portal. Ensure the container exists and your network allows outbound HTTPS to `*.blob.core.windows.net`. The error message is sanitized (credentials redacted) — check the log file for more detail.

**Q: Images in tickets are broken.**
A: Ensure the `url-rewriter.js` is being loaded. Check the browser console for JavaScript errors. The script should automatically rewrite image URLs to the plugin endpoint.

**Q: Azure is temporarily down. Will GLPI break?**
A: No. The plugin retries failed operations automatically (3 retries with exponential backoff). If all retries fail, uploads keep the local file, downloads fall back to local (in Backup mode) or show a friendly "temporarily unavailable" message, and deletes proceed normally. GLPI core operations are never blocked.

## Error Handling

| Scenario | Behavior |
|----------|----------|
| Azure unavailable during upload | Retried 3x with backoff. If all fail: file stays local, warning shown to admin, error logged. |
| Azure unavailable during download | Retried 3x. Falls back to local file (Backup mode) or shows friendly error. |
| Azure unavailable during delete | Retried 3x. Document purge proceeds normally. Orphan blob cleaned later. |
| Invalid credentials | "Test Connection" button alerts on config page. Uploads fail gracefully. |
| Container doesn't exist | Created automatically on first upload (if permissions allow). |
