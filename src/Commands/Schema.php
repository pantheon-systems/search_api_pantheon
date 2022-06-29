<?php

namespace Drupal\search_api_pantheon\Commands;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelTrait;
use Drupal\search_api_pantheon\Plugin\SolrConnector\PantheonSolrConnector;
use Drupal\search_api_pantheon\Services\PantheonGuzzle;
use Drupal\search_api_pantheon\Services\SchemaPoster;
use Drush\Commands\DrushCommands;

/**
 * Drush Search Api Pantheon Schema Commands.
 */
class Schema extends DrushCommands {
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
   * Search_api_pantheon:postSchema.
   *
   * @usage search_api_pantheon:postSchema {$server_id}
   *   Post the latest schema to the given Server.
   *   Default server ID = pantheon_solr8.
   *
   * @command search-api-pantheon:postSchema ${$server_id}
   * @aliases sapps
   */
  public function postSchema(?string $server_id = NULL) {
    // @todo: Update to support arbitrary path.
    if (!$server_id) {
      $server_id = PantheonSolrConnector::getDefaultEndpoint();
    }
    try {
      $this->schemaPoster->postSchema($server_id);
    }
    catch (\Exception $e) {
      $this->logger()->error((string) $e);
    }
  }

  /**
   * View a Schema File.
   *
   * @param string $filename
   *   Filename to retrieve.
   *
   * @command search-api-pantheon:view-schema
   * @aliases sapvs
   * @usage sapvs schema.xml
   * @usage search-api-pantheon:view-schema elevate.xml
   *
   * @throws \Exception
   * @throws \Psr\Http\Client\ClientExceptionInterface
   */
  public function viewSchema(string $filename = 'schema.xml') {
    $currentSchema = $this->schemaPoster->viewSchema($filename);
    $this->logger()->notice($currentSchema);
  }

}
