<?php

namespace Drupal\search_api_pantheon;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\search_api_pantheon\Utility\Cores;
use Drupal\search_api_pantheon\Utility\SolrGuzzle;
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
  protected $loggerFactory;

  /**
   * GuzzleHttp\Client definition.
   *
   * @var GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * Constructor.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->loggerFactory = $logger_factory;
    $this->httpClient = SolrGuzzle::getConfiguredClientInterface();
  }

  /**
   * Post a schema file to to the Pantheon Solr server.
   */
  public function postSchema($schema): bool
  {
    try {
      // Check for empty schema.
      if (filesize($schema) < 1) {
        $this->loggerFactory
          ->get('PantheonSchemaPoster')
          ->error('Empty schema not posting');
        return false;
      }
      // Check for invalid XML.
      $schema_file = file_get_contents($schema);
      if (!@simplexml_load_string($schema_file)) {
        $this->loggerFactory
          ->get('PantheonSchemaPoster')
          ->error('Schema is not XML - not posting');
        return false;
      }
      $response = $this->httpClient->post(Cores::getBaseCoreUri(), [
        'body' => $schema_file
      ]);
      $success = in_array($response->getStatusCode(), [200, 201, 202, 203]);
      if (!$success) {
        $this->loggerFactory
          ->get('PantheonSchemaPoster')
          ->error('Schema failed to post: {reason}', ['reason' => $response->getReasonPhrase()]);
      }
      else {
        $this->loggerFactory
          ->get('PantheonSchemaPoster')
          ->info('Schema posted');
      }
      return $success;
    } catch(\Exception $e) {
      $this->loggerFactory
        ->get('PantheonSchemaPoster')
        ->error('Schema failed to post {reason}', ['reason' => $e->getMessage()]);
    }
    return false;
  }

}
