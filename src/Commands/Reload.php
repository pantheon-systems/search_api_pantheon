<?php

namespace Drupal\search_api_pantheon\Commands;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\search_api_pantheon\Services\PantheonGuzzle;
use Drupal\search_api_pantheon\Services\SchemaPoster;
use Drush\Commands\DrushCommands;

/**
 * Drush Search Api Pantheon Schema Commands.
 */
class Reload extends DrushCommands {
  use LoggerChannelTrait;

  /**
   * Configured pantheon-solr-specific guzzle client.
   *
   * @var \Drupal\search_api_pantheon\Services\PantheonGuzzle
   */
  private PantheonGuzzle $pantheonGuzzle;

  /**
   * Configured pantheon-solr-specific schema poster class.
   *
   * @var \Drupal\search_api_pantheon\Services\SchemaPoster
   */
  private SchemaPoster $schemaPoster;

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   Injected by container.
   * @param \Drupal\search_api_pantheon\Services\PantheonGuzzle $pantheonGuzzle
   *   Injected by container.
   * @param \Drupal\search_api_pantheon\Services\SchemaPoster $schemaPoster
   *   Injected by Container.
   */
  public function __construct(
        LoggerChannelFactoryInterface $loggerChannelFactory,
        PantheonGuzzle $pantheonGuzzle,
        SchemaPoster $schemaPoster
    ) {
    $this->logger = $loggerChannelFactory->get('SearchAPIPantheon Drush');
    $this->pantheonGuzzle = $pantheonGuzzle;
    $this->schemaPoster = $schemaPoster;
  }

  /**
   * Search_api_pantheon:reloadSchema.
   *
   * @usage search-api-pantheon:reloadSchema
   *  Reload the latest schema
   *
   * @command search-api-pantheon:reloadSchema
   */
  public function reloadSchema() {
    try {
      $this->schemaPoster->reloadServer();
    }
    catch (\Exception $e) {
      $this->logger->error((string) $e);
    }
  }

}
