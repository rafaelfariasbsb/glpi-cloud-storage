<?php

/**
 * Cloud Storage - Configuration form handler
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Cloudstorage\Config;

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

if (isset($_POST['update'])) {
    // CSRF is validated automatically by GLPI 11's CheckCsrfListener

    $fields = [
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

    $allowedProviders = ['azure'];  // S3 not available until Phase 2
    $allowedStorageModes = ['cloud_primary', 'cloud_backup'];
    $allowedDownloadMethods = ['redirect', 'proxy'];

    // Validation rules for free-form fields (format + max length)
    $fieldValidations = [
        'azure_container_name' => static function (string $v): bool {
            // Azure container naming rules: 3-63 chars, lowercase alphanumeric + hyphens
            return (bool) preg_match('/^[a-z0-9]([a-z0-9-]{1,61}[a-z0-9])?$/', $v);
        },
        'azure_account_name' => static function (string $v): bool {
            // Azure storage account: 3-24 chars, lowercase alphanumeric only
            return $v === '' || (bool) preg_match('/^[a-z0-9]{3,24}$/', $v);
        },
        'azure_connection_string' => static function (string $v): bool {
            return strlen($v) <= 4096 && (
                str_starts_with($v, 'DefaultEndpointsProtocol=')
                || str_starts_with($v, 'UseDevelopmentStorage=')
            );
        },
        'azure_account_key' => static function (string $v): bool {
            // Azure keys are base64-encoded, max ~88 chars. Allow empty (optional field).
            return $v === '' || (strlen($v) <= 512 && (bool) preg_match('/^[A-Za-z0-9+\/=]+$/', $v));
        },
        's3_bucket_name' => static function (string $v): bool {
            // S3 bucket naming: 3-63 chars, lowercase, hyphens, dots
            return $v === '' || (bool) preg_match('/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/', $v);
        },
        's3_access_key_id' => static function (string $v): bool {
            return $v === '' || (strlen($v) >= 16 && strlen($v) <= 128 && (bool) preg_match('/^[A-Za-z0-9]+$/', $v));
        },
        's3_region' => static function (string $v): bool {
            return $v === '' || (bool) preg_match('/^[a-z0-9-]{1,64}$/', $v);
        },
        's3_endpoint' => static function (string $v): bool {
            return $v === '' || (bool) filter_var($v, FILTER_VALIDATE_URL);
        },
    ];

    $values = [];
    $hasValidationError = false;
    foreach ($fields as $field) {
        if (!isset($_POST[$field])) {
            continue;
        }

        $value = $_POST[$field];

        // Validate enumerated fields
        if ($field === 'provider' && !in_array($value, $allowedProviders, true)) {
            Session::addMessageAfterRedirect(
                htmlescape(sprintf(__('Invalid value for field "%s". Please check the format.'), $field)),
                false,
                ERROR
            );
            $hasValidationError = true;
            continue;
        }
        if ($field === 'storage_mode' && !in_array($value, $allowedStorageModes, true)) {
            continue;
        }
        if ($field === 'download_method' && !in_array($value, $allowedDownloadMethods, true)) {
            continue;
        }
        if ($field === 'url_expiry_minutes') {
            $value = (string) max(1, min(1440, (int) $value));
        }
        if ($field === 'enabled') {
            $value = in_array($value, ['0', '1'], true) ? $value : '0';
        }

        // Validate free-form fields (format + length)
        if (isset($fieldValidations[$field]) && $value !== '' && !$fieldValidations[$field]($value)) {
            Session::addMessageAfterRedirect(
                htmlescape(sprintf(__('Invalid value for field "%s". Please check the format.'), $field)),
                false,
                ERROR
            );
            $hasValidationError = true;
            continue;
        }

        $values[$field] = $value;
    }

    if ($hasValidationError) {
        Html::back();
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
            __('Connection to cloud storage successful!'),
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
