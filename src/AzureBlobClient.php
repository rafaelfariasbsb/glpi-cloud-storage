<?php

namespace GlpiPlugin\Azureblobstorage;

use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Blob\BlobSharedAccessSignatureHelper;
use MicrosoftAzure\Storage\Common\Internal\Resources;

class AzureBlobClient
{
    private static ?self $instance = null;

    private Filesystem $filesystem;
    private BlobRestProxy $blobClient;
    private string $containerName;
    private string $accountName;
    private string $accountKey;
    private string $blobEndpoint;

    private function __construct(
        string $connectionString,
        string $containerName,
        string $accountName,
        string $accountKey
    ) {
        $this->containerName = $containerName;
        $this->accountName = $accountName;
        $this->accountKey = $accountKey;
        $this->blobEndpoint = self::parseBlobEndpoint($connectionString, $accountName);

        $this->blobClient = BlobRestProxy::createBlobService($connectionString);

        $adapter = new AzureBlobStorageAdapter(
            $this->blobClient,
            $this->containerName
        );

        $this->filesystem = new Filesystem($adapter);
    }

    /**
     * Parse the Blob endpoint from a connection string.
     *
     * Supports standard Azure, Azurite (local emulator), Azure Government,
     * and Azure China by reading BlobEndpoint or constructing from
     * EndpointSuffix + AccountName.
     */
    private static function parseBlobEndpoint(string $connectionString, string $accountName): string
    {
        $parts = [];
        foreach (explode(';', $connectionString) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }
            $eqPos = strpos($segment, '=');
            if ($eqPos !== false) {
                $key = substr($segment, 0, $eqPos);
                $value = substr($segment, $eqPos + 1);
                $parts[$key] = $value;
            }
        }

        // Explicit BlobEndpoint takes priority (used by Azurite and custom setups)
        if (!empty($parts['BlobEndpoint'])) {
            return rtrim($parts['BlobEndpoint'], '/');
        }

        // Construct from protocol + account + suffix
        $protocol = $parts['DefaultEndpointsProtocol'] ?? 'https';
        $account = $parts['AccountName'] ?? $accountName;
        $suffix = $parts['EndpointSuffix'] ?? 'core.windows.net';

        return sprintf('%s://%s.blob.%s', $protocol, $account, $suffix);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            $config = Config::getPluginConfig();

            if (empty($config['connection_string'])) {
                throw new \RuntimeException('[AzureBlobStorage] Connection string is not configured.');
            }

            if (empty($config['container_name'])) {
                throw new \RuntimeException('[AzureBlobStorage] Container name is not configured.');
            }

            self::$instance = new self(
                $config['connection_string'],
                $config['container_name'],
                $config['account_name'] ?? '',
                $config['account_key'] ?? ''
            );
        }

        return self::$instance;
    }

    /**
     * Reset singleton (useful for testing or config changes).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Upload a local file to Azure Blob Storage.
     *
     * @param string $blobPath   Path in the container (e.g., "PDF/ab/cdef123.PDF")
     * @param string $localPath  Absolute path to the local file
     */
    public function upload(string $blobPath, string $localPath): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException(
                sprintf('[AzureBlobStorage] Cannot open local file: %s', $localPath)
            );
        }

        try {
            $this->filesystem->writeStream($blobPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Download blob content as a string.
     */
    public function download(string $blobPath): string
    {
        return $this->filesystem->read($blobPath);
    }

    /**
     * Get a readable stream for a blob (memory-safe for large files).
     *
     * @return resource
     */
    public function readStream(string $blobPath)
    {
        return $this->filesystem->readStream($blobPath);
    }

    /**
     * Download blob to a local file.
     */
    public function downloadToFile(string $blobPath, string $localPath): void
    {
        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stream = $this->filesystem->readStream($blobPath);
        $localStream = fopen($localPath, 'wb');

        if ($localStream === false) {
            throw new \RuntimeException(
                sprintf('[AzureBlobStorage] Cannot write to local file: %s', $localPath)
            );
        }

        try {
            stream_copy_to_stream($stream, $localStream);
        } finally {
            fclose($localStream);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Delete a blob from Azure.
     */
    public function delete(string $blobPath): void
    {
        try {
            $this->filesystem->delete($blobPath);
        } catch (FilesystemException $e) {
            trigger_error(
                sprintf('[AzureBlobStorage] Failed to delete blob %s: %s', $blobPath, $e->getMessage()),
                E_USER_WARNING
            );
        }
    }

    /**
     * Check if a blob exists.
     */
    public function exists(string $blobPath): bool
    {
        try {
            return $this->filesystem->fileExists($blobPath);
        } catch (FilesystemException $e) {
            return false;
        }
    }

    /**
     * Get the file size of a blob in bytes.
     */
    public function fileSize(string $blobPath): int
    {
        return $this->filesystem->fileSize($blobPath);
    }

    /**
     * Generate a SAS (Shared Access Signature) URL for temporary access.
     *
     * @param string $blobPath       Path to the blob
     * @param int    $expiryMinutes  How many minutes until the URL expires
     * @return string The full SAS URL
     */
    public function generateSasUrl(string $blobPath, int $expiryMinutes = 10): string
    {
        $helper = new BlobSharedAccessSignatureHelper(
            $this->accountName,
            $this->accountKey
        );

        $expiry = new \DateTime();
        $expiry->modify('+' . $expiryMinutes . ' minutes');

        $sas = $helper->generateBlobServiceSharedAccessSignatureToken(
            Resources::RESOURCE_TYPE_BLOB,
            $this->containerName . '/' . $blobPath,
            'r', // read permission
            $expiry
        );

        return sprintf(
            '%s/%s/%s?%s',
            $this->blobEndpoint,
            $this->containerName,
            $blobPath,
            $sas
        );
    }

    /**
     * Test the connection to Azure Blob Storage.
     *
     * @return true|string  True on success, error message on failure
     */
    public function testConnection(): true|string
    {
        try {
            // Try listing blobs (max 1) to verify connection
            $this->blobClient->listBlobs($this->containerName);
            return true;
        } catch (\Throwable $e) {
            return sprintf('Connection failed: %s', $e->getMessage());
        }
    }

    /**
     * Create a client from explicit parameters (for testing connection before saving config).
     */
    public static function fromParams(
        string $connectionString,
        string $containerName,
        string $accountName = '',
        string $accountKey = ''
    ): self {
        return new self($connectionString, $containerName, $accountName, $accountKey);
    }
}
