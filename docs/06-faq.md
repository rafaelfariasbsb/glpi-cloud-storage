# FAQ

## General

**Q: What happens if I disable the plugin?**
A: Documents stored in cloud will still be served from local copies (if available). If local copies were removed, those documents will be temporarily inaccessible until the plugin is re-enabled.

**Q: Can I use it with multiple GLPI instances?**
A: Yes! All instances can point to the same cloud container, as long as they share the same database.

**Q: Does the plugin work with GLPI Cloud?**
A: No, only with self-hosted installations where you have access to the plugins directory.

**Q: Can I use AWS S3 instead of Azure?**
A: S3 support is planned for Phase 2 of the plugin. The architecture already includes `StorageClientInterface` and `StorageClientFactory` to support multiple providers.

**Q: Does the plugin delete local files automatically?**
A: No. Even in Cloud Primary mode, local files are kept during the HTTP request to avoid race conditions with inline image loading. Use `plugins:cloudstorage:migrate --delete-local` to remove local copies after confirming they are in cloud storage.

## Costs

**Q: How much does Azure Blob Storage cost?**
A: Azure charges per GB stored and per operation. For most GLPI installations, the cost is cents per month. Check the [Azure pricing calculator](https://azure.microsoft.com/pricing/calculator/).

**Q: Which Azure Blob tier should I use?**
A: **Hot** for frequently accessed documents, **Cool** for rarely accessed. Configure tier policies in the Azure Portal, not in the plugin. See [Security - Lifecycle Management](05-security.md).

## Troubleshooting

**Q: Upload to cloud failed, what happens?**
A: The local file is preserved, the admin sees a warning message, and the error is logged with full stack trace to `files/_log/cloudstorage.log`. The document creation in GLPI is NOT blocked.

**Q: Where are the plugin logs?**
A: Two locations:
- **PHP error log** — one-line summaries prefixed with `[CloudStorage]`
- **`files/_log/cloudstorage.log`** — detailed logs with document IDs, blob paths, error messages, and full PHP stack traces

**Q: Downloads are returning 404 for some documents.**
A: Check if those documents were uploaded to cloud. Run `plugins:cloudstorage:migrate` to upload any missing documents. Also verify the plugin is enabled and the `enabled` config flag is set to `1`.

**Q: The "Test Connection" button fails.**
A: Verify your connection string and account key in the Azure Portal. Ensure the container exists and your network allows outbound HTTPS to `*.blob.core.windows.net`. Check `files/_log/cloudstorage.log` for details.

**Q: Images in tickets are broken.**
A: Ensure the `url-rewriter.js` is being loaded (check browser console). The script should rewrite image URLs from `document.send.php?file=` to the plugin endpoint. Also verify the document's local file exists or is tracked in cloud.

**Q: Cloud storage is temporarily down. Will GLPI break?**
A: No. Uploads keep the local file, downloads fall back to local (in Backup mode) or show a friendly "temporarily unavailable" message, and deletes proceed normally.

## Error Handling

| Scenario | Behavior |
|----------|----------|
| Cloud unavailable during upload | File stays local, warning shown, error logged |
| Cloud unavailable during download | Falls back to local file or shows friendly error |
| Cloud unavailable during delete | Document purge proceeds normally |
| Invalid credentials | "Test Connection" button alerts with sanitized error |
