<?php

namespace GlpiPlugin\Azureblobstorage;

use CommonDBTM;

class DocumentTracker extends CommonDBTM
{
    public static $table = 'glpi_plugin_azureblobstorage_documents';
    public static $rightname = 'config';

    /**
     * Track a document as stored in Azure.
     */
    public static function track(int $documentId, string $filepath, string $sha1sum, int $fileSize = 0): bool
    {
        $tracker = new self();

        // Check if already tracked
        $existing = $tracker->find(['documents_id' => $documentId]);
        if (!empty($existing)) {
            return true;
        }

        return (bool) $tracker->add([
            'documents_id'   => $documentId,
            'filepath'       => $filepath,
            'sha1sum'        => $sha1sum,
            'azure_blob_name' => $filepath,
            'uploaded_at'    => date('Y-m-d H:i:s'),
            'file_size'      => $fileSize,
        ]);
    }

    /**
     * Check if a document is stored in Azure.
     */
    public static function isInAzure(int $documentId): bool
    {
        $tracker = new self();
        $results = $tracker->find(['documents_id' => $documentId]);
        return !empty($results);
    }

    /**
     * Get the tracking record for a document.
     *
     * @return array|null The record fields, or null if not tracked
     */
    public static function getByDocumentId(int $documentId): ?array
    {
        $tracker = new self();
        $results = $tracker->find(['documents_id' => $documentId]);

        if (empty($results)) {
            return null;
        }

        return reset($results);
    }

    /**
     * Check if a SHA1 hash already exists in the tracker (for deduplication).
     */
    public static function sha1ExistsInAzure(string $sha1sum): bool
    {
        $tracker = new self();
        $results = $tracker->find(['sha1sum' => $sha1sum], [], 1);
        return !empty($results);
    }

    /**
     * Count how many tracker records reference a given SHA1.
     */
    public static function countBySha1(string $sha1sum): int
    {
        return countElementsInTable(self::$table, ['sha1sum' => $sha1sum]);
    }

    /**
     * Remove tracking record for a document.
     */
    public static function removeByDocumentId(int $documentId): bool
    {
        $tracker = new self();
        $results = $tracker->find(['documents_id' => $documentId]);

        if (empty($results)) {
            return true;
        }

        $record = reset($results);
        return $tracker->delete(['id' => $record['id']], true);
    }
}
