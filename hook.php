<?php

/**
 * Cloud Storage for GLPI - Install/Uninstall hooks
 *
 * @license GPL-3.0-or-later
 */

function plugin_cloudstorage_install(): bool
{
    global $DB;

    // --- Migration from azureblobstorage plugin ---

    // Migrate table: rename + alter column
    if (
        $DB->tableExists('glpi_plugin_azureblobstorage_documenttrackers')
        && !$DB->tableExists('glpi_plugin_cloudstorage_documenttrackers')
    ) {
        $DB->doQuery(
            "RENAME TABLE `glpi_plugin_azureblobstorage_documenttrackers`
             TO `glpi_plugin_cloudstorage_documenttrackers`"
        );
        $DB->doQuery(
            "ALTER TABLE `glpi_plugin_cloudstorage_documenttrackers`
             CHANGE `azure_blob_name` `remote_path` varchar(512) NOT NULL DEFAULT ''"
        );
    }

    // Migrate config: old context → new context with renamed keys
    $oldConfig = Config::getConfigurationValues('plugin:azureblobstorage');
    if (!empty($oldConfig)) {
        $keyMap = [
            'connection_string'  => 'azure_connection_string',
            'account_name'       => 'azure_account_name',
            'account_key'        => 'azure_account_key',
            'container_name'     => 'azure_container_name',
            'sas_expiry_minutes' => 'url_expiry_minutes',
            'storage_mode'       => 'storage_mode',
            'download_method'    => 'download_method',
            'enabled'            => 'enabled',
        ];

        $enumMap = [
            'storage_mode' => [
                'azure_primary' => 'cloud_primary',
                'azure_backup'  => 'cloud_backup',
            ],
            'download_method' => [
                'sas_redirect' => 'redirect',
            ],
        ];

        $newValues = ['provider' => 'azure'];
        foreach ($keyMap as $oldKey => $newKey) {
            if (isset($oldConfig[$oldKey])) {
                $value = $oldConfig[$oldKey];
                // Map old enum values to new ones
                if (isset($enumMap[$oldKey][$value])) {
                    $value = $enumMap[$oldKey][$value];
                }
                $newValues[$newKey] = $value;
            }
        }

        Config::setConfigurationValues('plugin:cloudstorage', $newValues);

        // Remove old config context
        $oldKeys = array_keys($oldConfig);
        Config::deleteConfigurationValues('plugin:azureblobstorage', $oldKeys);
    }

    // --- Create table if not exists (fresh install) ---

    if (!$DB->tableExists('glpi_plugin_cloudstorage_documenttrackers')) {
        $query = "CREATE TABLE `glpi_plugin_cloudstorage_documenttrackers` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `documents_id` int unsigned NOT NULL DEFAULT 0,
            `filepath` varchar(255) NOT NULL DEFAULT '',
            `sha1sum` char(40) NOT NULL DEFAULT '',
            `remote_path` varchar(512) NOT NULL DEFAULT '',
            `uploaded_at` timestamp NULL DEFAULT NULL,
            `file_size` bigint unsigned DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `documents_id` (`documents_id`),
            KEY `filepath` (`filepath`),
            KEY `sha1sum` (`sha1sum`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$DB->doQuery($query)) {
            trigger_error(
                sprintf('[CloudStorage] Error creating table: %s', $DB->error()),
                E_USER_ERROR
            );
            return false;
        }
    }

    // Set default config values
    $defaults = [
        'provider'                 => 'azure',
        'azure_connection_string'  => '',
        'azure_account_name'       => '',
        'azure_account_key'        => '',
        'azure_container_name'     => 'glpi-documents',
        's3_access_key_id'         => '',
        's3_secret_access_key'     => '',
        's3_region'                => '',
        's3_bucket_name'           => '',
        's3_endpoint'              => '',
        'storage_mode'             => 'cloud_primary',
        'download_method'          => 'redirect',
        'url_expiry_minutes'       => '5',
        'enabled'                  => '0',
    ];

    $existing = Config::getConfigurationValues('plugin:cloudstorage');
    $to_set = [];
    foreach ($defaults as $key => $value) {
        if (!isset($existing[$key])) {
            $to_set[$key] = $value;
        }
    }
    if (!empty($to_set)) {
        Config::setConfigurationValues('plugin:cloudstorage', $to_set);
    }

    return true;
}

function plugin_cloudstorage_uninstall(): bool
{
    global $DB;

    if ($DB->tableExists('glpi_plugin_cloudstorage_documenttrackers')) {
        // Block uninstall if documents are still tracked in cloud storage
        $result = $DB->request([
            'COUNT' => 'total',
            'FROM'  => 'glpi_plugin_cloudstorage_documenttrackers',
        ]);
        $count = (int) ($result->current()['total'] ?? 0);
        if ($count > 0) {
            trigger_error(
                sprintf(
                    '[CloudStorage] Cannot uninstall: %d documents are still tracked in cloud storage. '
                    . 'Run "php bin/console plugins:cloudstorage:migrate-local" first '
                    . 'to download all documents back to local storage.',
                    $count
                ),
                E_USER_ERROR
            );
            return false;
        }

        $DB->doQuery("DROP TABLE `glpi_plugin_cloudstorage_documenttrackers`");
    }

    // Remove config values
    $config_keys = [
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

    Config::deleteConfigurationValues('plugin:cloudstorage', $config_keys);

    return true;
}
