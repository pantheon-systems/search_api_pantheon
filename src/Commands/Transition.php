<?php

namespace Drupal\search_api_pantheon\Commands;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\search_api_pantheon\Services\Endpoint;
use Drupal\search_api_pantheon\Services\PantheonGuzzle;
use Drupal\search_api_pantheon\Services\SolariumClient;
use Drupal\search_api_solr\SolrConnectorInterface;
use Drush\Commands\DrushCommands;
use Solarium\Core\Query\Result\ResultInterface;
use Solarium\QueryType\Update\Query\Document as UpdateDocument;
use Solarium\QueryType\Update\Query\Query as UpdateQuery;

/**
 * A Drush command file.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 *
 * See these files for an example of injecting Drupal services:
 *   - http://cgit.drupalcode.org/devel/tree/src/Commands/DevelCommands.php
 *   - http://cgit.drupalcode.org/devel/tree/drush.services.yml
 */
class Transition extends DrushCommands {

  protected PantheonGuzzle $pantheonGuzzle;
  protected Endpoint $endpoint;
  protected SolariumClient $solr;

  /**
   * Class Constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   Injected by container.
   * @param \Drupal\search_api_pantheon\Services\PantheonGuzzle $pantheonGuzzle
   *   Injected by container.
   * @param \Drupal\search_api_pantheon\Services\Endpoint $endpoint
   *   Injected by container.
   * @param \Drupal\search_api_pantheon\Services\SolariumClient $solariumClient
   *   Injected by container.
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerChannelFactory,
    PantheonGuzzle $pantheonGuzzle,
    Endpoint $endpoint,
    SolariumClient $solariumClient
  ) {
    $this->logger = $loggerChannelFactory->get('SearchAPIPantheon Drush');
    $this->pantheonGuzzle = $pantheonGuzzle;
    $this->endpoint = $endpoint;
    $this->solr = $solariumClient;
  }

  /**
   * search_api_pantheon:transition
   *
   * @usage search-api-pantheon:transition
   *   Transition solr 3 server to solr 8.
   *
   * @command search-api-pantheon:transition
   * @aliases sapt
   *
   * @param string $source
   *    Source site.env.
   * @param string $index
   *    Index ID being transitioned.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   * @throws \JsonException
   * @throws \Exception
   */
  public function transition(string $source, string $index) {
    try {
      $filename = DRUPAL_ROOT . '/../index_server.json';
      $contents = json_decode(
        file_get_contents($filename),
      true, 512, JSON_THROW_ON_ERROR
      );
      \Kint::dump(get_defined_vars());

    }
    catch (\Exception $e) {
      \Kint::dump($e);
      $this->logger->emergency("There's a problem somewhere...");
      exit(1);
    }
    catch (\Throwable $t) {
      \Kint::dump($t);
      $this->logger->emergency("There's a problem somewhere...");
      exit(1);
    }
    $this->logger()->notice(
      "If there's an issue with the connection, it would have shown up here. You should be good to go!"
    );
  }



}
