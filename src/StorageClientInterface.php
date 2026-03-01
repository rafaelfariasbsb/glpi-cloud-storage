<?php

namespace GlpiPlugin\Cloudstorage;

interface StorageClientInterface
{
    /**
     * Upload a local file to cloud storage.
     *
     * @param string $remotePath Path in the container (e.g., "PDF/ab/cdef123.PDF")
     * @param string $localPath  Absolute path to the local file
     */
    public function upload(string $remotePath, string $localPath): void;

    /**
     * Download file content as a string.
     */
    public function download(string $remotePath): string;

    /**
     * Get a readable stream for a file (memory-safe for large files).
     *
     * @return resource
     */
    public function readStream(string $remotePath);

    /**
     * Download file to a local path.
     */
    public function downloadToFile(string $remotePath, string $localPath): void;

    /**
     * Delete a file from cloud storage.
     */
    public function delete(string $remotePath): void;

    /**
     * Check if a file exists in cloud storage.
     */
    public function exists(string $remotePath): bool;

    /**
     * Get the file size in bytes.
     */
    public function fileSize(string $remotePath): int;

    /**
     * Generate a temporary URL for direct browser access.
     *
     * @param string $remotePath    Path to the file
     * @param int    $expiryMinutes How many minutes until the URL expires
     * @return string The full temporary URL
     */
    public function generateTemporaryUrl(string $remotePath, int $expiryMinutes): string;

    /**
     * Test the connection to cloud storage.
     *
     * @return true|string True on success, error message on failure
     */
    public function testConnection(): true|string;
}
