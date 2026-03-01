<?php

namespace GlpiPlugin\Azureblobstorage;

use Document;

class DocumentHook
{
    /** @var string[] Local files to delete at the end of the request. */
    private static array $pendingDeletes = [];

    /**
     * Hook called after a Document is added to the database.
     * Uploads the file to Azure Blob Storage.
     *
     * Local file deletion is deferred to the end of the request via
     * register_shutdown_function() to avoid breaking GLPI's post-processing
     * (e.g. convertTagToImage needs the local file after Document::add).
     */
    public static function onItemAdd(Document $item): void
    {
        if (!Config::isEnabled()) {
            return;
        }

        $filepath = $item->fields['filepath'] ?? $item->input['filepath'] ?? null;
        if (empty($filepath)) {
            return; // Link-only document, no file
        }

        $sha1sum = $item->fields['sha1sum'] ?? $item->input['sha1sum'] ?? '';
        $localPath = GLPI_DOC_DIR . '/' . $filepath;

        if (!file_exists($localPath)) {
            return; // No local file to upload
        }

        // Deduplication: if a blob with same SHA1 already exists in Azure, skip upload
        if (!empty($sha1sum) && DocumentTracker::sha1ExistsInAzure($sha1sum)) {
            // Verify the blob actually exists (may have been deleted externally)
            try {
                $client = AzureBlobClient::getInstance();
                if ($client->exists($filepath)) {
                    $fileSize = filesize($localPath) ?: 0;
                    DocumentTracker::track($item->getID(), $filepath, $sha1sum, $fileSize);

                    if (Config::isAzurePrimary()) {
                        self::scheduleDeletion($localPath);
                    }
                    return;
                }
                // Blob missing in Azure — fall through to re-upload
            } catch (\Throwable $e) {
                // Cannot verify — fall through to upload as safety measure
            }
        }

        try {
            $client = AzureBlobClient::getInstance();

            // Upload to Azure
            $client->upload($filepath, $localPath);

            // Track in database
            $fileSize = filesize($localPath) ?: 0;
            DocumentTracker::track($item->getID(), $filepath, $sha1sum, $fileSize);

            // In "Azure Primary" mode, defer local deletion to end of request
            if (Config::isAzurePrimary()) {
                self::scheduleDeletion($localPath);
            }
        } catch (\Throwable $e) {
            // Log error but do NOT prevent document creation
            trigger_error(
                sprintf(
                    '[AzureBlobStorage] Upload failed for document %d (%s): %s',
                    $item->getID(),
                    $filepath,
                    $e->getMessage()
                ),
                E_USER_WARNING
            );
        }
    }

    /**
     * Hook called after a Document is updated.
     * If the file changed, upload the new version to Azure.
     */
    public static function onItemUpdate(Document $item): void
    {
        if (!Config::isEnabled()) {
            return;
        }

        // Check if filepath changed (new file uploaded)
        $oldFilepath = $item->oldvalues['filepath'] ?? null;
        $newFilepath = $item->fields['filepath'] ?? null;

        if ($oldFilepath === null || $oldFilepath === $newFilepath) {
            return; // File didn't change
        }

        if (empty($newFilepath)) {
            return;
        }

        $newSha1sum = $item->fields['sha1sum'] ?? '';
        $localPath = GLPI_DOC_DIR . '/' . $newFilepath;

        if (!file_exists($localPath)) {
            return;
        }

        try {
            $client = AzureBlobClient::getInstance();

            // Upload new file (if not already in Azure via deduplication)
            $needsUpload = true;
            if (!empty($newSha1sum) && DocumentTracker::sha1ExistsInAzure($newSha1sum)) {
                // Verify the blob actually exists before skipping upload
                $needsUpload = !$client->exists($newFilepath);
            }
            if ($needsUpload) {
                $client->upload($newFilepath, $localPath);
            }

            // Update tracking record
            DocumentTracker::removeByDocumentId($item->getID());
            $fileSize = filesize($localPath) ?: 0;
            DocumentTracker::track($item->getID(), $newFilepath, $newSha1sum, $fileSize);

            // Remove local copy if Azure Primary (deferred to end of request)
            if (Config::isAzurePrimary()) {
                self::scheduleDeletion($localPath);
            }

            // Clean up old Azure blob if no longer referenced
            if (!empty($oldFilepath)) {
                $oldSha1sum = $item->oldvalues['sha1sum'] ?? '';
                self::cleanupOrphanedBlob($oldFilepath, $oldSha1sum);
            }
        } catch (\Throwable $e) {
            trigger_error(
                sprintf(
                    '[AzureBlobStorage] Update upload failed for document %d: %s',
                    $item->getID(),
                    $e->getMessage()
                ),
                E_USER_WARNING
            );
        }
    }

    /**
     * Hook called BEFORE a Document is purged (deleted from DB).
     * Delete the blob from Azure if this is the last reference.
     *
     * Order: remove tracker FIRST, then check references and delete blob.
     * This prevents a race where onItemAdd sees the tracker and skips upload
     * while we're about to delete the blob.
     */
    public static function onPreItemPurge(Document $item): void
    {
        if (!Config::isEnabled()) {
            return;
        }

        $documentId = $item->getID();
        $tracking = DocumentTracker::getByDocumentId($documentId);

        if ($tracking === null) {
            return; // Not tracked in Azure
        }

        $sha1sum = $tracking['sha1sum'];
        $blobPath = $tracking['azure_blob_name'];

        // Remove tracker FIRST to prevent race conditions with onItemAdd deduplication
        DocumentTracker::removeByDocumentId($documentId);

        try {
            // Check deduplication: only delete blob if no other references remain.
            // docCount includes this document (still in DB at PRE_ITEM_PURGE), so <= 1.
            // trackerCount is already 0 for this doc (removed above).
            $docCount = countElementsInTable(
                'glpi_documents',
                ['sha1sum' => $sha1sum]
            );
            $trackerCount = DocumentTracker::countBySha1($sha1sum);

            if ($docCount <= 1 && $trackerCount === 0) {
                $client = AzureBlobClient::getInstance();
                $client->delete($blobPath);
            }
        } catch (\Throwable $e) {
            // Log but don't prevent purge
            trigger_error(
                sprintf(
                    '[AzureBlobStorage] Failed to delete blob for document %d: %s',
                    $documentId,
                    $e->getMessage()
                ),
                E_USER_WARNING
            );
        }
    }

    /**
     * Schedule a local file for deletion at the end of the request.
     *
     * GLPI's post-processing (e.g. convertTagToImage, thumbnail generation)
     * may still need the local file after Document hooks fire. Deferring
     * deletion to shutdown ensures all processing is complete.
     */
    private static function scheduleDeletion(string $localPath): void
    {
        self::$pendingDeletes[] = $localPath;

        // Always register — register_shutdown_function is per-request,
        // safe to call multiple times (each call adds a new handler).
        // We use count check to register only once per request.
        if (count(self::$pendingDeletes) === 1) {
            register_shutdown_function([self::class, 'processPendingDeletes']);
        }
    }

    /**
     * Delete all scheduled local files. Called at PHP shutdown.
     */
    public static function processPendingDeletes(): void
    {
        foreach (self::$pendingDeletes as $localPath) {
            if (!file_exists($localPath)) {
                continue;
            }
            if (!unlink($localPath)) {
                trigger_error(
                    sprintf('[AzureBlobStorage] Failed to delete local file: %s', $localPath),
                    E_USER_WARNING
                );
            } else {
                self::cleanEmptyDirs(dirname($localPath));
            }
        }
        self::$pendingDeletes = [];
    }

    /**
     * Delete an orphaned blob if no documents reference it anymore.
     */
    private static function cleanupOrphanedBlob(string $filepath, string $sha1sum): void
    {
        if (empty($sha1sum)) {
            return;
        }

        $docCount = countElementsInTable(
            'glpi_documents',
            ['sha1sum' => $sha1sum]
        );

        $trackerCount = DocumentTracker::countBySha1($sha1sum);

        if ($docCount <= 0 && $trackerCount <= 0) {
            try {
                $client = AzureBlobClient::getInstance();
                $client->delete($filepath);
            } catch (\Throwable $e) {
                trigger_error(
                    sprintf('[AzureBlobStorage] Orphan cleanup failed for %s: %s', $filepath, $e->getMessage()),
                    E_USER_WARNING
                );
            }
        }
    }

    /**
     * Remove empty parent directories up to GLPI_DOC_DIR.
     */
    private static function cleanEmptyDirs(string $dir): void
    {
        $docDir = realpath(GLPI_DOC_DIR);
        if ($docDir === false) {
            return;
        }

        while ($dir !== $docDir && is_dir($dir)) {
            $files = scandir($dir);
            if ($files === false || count($files) > 2) { // . and ..
                break;
            }
            if (!rmdir($dir)) {
                break;
            }
            $dir = dirname($dir);
        }
    }
}
