<?php

namespace Drupal\search_api_pantheon;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_pantheon\Plugin\SolrConnector\PantheonSolrConnector;
use Drupal\search_api_solr\SolrBackendInterface;

/**
 * A Drush command file.
 */
trait GetPantheonSolrServerTrait {

  /**
   * The search API server storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $storage;

  /**
   * Get a backend with pantheon connector.
   *
   * @param string|null $server_id
   *   Optional server id. If not provided, the first server with the
   *   pantheon connector will be returned.
   *
   * @return \Drupal\search_api\ServerInterface
   */
  protected function getPantheonSolrServer(?string $server_id = NULL): ServerInterface {
    if (!$server_id) {
      $ids = $this->storage->getQuery()
        ->condition('backend_config.connector', 'pantheon')
        ->execute();
      $server_id = reset($ids);
    }
    return $this->storage->load($server_id);
  }

  protected function getPantheonSolrConnector(?ServerInterface $server = NULL): PantheonSolrConnector {
    if (!$server) {
      $server = $this->getPantheonSolrServer();
    }
    $backend = $server->getBackend();
    assert($backend instanceof SolrBackendInterface);
    $connector = $backend->getSolrConnector();
    assert($connector instanceof PantheonSolrConnector);
    return $connector;
  }

}
