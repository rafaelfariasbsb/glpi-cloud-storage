<?php

/**
 * Azure Blob Storage - Configuration form handler
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Azureblobstorage\Config;

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

if (isset($_POST['update'])) {
    // CSRF is validated automatically by GLPI 11's CheckCsrfListener

    $fields = [
        'connection_string',
        'account_name',
        'account_key',
        'container_name',
        'storage_mode',
        'download_method',
        'sas_expiry_minutes',
        'enabled',
    ];

    $allowedStorageModes = ['azure_primary', 'azure_backup'];
    $allowedDownloadMethods = ['sas_redirect', 'proxy'];

    $values = [];
    foreach ($fields as $field) {
        if (!isset($_POST[$field])) {
            continue;
        }

        $value = $_POST[$field];

        // Validate enumerated fields
        if ($field === 'storage_mode' && !in_array($value, $allowedStorageModes, true)) {
            continue;
        }
        if ($field === 'download_method' && !in_array($value, $allowedDownloadMethods, true)) {
            continue;
        }
        if ($field === 'sas_expiry_minutes') {
            $value = (string) max(1, min(1440, (int) $value));
        }
        if ($field === 'enabled') {
            $value = in_array($value, ['0', '1'], true) ? $value : '0';
        }

        $values[$field] = $value;
    }

    Config::set($values);

    Session::addMessageAfterRedirect(
        __('Configuration updated successfully.'),
        true,
        INFO
    );

    Html::back();
}

if (isset($_POST['test_connection'])) {
    // CSRF is validated automatically by GLPI 11's CheckCsrfListener

    // Use saved config — credentials are not sent from the form
    $result = Config::testConnection();

    if ($result === true) {
        Session::addMessageAfterRedirect(
            __('Connection to Azure Blob Storage successful!'),
            true,
            INFO
        );
    } else {
        Session::addMessageAfterRedirect(
            htmlescape(sprintf(__('Connection test failed: %s'), $result)),
            true,
            ERROR
        );
    }

    Html::back();
}
