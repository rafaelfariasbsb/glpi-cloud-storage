<?php

/**
 * Cloud Storage - Configuration page
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Cloudstorage\Config;

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

Html::header(
    __('Cloud Storage'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins'
);

$config = Config::getPluginConfig();

$plugin = new Plugin();
$plugin->getFromDBbyDir('cloudstorage');

TemplateRenderer::getInstance()->display(
    '@cloudstorage/config.html.twig',
    [
        'config'    => $config,
        'plugin'    => $plugin,
        'canedit'   => Session::haveRight('config', UPDATE),
    ]
);

Html::footer();
