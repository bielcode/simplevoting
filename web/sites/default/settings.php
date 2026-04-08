<?php

/**
 * @file
 * Drupal site-specific configuration file.
 */

// Localização padrão do config sync (obrigatório no Drupal 10).
$settings['config_sync_directory'] = '../config/sync';

// Inclui settings.local.php quando presente (ambiente Lando, dev, etc.).
if (file_exists($app_root . '/' . $site_path . '/settings.local.php')) {
  include $app_root . '/' . $site_path . '/settings.local.php';
}
