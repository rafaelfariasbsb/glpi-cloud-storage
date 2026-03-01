<?php

namespace GlpiPlugin\Cloudstorage;

use Document;

class DocumentHook
{
    /**
     * Hook called after a Document is added to the database.
     * Uploads the file to cloud storage.
     *
     * Note: Local files are NOT deleted here, even in cloud_primary mode.
     * Automatic deletion during the HTTP request causes a race condition:
     * the browser tries to load inline images via the core document.send.php
     * before the JS url-rewriter can redirect to the plugin endpoint.
     * Use the CLI command `plugins:cloudstorage:cleanup-local` to remove
     * local copies of files that are confirmed uploaded to cloud storage.
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

        try {
            $localPath = Config::validateLocalPath($filepath);
        } catch (\RuntimeException $e) {
            \Toolbox::logInFile('cloudstorage', $e->getMessage() . "\n");
            return;
        }

        if (!file_exists($localPath)) {
            return; // No local file to upload
        }

        // Deduplication: if a blob with same SHA1 already exists, skip upload
        if (!empty($sha1sum) && DocumentTracker::sha1Exists($sha1sum)) {
            // Verify the blob actually exists (may have been deleted externally)
            try {
                $client = StorageClientFactory::getInstance();
                if ($client->exists($filepath)) {
                    $fileSize = filesize($localPath) ?: 0;
                    DocumentTracker::track($item->getID(), $filepath, $sha1sum, $fileSize);
                    return;
                }
                // Blob missing in Azure — fall through to re-upload
            } catch (\Throwable $e) {
                // Cannot verify — fall through to upload as safety measure
                trigger_error(
                    sprintf(
                        '[CloudStorage] Dedup verification failed for document %d (%s): %s',
                        $item->getID(),
                        $filepath,
                        $e->getMessage()
                    ),
                    E_USER_WARNING
                );
                \Toolbox::logInFile('cloudstorage', sprintf(
                    "DEDUP CHECK FAILED | doc_id=%d | filepath=%s | error=%s\n%s\n",
                    $item->getID(),
                    $filepath,
                    $e->getMessage(),
                    $e->getTraceAsString()
                ));
            }
        }

        try {
            $client = StorageClientFactory::getInstance();

            // Upload to cloud storage
            $client->upload($filepath, $localPath);

            // Track in database
            $fileSize = filesize($localPath) ?: 0;
            DocumentTracker::track($item->getID(), $filepath, $sha1sum, $fileSize);

        } catch (\Throwable $e) {
            // Log error but do NOT prevent document creation
            trigger_error(
                sprintf(
                    '[CloudStorage] Upload failed for document %d (%s): %s',
                    $item->getID(),
                    $filepath,
                    $e->getMessage()
                ),
                E_USER_WARNING
            );
            \Toolbox::logInFile('cloudstorage', sprintf(
                "UPLOAD FAILED | doc_id=%d | filepath=%s | error=%s\n%s\n",
                $item->getID(),
                $filepath,
                $e->getMessage(),
                $e->getTraceAsString()
            ));
            \Session::addMessageAfterRedirect(
                sprintf(
                    __('[Cloud Storage] Upload to cloud storage failed for document "%s". The local copy was kept. Check files/_log/cloudstorage.log for details.'),
                    $item->fields['filename'] ?? $filepath
                ),
                false,
                WARNING
            );
        }
    }

    /**
     * Hook called after a Document is updated.
     * If the file changed, upload the new version to cloud storage.
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

        try {
            $localPath = Config::validateLocalPath($newFilepath);
        } catch (\RuntimeException $e) {
            \Toolbox::logInFile('cloudstorage', $e->getMessage() . "\n");
            return;
        }

        if (!file_exists($localPath)) {
            return;
        }

        try {
            $client = StorageClientFactory::getInstance();

            // Upload new file (if not already in cloud via deduplication)
            $needsUpload = true;
            if (!empty($newSha1sum) && DocumentTracker::sha1Exists($newSha1sum)) {
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

            // Clean up old Azure blob if no longer referenced
            if (!empty($oldFilepath)) {
                $oldSha1sum = $item->oldvalues['sha1sum'] ?? '';
                self::cleanupOrphanedBlob($oldFilepath, $oldSha1sum);
            }
        } catch (\Throwable $e) {
            trigger_error(
                sprintf(
                    '[CloudStorage] Update upload failed for document %d: %s',
                    $item->getID(),
                    $e->getMessage()
                ),
                E_USER_WARNING
            );
            \Toolbox::logInFile('cloudstorage', sprintf(
                "UPDATE UPLOAD FAILED | doc_id=%d | error=%s\n%s\n",
                $item->getID(),
                $e->getMessage(),
                $e->getTraceAsString()
            ));
            \Session::addMessageAfterRedirect(
                sprintf(
                    __('[Cloud Storage] Upload to cloud storage failed for document "%s". The local copy was kept. Check files/_log/cloudstorage.log for details.'),
                    $item->fields['filename'] ?? $newFilepath
                ),
                false,
                WARNING
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
        $blobPath = $tracking['remote_path'];

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
                $client = StorageClientFactory::getInstance();
                $client->delete($blobPath);
            }
        } catch (\Throwable $e) {
            // Log but don't prevent purge
            trigger_error(
                sprintf(
                    '[CloudStorage] Failed to delete blob for document %d: %s',
                    $documentId,
                    $e->getMessage()
                ),
                E_USER_WARNING
            );
            \Toolbox::logInFile('cloudstorage', sprintf(
                "PURGE DELETE FAILED | doc_id=%d | blob=%s | error=%s\n%s\n",
                $documentId,
                $blobPath,
                $e->getMessage(),
                $e->getTraceAsString()
            ));
        }
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
                $client = StorageClientFactory::getInstance();
                $client->delete($filepath);
            } catch (\Throwable $e) {
                trigger_error(
                    sprintf('[CloudStorage] Orphan cleanup failed for %s: %s', $filepath, $e->getMessage()),
                    E_USER_WARNING
                );
                \Toolbox::logInFile('cloudstorage', sprintf(
                    "ORPHAN CLEANUP FAILED | filepath=%s | error=%s\n%s\n",
                    $filepath,
                    $e->getMessage(),
                    $e->getTraceAsString()
                ));
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

        while (rtrim($dir, '/') !== rtrim($docDir, '/') && is_dir($dir)) {
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
