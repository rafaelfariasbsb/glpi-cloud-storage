<?php

/**
 * Azure Blob Storage - Configuration page
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Azureblobstorage\Config;

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

$config = Config::getPluginConfig();

$plugin = new Plugin();
$plugin->getFromDBbyDir('azureblobstorage');

TemplateRenderer::getInstance()->display(
    '@azureblobstorage/config.html.twig',
    [
        'config'    => $config,
        'plugin'    => $plugin,
        'canedit'   => Session::haveRight('config', UPDATE),
    ]
);
