<?php

/**
 * @file
 * Bootstrap routines for testing.
 */

use Drupal\Tests\search_api_pantheon\Unit\PantheonSolrCurlTest;

$loader = require dirname(__DIR__) . '/vendor/autoload.php';

// Drupal's own class loader normally finds modules; register the ones this
// module extends so that its classes can be loaded without a Drupal install.
$loader->addPsr4('Drupal\\search_api_solr\\', dirname(__DIR__) . '/vendor/drupal/search_api_solr/src');
$loader->addPsr4('Drupal\\search_api\\', dirname(__DIR__) . '/vendor/drupal/search_api/src');

/**
 * Stands in for pantheon_curl_setup() from the Pantheon platform PHP prepend.
 *
 * On Pantheon this function is always defined before Drupal loads. Here it
 * ignores the arguments it is called with and returns whatever curl options
 * the running test has told it to.
 */
function pantheon_curl_setup(): array {
  return [NULL, PantheonSolrCurlTest::$prependCurlOptions];
}
