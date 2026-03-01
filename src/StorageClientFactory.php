<?php

namespace GlpiPlugin\Cloudstorage;

class StorageClientFactory
{
    private static ?StorageClientInterface $instance = null;

    public static function getInstance(): StorageClientInterface
    {
        if (self::$instance === null) {
            $provider = Config::getProvider();
            self::$instance = match ($provider) {
                'azure' => AzureBlobClient::fromConfig(Config::getPluginConfig()),
                's3'    => throw new \RuntimeException('[CloudStorage] S3 provider not yet implemented (Phase 2).'),
                default => throw new \RuntimeException(sprintf('[CloudStorage] Unknown storage provider: %s', $provider)),
            };
        }
        return self::$instance;
    }

    /**
     * Reset singleton (called by Config::set() when provider/config changes).
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }
}
