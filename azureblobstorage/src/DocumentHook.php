<?php

namespace GlpiPlugin\Azureblobstorage;

use Document;

class DocumentHook
{
    /**
     * Hook called after a Document is added to the database.
     * Uploads the file to Azure Blob Storage.
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
            // Just create the tracking record pointing to the existing blob
            $fileSize = filesize($localPath) ?: 0;
            DocumentTracker::track($item->getID(), $filepath, $sha1sum, $fileSize);

            if (Config::isAzurePrimary()) {
                @unlink($localPath);
                self::cleanEmptyDirs(dirname($localPath));
            }
            return;
        }

        try {
            $client = AzureBlobClient::getInstance();

            // Upload to Azure
            $client->upload($filepath, $localPath);

            // Track in database
            $fileSize = filesize($localPath) ?: 0;
            DocumentTracker::track($item->getID(), $filepath, $sha1sum, $fileSize);

            // In "Azure Primary" mode, remove the local copy after confirmed upload
            if (Config::isAzurePrimary()) {
                @unlink($localPath);
                self::cleanEmptyDirs(dirname($localPath));
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
            if (!empty($newSha1sum) && !DocumentTracker::sha1ExistsInAzure($newSha1sum)) {
                $client->upload($newFilepath, $localPath);
            }

            // Update tracking record
            DocumentTracker::removeByDocumentId($item->getID());
            $fileSize = filesize($localPath) ?: 0;
            DocumentTracker::track($item->getID(), $newFilepath, $newSha1sum, $fileSize);

            // Remove local copy if Azure Primary
            if (Config::isAzurePrimary()) {
                @unlink($localPath);
                self::cleanEmptyDirs(dirname($localPath));
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

        try {
            // Check deduplication: only delete blob if this is the last reference
            // Count in GLPI documents table (same as core logic)
            $docCount = countElementsInTable(
                'glpi_documents',
                ['sha1sum' => $sha1sum]
            );
            $trackerCount = DocumentTracker::countBySha1($sha1sum);

            // If this is the last document referencing this SHA1, delete the blob
            if ($docCount <= 1 && $trackerCount <= 1) {
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

        // Always remove the tracking record
        DocumentTracker::removeByDocumentId($documentId);
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
            @rmdir($dir);
            $dir = dirname($dir);
        }
    }
}
