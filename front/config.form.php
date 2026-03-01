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
    Session::checkCSRF($_POST);

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

    $values = [];
    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            $values[$field] = $_POST[$field];
        }
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
    Session::checkCSRF($_POST);

    $result = Config::testConnection(
        $_POST['connection_string'] ?? null,
        $_POST['container_name'] ?? null,
        $_POST['account_name'] ?? null,
        $_POST['account_key'] ?? null
    );

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
