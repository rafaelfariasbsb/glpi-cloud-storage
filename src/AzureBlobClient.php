<?php

namespace GlpiPlugin\Cloudstorage;

use AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter;
use AzureOss\Storage\Blob\BlobContainerClient;
use AzureOss\Storage\Blob\BlobServiceClient;
use AzureOss\Storage\Blob\Sas\BlobSasBuilder;
use AzureOss\Storage\Blob\Sas\BlobSasPermissions;
use AzureOss\Storage\Common\Sas\SasProtocol;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;

class AzureBlobClient implements StorageClientInterface
{
    private Filesystem $filesystem;
    private BlobContainerClient $containerClient;

    private function __construct(
        BlobContainerClient $containerClient,
    ) {
        $this->containerClient = $containerClient;
        $adapter = new AzureBlobStorageAdapter($this->containerClient);
        $this->filesystem = new Filesystem($adapter);
    }

    /**
     * Create a client from the plugin config array.
     *
     * Called by StorageClientFactory::getInstance(). Config keys are prefixed:
     * azure_connection_string, azure_container_name, etc.
     */
    public static function fromConfig(array $config): self
    {
        $connectionString = $config['azure_connection_string'] ?? '';
        $containerName = $config['azure_container_name'] ?? '';

        if (empty($connectionString)) {
            throw new \RuntimeException('[CloudStorage] Azure connection string is not configured.');
        }

        if (empty($containerName)) {
            throw new \RuntimeException('[CloudStorage] Azure container name is not configured.');
        }

        try {
            $serviceClient = BlobServiceClient::fromConnectionString($connectionString);
            $containerClient = $serviceClient->getContainerClient($containerName);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                sprintf(
                    '[CloudStorage] Invalid Azure credentials. Please check your Connection String in plugin settings. (Detail: %s)',
                    self::sanitizeErrorMessage($e->getMessage())
                ),
                0,
                $e
            );
        }

        return new self($containerClient);
    }

    /**
     * Create a client from explicit parameters (for testing connection before saving config).
     */
    public static function fromParams(
        string $connectionString,
        string $containerName,
    ): self {
        $serviceClient = BlobServiceClient::fromConnectionString($connectionString);
        $containerClient = $serviceClient->getContainerClient($containerName);
        return new self($containerClient);
    }

    /**
     * Upload a local file to cloud storage.
     */
    public function upload(string $remotePath, string $localPath): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException(
                sprintf('[CloudStorage] Cannot open local file: %s', $localPath)
            );
        }

        try {
            $this->filesystem->writeStream($remotePath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Download file content as a string.
     */
    public function download(string $remotePath): string
    {
        return $this->filesystem->read($remotePath);
    }

    /**
     * Get a readable stream for a file (memory-safe for large files).
     *
     * @return resource
     */
    public function readStream(string $remotePath)
    {
        return $this->filesystem->readStream($remotePath);
    }

    /**
     * Download file to a local path.
     */
    public function downloadToFile(string $remotePath, string $localPath): void
    {
        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $stream = $this->filesystem->readStream($remotePath);
        $localStream = fopen($localPath, 'wb');

        if ($localStream === false) {
            throw new \RuntimeException(
                sprintf('[CloudStorage] Cannot write to local file: %s', $localPath)
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
     * Delete a file from cloud storage.
     */
    public function delete(string $remotePath): void
    {
        try {
            $this->filesystem->delete($remotePath);
        } catch (FilesystemException $e) {
            trigger_error(
                sprintf('[CloudStorage] Failed to delete blob %s: %s', $remotePath, $e->getMessage()),
                E_USER_WARNING
            );
            \Toolbox::logInFile('cloudstorage', sprintf(
                "DELETE FAILED | blob=%s | error=%s\n%s\n",
                $remotePath,
                $e->getMessage(),
                $e->getTraceAsString()
            ));
        }
    }

    /**
     * Check if a file exists in cloud storage.
     */
    public function exists(string $remotePath): bool
    {
        try {
            return $this->filesystem->fileExists($remotePath);
        } catch (FilesystemException $e) {
            trigger_error(
                sprintf('[CloudStorage] Failed to check blob existence for %s: %s', $remotePath, $e->getMessage()),
                E_USER_WARNING
            );
            \Toolbox::logInFile('cloudstorage', sprintf(
                "EXISTS CHECK FAILED | blob=%s | error=%s\n%s\n",
                $remotePath,
                $e->getMessage(),
                $e->getTraceAsString()
            ));
            return false;
        }
    }

    /**
     * Get the file size in bytes.
     */
    public function fileSize(string $remotePath): int
    {
        return $this->filesystem->fileSize($remotePath);
    }

    /**
     * Generate a temporary URL for direct browser access (Azure SAS URL).
     *
     * Uses HTTPS-only protocol to prevent token leakage over plaintext.
     */
    public function generateTemporaryUrl(string $remotePath, int $expiryMinutes): string
    {
        $expiryMinutes = max(1, $expiryMinutes);

        $blobClient = $this->containerClient->getBlobClient($remotePath);
        $sasUri = $blobClient->generateSasUri(
            BlobSasBuilder::new()
                ->setPermissions(new BlobSasPermissions(read: true))
                ->setExpiresOn(new \DateTimeImmutable("+{$expiryMinutes} minutes"))
                ->setProtocol(SasProtocol::HttpsOnly)
        );

        return (string) $sasUri;
    }

    /**
     * Test the connection to Azure Blob Storage.
     *
     * @return true|string True on success, error message on failure
     */
    public function testConnection(): true|string
    {
        try {
            $blobs = $this->containerClient->getBlobs();
            foreach ($blobs as $blob) {
                break; // iterate 1, lazy — validates access
            }
            return true;
        } catch (\Throwable $e) {
            return sprintf('Connection failed: %s', self::sanitizeErrorMessage($e->getMessage()));
        }
    }

    /**
     * Sanitize error messages to avoid leaking credentials in logs.
     */
    private static function sanitizeErrorMessage(string $message): string
    {
        // Redact specific Azure credential patterns
        $message = preg_replace('/AccountKey=[^\s;]+/', 'AccountKey=***REDACTED***', $message) ?? $message;
        $message = preg_replace('/SharedAccessSignature=[^\s;]+/', 'SharedAccessSignature=***REDACTED***', $message) ?? $message;
        $message = preg_replace('/sig=[^\s&]+/', 'sig=***REDACTED***', $message) ?? $message;

        // Redact values between quotes (existing pattern)
        $message = preg_replace(
            "/['\"]([A-Za-z0-9+\/=]{40,})['\"]/",
            "'***REDACTED***'",
            $message
        ) ?? $message;

        // Redact any loose long base64 sequences (catches unquoted keys)
        $message = preg_replace(
            '/(?<![A-Za-z0-9+\/=])([A-Za-z0-9+\/]{40,}={0,2})(?![A-Za-z0-9+\/=])/',
            '***REDACTED***',
            $message
        ) ?? $message;

        return $message;
    }
}
