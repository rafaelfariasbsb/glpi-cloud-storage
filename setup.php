<?php

/**
 * Azure Blob Storage for GLPI
 *
 * Store GLPI documents in Microsoft Azure Blob Storage.
 * This plugin does NOT modify any GLPI core files.
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Azureblobstorage\Config;
use GlpiPlugin\Azureblobstorage\DocumentHook;

define('PLUGIN_AZUREBLOBSTORAGE_VERSION', '1.0.0');
define('PLUGIN_AZUREBLOBSTORAGE_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_AZUREBLOBSTORAGE_MAX_GLPI_VERSION', '11.99.99');

function plugin_version_azureblobstorage(): array
{
    return [
        'name'           => 'Azure Blob Storage',
        'version'        => PLUGIN_AZUREBLOBSTORAGE_VERSION,
        'author'         => 'Rafael Farias',
        'license'        => 'GPLv3',
        'homepage'       => 'https://github.com/rafaelfariasbsb/glpi-cloud-storage',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_AZUREBLOBSTORAGE_MIN_GLPI_VERSION,
                'max' => PLUGIN_AZUREBLOBSTORAGE_MAX_GLPI_VERSION,
            ],
            'php'  => [
                'min' => '8.2',
            ],
        ],
    ];
}

function plugin_azureblobstorage_check_prerequisites(): bool
{
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        echo "Azure Blob Storage plugin requires composer dependencies. Run 'composer install' in the plugin directory.";
        return false;
    }

    return true;
}

function plugin_azureblobstorage_check_config(): bool
{
    return true;
}

function plugin_init_azureblobstorage(): void
{
    global $PLUGIN_HOOKS;

    $plugin = new Plugin();
    if (!$plugin->isActivated('azureblobstorage')) {
        return;
    }

    // Load composer autoload for Flysystem/Azure SDK
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }

    // Config page
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['azureblobstorage'] = 'front/config.php';

    // Encrypt sensitive config values
    $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['azureblobstorage'] = [
        'connection_string',
        'account_key',
    ];

    // Document lifecycle hooks
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['azureblobstorage'] = [
        'Document' => [DocumentHook::class, 'onItemAdd'],
    ];

    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['azureblobstorage'] = [
        'Document' => [DocumentHook::class, 'onItemUpdate'],
    ];

    $PLUGIN_HOOKS[Hooks::PRE_ITEM_PURGE]['azureblobstorage'] = [
        'Document' => [DocumentHook::class, 'onPreItemPurge'],
    ];

    // JavaScript for URL rewriting
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['azureblobstorage'] = ['js/url-rewriter.js'];
}
