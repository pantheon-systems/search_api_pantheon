<?php

/**
 * @file
 * Bootstrap file for PHPUnit tests.
 */

// Try to load the Composer autoloader.
$autoloader = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloader)) {
  require_once $autoloader;
}
