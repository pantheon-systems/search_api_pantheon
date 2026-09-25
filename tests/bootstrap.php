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
 * ignores the arguments it is called with and returns whatever curl options
 * the running test has told it to.
 */
function pantheon_curl_setup(): array {
  return [NULL, PantheonSolrCurlTest::$prependCurlOptions];
}
