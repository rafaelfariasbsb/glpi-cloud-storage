<?php

/**
 * Cloud Storage for GLPI
 *
 * Store GLPI documents in cloud storage (Azure Blob Storage, AWS S3).
 * This plugin does NOT modify any GLPI core files.
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Cloudstorage\DocumentHook;

define('PLUGIN_CLOUDSTORAGE_VERSION', '2.0.0');
define('PLUGIN_CLOUDSTORAGE_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_CLOUDSTORAGE_MAX_GLPI_VERSION', '11.99.99');

function plugin_version_cloudstorage(): array
{
    return [
        'name'           => 'Cloud Storage',
        'version'        => PLUGIN_CLOUDSTORAGE_VERSION,
        'author'         => 'Rafael Farias',
        'license'        => 'GPLv3',
        'homepage'       => 'https://github.com/rafaelfariasbsb/glpi-cloud-storage',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_CLOUDSTORAGE_MIN_GLPI_VERSION,
                'max' => PLUGIN_CLOUDSTORAGE_MAX_GLPI_VERSION,
            ],
            'php'  => [
                'min' => '8.2',
            ],
        ],
    ];
}

function plugin_cloudstorage_check_prerequisites(): bool
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        echo "Cloud Storage plugin requires composer dependencies. Run 'composer install' in the plugin directory.";
        return false;
    }

    return true;
}

function plugin_cloudstorage_check_config(): bool
{
    return true;
}

function plugin_init_cloudstorage(): void
{
    global $PLUGIN_HOOKS;

    $plugin = new Plugin();
    if (!$plugin->isActivated('cloudstorage')) {
        return;
    }

    // Load composer autoload for Flysystem/Cloud SDKs
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }

    // Config page
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['cloudstorage'] = 'front/config.php';

    // Encrypt sensitive config values
    $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['cloudstorage'] = [
        'azure_connection_string',
        'azure_account_key',
        's3_access_key_id',
        's3_secret_access_key',
    ];

    // Document lifecycle hooks
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['cloudstorage'] = [
        'Document' => [DocumentHook::class, 'onItemAdd'],
    ];

    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['cloudstorage'] = [
        'Document' => [DocumentHook::class, 'onItemUpdate'],
    ];

    $PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['cloudstorage'] = [
        'Document' => [DocumentHook::class, 'onPreItemPurge'],
    ];

    // JavaScript for URL rewriting.
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['cloudstorage'] = ['js/url-rewriter.js'];
}
