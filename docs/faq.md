# FAQ

## General

**Q: What happens if I disable the plugin?**
A: Documents stored only in Azure (Primary mode) will be temporarily inaccessible. Documents in Backup mode remain accessible from local. Re-enable the plugin or run reverse migration.

**Q: Can I use it with multiple GLPI instances?**
A: Yes! All instances can point to the same Azure container, as long as they share the same database.

**Q: Does the plugin work with GLPI Cloud?**
A: No, only with self-hosted installations where you have access to the plugins directory.

**Q: Can I use AWS S3 instead of Azure?**
A: An AWS S3 plugin (`awss3storage`) is planned for this repository. The architecture uses Flysystem, which makes adding new cloud adapters straightforward. Contributions are welcome.

## Costs

**Q: How much does Azure Blob Storage cost?**
A: Azure charges per GB stored and per operation. For most GLPI installations, the cost is cents per month. Check the [Azure pricing calculator](https://azure.microsoft.com/pricing/calculator/).

**Q: Which Azure Blob tier should I use?**
A: **Hot** for frequently accessed documents, **Cool** for rarely accessed documents (cheaper). Configure this in the Azure Portal, not in the plugin.

## Troubleshooting

**Q: Upload to Azure failed, what happens?**
A: The local file is preserved and the error is logged. The document creation in GLPI is NOT blocked. You can re-upload later using the migration command.

**Q: Downloads are returning 404 for some documents.**
A: Check if those documents were migrated to Azure. Run `plugins:azureblobstorage:migrate` to upload any missing documents. Also verify the plugin is enabled.

**Q: The "Test Connection" button fails.**
A: Verify your connection string and account key in the Azure Portal. Ensure the container exists and your network allows outbound HTTPS to `*.blob.core.windows.net`.

**Q: Images in tickets are broken.**
A: Ensure the `url-rewriter.js` is being loaded. Check the browser console for JavaScript errors. The script should automatically rewrite image URLs to the plugin endpoint.

## Error Handling

| Scenario | Behavior |
|----------|----------|
| Azure unavailable during upload | File stays local, error logged. Can be re-uploaded later. |
| Azure unavailable during download | Returns friendly error. In Backup mode, serves from local. |
| Azure unavailable during delete | Document purge proceeds normally. Orphan blob cleaned later. |
| Invalid credentials | "Test Connection" button alerts on config page. Uploads fail gracefully. |
| Container doesn't exist | Created automatically on first upload (if permissions allow). |
