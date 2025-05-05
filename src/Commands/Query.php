<?php

namespace Drupal\search_api_pantheon\Commands;

use Drupal\search_api_solr\SearchApiSolrException;
use Drupal\search_api_solr\SolrBackendInterface;

/**
 * A Drush command file.
 */
class Query extends PantheonCommandBase {

  /**
   * Search_api_pantheon:select.
   *
   * @usage search-api-pantheon:select <query>
   *   Runs a select query against Pantheon Solr.
   *
   * @command search-api-pantheon:select
   *
   * @option wt Output format
   * @option rows Number of rows to return
   * @option qf Query fields
   * @option defType Default search type
   * @option omitHeader Do not output header
   * @option fields Fields to return
   *
   * @aliases saps
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   * @throws \JsonException
   * @throws \Exception
   */
  public function select(
    $query,
    $options = [
      'wt' => 'json',
      'rows' => 10,
      'qf' => '',
      'defType' => 'edismax',
      'omitHeader' => 'true',
      'fields' => 'ss_search_api_id,ss_search_api_language,score,hash',
    ],
  ) {
    $this->logger->notice('Running a select query against Pantheon Solr.');

    $this->logger->notice('Query: ' . urldecode($query));
    $options['query'] = urldecode($query);

    $backend = $this->getPantheonSolrServer()->getBackend();
    assert($backend instanceof SolrBackendInterface);
    $connector = $backend->getSolrConnector();
    $query_object = $connector->getSelectQuery();
    $query_object->setOptions($options);
    $query_object->setResponseWriter($options['wt']);

    if ($options['defType']) {
      $query_object->addParam('defType', $options['defType']);
    }
    if ($options['omitHeader']) {
      $query_object->setOmitHeader(TRUE);
    }
    if ($options['qf']) {
      $query_object->addParam('qf', $options['qf']);
    }

    $query_object->addParam('TZ', 'UTC');

    try {
      $result = $connector->execute($query_object);
      $this->logger->notice('Query executed successfully.');
      $this->logger->notice('Query result:');
      return json_encode($result->getData(), \JSON_PRETTY_PRINT);
    }
    catch (SearchApiSolrException $e) {
      $this->logger->error('Query failed with message:');

      return json_encode(['error' => $e->getMessage()], \JSON_PRETTY_PRINT);
    }
  }

  /**
   * Force Solr server cleanup if hash has changed.
   *
   * @usage search-api-pantheon:force-cleanup <server_id>
   *   Force server cleanup by updating hash and running a delete query on given server.
   *
   * @command search-api-pantheon:force-cleanup
   *
   * @aliases sapfc
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   * @throws \Exception
   */
  public function forceServerClean($server_id = '') {
    $server = $this->getPantheonSolrServer($server_id);
    $connector = $this->getPantheonSolrConnector($server);
    $properties['status'] = TRUE;
    $properties['read_only'] = FALSE;
    foreach ($server->getIndexes($properties) as $index) {
      // We are sure this server is only available to this env so it is safe
      // to delete all of the server contents.
      $query = '*:*';

      $update_query = $connector->getUpdateQuery();
      $update_query->addDeleteQuery($query);
      $connector->update($update_query, $server->getBackend()->getCollectionEndpoint($index));
      \Drupal::state()->set('search_api_solr.' . $index->id() . '.last_update', \Drupal::time()->getCurrentTime());
    }
  }

}
