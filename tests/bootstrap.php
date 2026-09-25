<?php

/**
 * @file
 * Boots Drupal classes for isolated module tests without site settings.
 */
$root = getenv('DRUPAL_ROOT');

if (!$root) {
  $root = __DIR__;

  while (!is_file($root . '/core/lib/Drupal.php')) {
    $parent = dirname($root);

    if ($parent === $root) {
      throw new RuntimeException('Set DRUPAL_ROOT to the Drupal document root.');
    }

    $root = $parent;
  }
}

$loader = require $root . '/autoload.php';
$loader->addPsr4('Drupal\\country_access_filter\\', dirname(__DIR__) . '/src');
$loader->addPsr4('Drupal\\sqlite\\', $root . '/core/modules/sqlite/src');
$loader->addPsr4('Drupal\\page_cache\\', $root . '/core/modules/page_cache/src');
require_once $root . '/core/includes/bootstrap.inc';
require_once dirname(__DIR__) . '/country_access_filter.install';
require_once __DIR__ . '/src/AuditTestBase.php';
