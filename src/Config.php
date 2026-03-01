<?php

namespace GlpiPlugin\Cloudstorage;

class Config
{
    private const CONTEXT = 'plugin:cloudstorage';

    private const CONFIG_KEYS = [
        'provider',
        'azure_connection_string',
        'azure_account_name',
        'azure_account_key',
        'azure_container_name',
        's3_access_key_id',
        's3_secret_access_key',
        's3_region',
        's3_bucket_name',
        's3_endpoint',
        'storage_mode',
        'download_method',
        'url_expiry_minutes',
        'enabled',
    ];

    /** Fields encrypted via GLPI's SECURED_CONFIGS mechanism. */
    private const SECURED_FIELDS = [
        'azure_connection_string',
        'azure_account_key',
        's3_access_key_id',
        's3_secret_access_key',
    ];

    /** @var array<string, string>|null Per-request cache of decrypted config. */
    private static ?array $cache = null;

    /**
     * Get all plugin configuration values.
     * Secured fields are decrypted automatically. Cached per request.
     *
     * @return array<string, string>
     */
    public static function getPluginConfig(): array
    {
        if (self::$cache === null) {
            $config = \Config::getConfigurationValues(self::CONTEXT, self::CONFIG_KEYS);
            self::$cache = self::decryptSecuredFields($config);
        }
        return self::$cache;
    }

    /**
     * Get a single configuration value.
     */
    public static function get(string $key, string $default = ''): string
    {
        $config = self::getPluginConfig();
        return $config[$key] ?? $default;
    }

    /**
     * Get the configured storage provider (azure or s3).
     */
    public static function getProvider(): string
    {
        return self::get('provider', 'azure');
    }

    /**
     * Decrypt fields that are stored encrypted via SECURED_CONFIGS.
     *
     * @param array<string, string> $config
     * @return array<string, string>
     */
    private static function decryptSecuredFields(array $config): array
    {
        $glpiKey = new \GLPIKey();
        foreach (self::SECURED_FIELDS as $field) {
            if (!empty($config[$field])) {
                try {
                    $decrypted = $glpiKey->decrypt($config[$field]);
                    // GLPIKey::decrypt() returns empty string on failure
                    $config[$field] = $decrypted !== '' ? $decrypted : $config[$field];
                } catch (\Throwable $e) {
                    trigger_error(
                        sprintf('[CloudStorage] Failed to decrypt config field "%s": %s', $field, $e->getMessage()),
                        E_USER_WARNING
                    );
                    \Toolbox::logInFile('cloudstorage', sprintf(
                        "DECRYPT FAILED | field=%s | error=%s\n%s\n",
                        $field,
                        $e->getMessage(),
                        $e->getTraceAsString()
                    ));
                }
            }
        }
        return $config;
    }

    /**
     * Set configuration values.
     *
     * @param array<string, string> $values
     */
    public static function set(array $values): void
    {
        \Config::setConfigurationValues(self::CONTEXT, $values);
        self::$cache = null;
        StorageClientFactory::resetInstance();
    }

    /**
     * Check if the plugin is enabled.
     */
    public static function isEnabled(): bool
    {
        return self::get('enabled', '0') === '1';
    }

    /**
     * Get the storage mode (cloud_primary or cloud_backup).
     */
    public static function getStorageMode(): string
    {
        return self::get('storage_mode', 'cloud_primary');
    }

    /**
     * Check if storage mode is "Cloud Primary" (delete local after upload).
     */
    public static function isCloudPrimary(): bool
    {
        return self::getStorageMode() === 'cloud_primary';
    }

    /**
     * Get the download method (redirect or proxy).
     */
    public static function getDownloadMethod(): string
    {
        return self::get('download_method', 'redirect');
    }

    /**
     * Get temporary URL expiry in minutes.
     */
    public static function getUrlExpiryMinutes(): int
    {
        return (int) self::get('url_expiry_minutes', '5');
    }

    /**
     * Validate that a filepath resolves to a location inside GLPI_DOC_DIR.
     *
     * Prevents path traversal attacks if filepath data in the DB is compromised.
     *
     * @param string $filepath Relative filepath (e.g., "PDF/ab/cdef123.PDF")
     * @return string Absolute local path
     * @throws \RuntimeException If the path escapes GLPI_DOC_DIR
     */
    public static function validateLocalPath(string $filepath): string
    {
        $localPath = GLPI_DOC_DIR . '/' . $filepath;
        $realDocDir = realpath(GLPI_DOC_DIR);

        if ($realDocDir === false) {
            throw new \RuntimeException('[CloudStorage] GLPI_DOC_DIR does not exist.');
        }

        // For files that already exist, check the real path directly
        if (file_exists($localPath)) {
            $realPath = realpath($localPath);
            if ($realPath === false || !str_starts_with($realPath, $realDocDir . '/')) {
                throw new \RuntimeException(
                    sprintf('[CloudStorage] Path traversal detected: %s', $filepath)
                );
            }
            return $realPath;
        }

        // For files that don't exist yet, validate the parent directory
        $parentDir = dirname($localPath);
        if (is_dir($parentDir)) {
            $realParent = realpath($parentDir);
            if ($realParent === false || !str_starts_with($realParent . '/', $realDocDir . '/')) {
                throw new \RuntimeException(
                    sprintf('[CloudStorage] Path traversal detected: %s', $filepath)
                );
            }
        } else {
            // Parent doesn't exist yet — validate that the filepath has no traversal sequences
            if (str_contains($filepath, '..') || str_starts_with($filepath, '/')) {
                throw new \RuntimeException(
                    sprintf('[CloudStorage] Path traversal detected: %s', $filepath)
                );
            }
        }

        return $localPath;
    }

    /**
     * Test the cloud storage connection using current (or provided) config.
     *
     * @return true|string True on success, error message string on failure.
     */
    public static function testConnection(): true|string
    {
        try {
            $client = StorageClientFactory::getInstance();
            return $client->testConnection();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}
