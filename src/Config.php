<?php

namespace GlpiPlugin\Azureblobstorage;

class Config
{
    private const CONTEXT = 'plugin:azureblobstorage';

    private const CONFIG_KEYS = [
        'connection_string',
        'account_name',
        'account_key',
        'container_name',
        'storage_mode',
        'download_method',
        'sas_expiry_minutes',
        'enabled',
    ];

    /**
     * Get all plugin configuration values.
     *
     * @return array<string, string>
     */
    public static function getPluginConfig(): array
    {
        return \Config::getConfigurationValues(self::CONTEXT, self::CONFIG_KEYS);
    }

    /**
     * Get a single configuration value.
     */
    public static function get(string $key, string $default = ''): string
    {
        $config = \Config::getConfigurationValues(self::CONTEXT, [$key]);
        return $config[$key] ?? $default;
    }

    /**
     * Set configuration values.
     *
     * @param array<string, string> $values
     */
    public static function set(array $values): void
    {
        \Config::setConfigurationValues(self::CONTEXT, $values);
        AzureBlobClient::resetInstance();
    }

    /**
     * Check if the plugin is enabled.
     */
    public static function isEnabled(): bool
    {
        return self::get('enabled', '0') === '1';
    }

    /**
     * Get the storage mode (azure_primary or azure_backup).
     */
    public static function getStorageMode(): string
    {
        return self::get('storage_mode', 'azure_primary');
    }

    /**
     * Check if storage mode is "Azure Primary" (delete local after upload).
     */
    public static function isAzurePrimary(): bool
    {
        return self::getStorageMode() === 'azure_primary';
    }

    /**
     * Get the download method (sas_redirect or proxy).
     */
    public static function getDownloadMethod(): string
    {
        return self::get('download_method', 'sas_redirect');
    }

    /**
     * Get SAS URL expiry in minutes.
     */
    public static function getSasExpiryMinutes(): int
    {
        return (int) self::get('sas_expiry_minutes', '10');
    }

    /**
     * Test the Azure connection using current (or provided) config.
     *
     * @return true|string True on success, error message string on failure.
     */
    public static function testConnection(
        ?string $connectionString = null,
        ?string $containerName = null,
        ?string $accountName = null,
        ?string $accountKey = null
    ): true|string {
        try {
            $config = self::getPluginConfig();

            $client = AzureBlobClient::fromParams(
                $connectionString ?? $config['connection_string'] ?? '',
                $containerName ?? $config['container_name'] ?? '',
                $accountName ?? $config['account_name'] ?? '',
                $accountKey ?? $config['account_key'] ?? ''
            );

            return $client->testConnection();
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}
