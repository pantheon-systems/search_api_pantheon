<?php

/**
 * @file
 * Bootstrap file for PHPUnit tests.
 */

use Composer\Autoload\ClassLoader;

$autoloader = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloader)) {
  require_once $autoloader;

  $vendor = __DIR__ . '/../vendor';
  $loader = new ClassLoader();
  $loader->addPsr4('Drupal\\search_api\\', "$vendor/drupal/search_api/src/");
  $loader->addPsr4('Drupal\\search_api_solr\\', "$vendor/drupal/search_api_solr/src/");
  $loader->register();
}
