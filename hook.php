<?php

/**
 * Azure Blob Storage for GLPI - Install/Uninstall hooks
 *
 * @license GPL-3.0-or-later
 */

function plugin_azureblobstorage_install(): bool
{
    global $DB;

    if (!$DB->tableExists('glpi_plugin_azureblobstorage_documenttrackers')) {
        $query = "CREATE TABLE `glpi_plugin_azureblobstorage_documenttrackers` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `documents_id` int unsigned NOT NULL DEFAULT 0,
            `filepath` varchar(255) NOT NULL DEFAULT '',
            `sha1sum` char(40) NOT NULL DEFAULT '',
            `azure_blob_name` varchar(512) NOT NULL DEFAULT '',
            `uploaded_at` timestamp NULL DEFAULT NULL,
            `file_size` bigint unsigned DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `documents_id` (`documents_id`),
            KEY `filepath` (`filepath`),
            KEY `sha1sum` (`sha1sum`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!$DB->doQuery($query)) {
            trigger_error(
                sprintf('[AzureBlobStorage] Error creating table: %s', $DB->error()),
                E_USER_ERROR
            );
            return false;
        }
    }

    // Set default config values
    $defaults = [
        'connection_string'  => '',
        'account_name'       => '',
        'account_key'        => '',
        'container_name'     => 'glpi-documents',
        'storage_mode'       => 'azure_primary',
        'download_method'    => 'sas_redirect',
        'sas_expiry_minutes' => '10',
        'enabled'            => '0',
    ];

    $existing = Config::getConfigurationValues('plugin:azureblobstorage');
    $to_set = [];
    foreach ($defaults as $key => $value) {
        if (!isset($existing[$key])) {
            $to_set[$key] = $value;
        }
    }
    if (!empty($to_set)) {
        Config::setConfigurationValues('plugin:azureblobstorage', $to_set);
    }

    return true;
}

function plugin_azureblobstorage_uninstall(): bool
{
    global $DB;

    if ($DB->tableExists('glpi_plugin_azureblobstorage_documenttrackers')) {
        // Block uninstall if documents are still tracked in Azure
        $result = $DB->request([
            'COUNT' => 'total',
            'FROM'  => 'glpi_plugin_azureblobstorage_documenttrackers',
        ]);
        $count = (int) ($result->current()['total'] ?? 0);
        if ($count > 0) {
            trigger_error(
                sprintf(
                    '[AzureBlobStorage] Cannot uninstall: %d documents are still tracked in Azure. '
                    . 'Run "php bin/console plugins:azureblobstorage:migrate-local" first '
                    . 'to download all documents back to local storage.',
                    $count
                ),
                E_USER_ERROR
            );
            return false;
        }

        $DB->doQuery("DROP TABLE `glpi_plugin_azureblobstorage_documenttrackers`");
    }

    // Remove config values
    $config_keys = [
        'connection_string',
        'account_name',
        'account_key',
        'container_name',
        'storage_mode',
        'download_method',
        'sas_expiry_minutes',
        'enabled',
    ];

    Config::deleteConfigurationValues('plugin:azureblobstorage', $config_keys);

    return true;
}
