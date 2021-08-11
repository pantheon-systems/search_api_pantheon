<?php

/**
 * @file
 * Bootstrap routines for testing.
 */

require_once dirname(__DIR__) . "/vendor/autoload.php";

if (
  !isset($_SERVER['PANTHEON_INDEX_SCHEME'])
  || !isset($_SERVER['PANTHEON_INDEX_HOST'])
  || !isset($_SERVER['PANTHEON_INDEX_PORT'])
) {
  throw new \Exception("Not correctly configured to test.");
}
