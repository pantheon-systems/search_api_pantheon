<?php

/**
 * @file
 * Bootstrap file for PHPUnit tests.
 */

$autoloader = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloader)) {
  require_once $autoloader;

  // Register contrib module namespaces for standalone unit tests.
  $vendor = __DIR__ . '/../vendor';
  $loader = new \Composer\Autoload\ClassLoader();
  $loader->addPsr4('Drupal\\search_api\\', "$vendor/drupal/search_api/src/");
  $loader->addPsr4('Drupal\\search_api_solr\\', "$vendor/drupal/search_api_solr/src/");
  $loader->register();
}
