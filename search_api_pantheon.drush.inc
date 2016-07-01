<?php

/**
 * @file
 * Drush commands for Search API.
 */

use Drupal\search_api\Entity\Index;
use Drupal\search_api\Entity\Server;
use Drupal\search_api\IndexBatchHelper;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;

/**
 * Implements hook_drush_command().
 */
function search_api_pantheon_drush_command() {
  $items = array();

  $items['search-api-pantheon-schema-post'] = array(
    'description' => 'post the schema',
  );

  return $items;
}

/**
 * This drush command is a hacky way to post a schema. This function needs to be
 * cleaned up and moved.
 */
function drush_search_api_pantheon_schema_post() {

  $schema_path = drupal_get_path('module', 'search_api_solr') . '/solr-conf/4.x/schema.xml';
}




/**
 * @param string $schema
 *   Path to local schema XML
 *
 * @return string
 *   Response from Solr.
 */
function pantheon_apachesolr_post_schema_exec($schema) {
  // Check for empty schema.
  if (filesize($schema) < 1) {
    watchdog('pantheon_apachesolr', 'Empty schema !schema - not posting', array(
      '!schema' => $schema,
    ), WATCHDOG_ERROR);
    return NULL;
  }
  // Check for invalid XML.
  $schema_file = file_get_contents($schema);
  if (!@simplexml_load_string($schema_file)) {
    watchdog('pantheon_apachesolr', 'Schema !schema is not XML - not posting', array(
      '!schema' => $schema,
    ), WATCHDOG_ERROR);
    return NULL;
  }

  $ch = curl_init();
  $host = getenv( 'PANTHEON_INDEX_HOST' );
	// @todo, pantheon env, dev
  $path = 'sites/self/environments/ '  . $_ENV['PANTHEON_ENVIRONMENT'] . ' /index';

  $client_cert = '../certs/binding.pem';
  $url = 'https://'. $host .'/'. $path;

  $file = fopen($schema, 'r');
  // set URL and other appropriate options
  $opts = array(
    CURLOPT_URL => $url,
    CURLOPT_PORT => getenv( 'PANTHEON_INDEX_PORT' ),
    CURLOPT_RETURNTRANSFER => 1,
    CURLOPT_SSLCERT => $client_cert,
    CURLOPT_HTTPHEADER => array('Content-type:text/xml; charset=utf-8'),
    CURLOPT_PUT => TRUE,
    CURLOPT_BINARYTRANSFER => 1,
    CURLOPT_INFILE => $file,
    CURLOPT_INFILESIZE => filesize($schema),
  );
  curl_setopt_array($ch, $opts);
  $response = curl_exec($ch);
  $info = curl_getinfo($ch);
  $success_codes = array(
    '200',
    '201',
    '202',
    '204'
  );

//drush_print_r($info);
drush_print_r($response);

  $success = (in_array($info['http_code'], $success_codes));
  fclose($file);
  if (!$success) {
/// @todo watchdog
  }
  else {
    //variable_set('pantheon_apachesolr_schema', $schema);
  }

  return $success;
}
