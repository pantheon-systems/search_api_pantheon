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

}
