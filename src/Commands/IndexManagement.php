<?php

declare(strict_types=1);

namespace Drupal\search_api_pantheon\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_solr\Controller\SolrConfigSetController;
use Drupal\search_api_solr\SearchApiSolrException;
use Drupal\search_api_solr\SolrBackendInterface;
use Drupal\search_api_solr\Utility\SolrCommandHelper;
use Drush\Commands\DrushCommands;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Defines Drush commands for managing Pantheon's Solr index.
 *
 * @see \Drupal\search_api_solr_devel\Commands\SearchApiSolrDevelCommands
 */
final class IndexManagement extends DrushCommands {

  private SolrCommandHelper $commandHelper;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, ModuleHandlerInterface $moduleHandler, EventDispatcherInterface $eventDispatcher, SolrConfigSetController $solrConfigSetController) {
    parent::__construct();
    $this->commandHelper = new SolrCommandHelper($entityTypeManager, $moduleHandler, $eventDispatcher, $solrConfigSetController);
  }

  /**
   * {@inheritdoc}
   */
  public function setLogger(LoggerInterface $logger): void {
    parent::setLogger($logger);
    $this->commandHelper->setLogger($logger);
  }

  /**
   * Deletes *all* documents on a Solr search server (including all indexes).
   *
   * @param string $server_id
   *   The ID of the server.
   *
   * @command search-api-pantheon:sapi-delete-all
   *
   * @usage search-api-pantheon:sapi-delete-all server_id
   *   Deletes *all* documents on server_id.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   * @throws \Drupal\search_api\SearchApiException
   */
  public function deleteAll(string $server_id): void {
    $servers = $this->commandHelper->loadServers([$server_id]);
    if ($server = \reset($servers)) {
      $backend = $server->getBackend();
      if ($backend instanceof SolrBackendInterface) {
        $connector = $backend->getSolrConnector();
        $update_query = $connector->getUpdateQuery();
        $update_query->addDeleteQuery('*:*');
        $connector->update($update_query);

        foreach ($server->getIndexes() as $index) {
          if ($index->status() && !$index->isReadOnly()) {
            if ($connector->isCloud()) {
              $connector->update($update_query, $backend->getCollectionEndpoint($index));
            }
            $index->reindex();
          }
        }
      }
      else {
        throw new SearchApiSolrException("The given server ID doesn't use the Solr backend.");
      }
    }
    else {
      throw new SearchApiException("The given server ID doesn't exist.");
    }
  }

}
