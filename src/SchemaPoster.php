<?php

namespace Drupal\search_api_pantheon;

use Drupal\Core\Logger\LoggerChannelFactory;
use GuzzleHttp\Client;

/**
 * Class SchemaPoster.
 *
 * @package Drupal\search_api_pantheon
 */
class SchemaPoster {

  /**
   * Drupal\Core\Logger\LoggerChannelFactory definition.
   *
   * @var Drupal\Core\Logger\LoggerChannelFactory
   */
  protected $logger_factory;

  /**
   * GuzzleHttp\Client definition.
   *
   * @var GuzzleHttp\Client
   */
  protected $http_client;
  /**
   * Constructor.
   */
  public function __construct(LoggerChannelFactory $logger_factory, Client $http_client) {
    $this->logger_factory = $logger_factory;
    $this->http_client = $http_client;
  }


  public function postSchema($schema) {


    \Drupal::logger('my_module')->notice('asdf');
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
    $host = getenv('PANTHEON_INDEX_HOST');
    $path = 'sites/self/environments/' . $_ENV['PANTHEON_ENVIRONMENT'] . '/index';

    $client_cert = '../certs/binding.pem';
    $url = 'https://' . $host . '/' . $path;

    $file = fopen($schema, 'r');
// set URL and other appropriate options
    $opts = array(
      CURLOPT_URL => $url,
      CURLOPT_PORT => getenv('PANTHEON_INDEX_PORT'),
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

    \Drupal::logger('my_module')->notice(print_r($info, TRUE));


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









}
