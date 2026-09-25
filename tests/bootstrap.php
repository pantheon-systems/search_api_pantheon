<?php

/**
 * @file
 * Bootstrap routines for testing.
 */

use Drupal\Tests\search_api_pantheon\Unit\PantheonSolrCurlTest;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Stands in for pantheon_curl_setup() from the Pantheon platform PHP prepend.
 *
 * On Pantheon this function is always defined before Drupal loads. Here it
 * returns whatever curl options the running test has told it to.
 */
function pantheon_curl_setup($url, $data = NULL, $port = 443, $verb = 'GET') {
  return [NULL, PantheonSolrCurlTest::$prependCurlOptions];
}
