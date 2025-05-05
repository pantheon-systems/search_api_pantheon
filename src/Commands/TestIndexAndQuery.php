<?php

namespace Drupal\search_api_pantheon\Commands;

use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Solarium\Core\Query\Result\ResultInterface;
use Solarium\QueryType\Update\Query\Document as UpdateDocument;
use Symfony\Component\Yaml\Yaml;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\search_api\Entity\Index;

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
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TestIndexAndQuery extends PantheonCommandBase {

  /**
   * Class Constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected ConfigInstallerInterface $configInstaller,
  ) {
    parent::__construct($entityTypeManager);
  }

  /**
   * Search_api_pantheon:test-index-and-query.
   *
   * @usage search-api-pantheon:test-index-and-query
   *   Connect to the solr8 server to index a single item and immediately query it.
   *
   * @command search-api-pantheon:test-index-and-query
   * @aliases sap-tiq
   */
  public function testIndexAndQuery() {
    try {
      $response = $this->pingSolrHost();
      $this->logger->notice('Ping Received Response? {var}', [
        'var' => $response ? '✅' : '❌',
      ]);
      // Create a new random index.
      $this->logger->notice("Creating temporary index...");
      $module_root = \Drupal::service('extension.list.module')->getPath('search_api_pantheon');
      $value = Yaml::parseFile($module_root . '/.ci/config/search_api.index.solr_index.yml');

      // Update index from config.
      if (isset($value['datasource_settings']["entity:node"])) {
        unset($value['datasource_settings']["entity:node"]);
      }
      $value['datasource_settings']['solr_document'] = [
        'id_field' => 'id',
        'request_handler' => '',
        'default_query' => '*:*',
        'label_field' => '',
        'language_field' => '',
        'url_field' => '',
      ];
      $index_id = $value['id'] . '_' . uniqid();
      $value['id'] = $index_id;
      $directory = 'temporary://' . $index_id;
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface:: CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $yaml = Yaml::dump($value);
      file_put_contents($directory . '/search_api.index.' . $index_id . '.yml', $yaml);
      $config_source = new FileStorage($directory);
      $this->configInstaller->installOptionalConfig($config_source);
      $index = Index::load($index_id);
      $index->save();
      $this->logger->notice("Temporary index created.");

      $indexSingleItemQuery = $this->indexSingleItem($index->id());
      $this->logger->notice('Solr Update index with one document Response: {code} {reason}', [
        'code' => $indexSingleItemQuery->getResponse()->getStatusCode(),
        'reason' => $indexSingleItemQuery->getResponse()->getStatusMessage(),
      ]);

      if ($indexSingleItemQuery->getResponse()->getStatusCode() !== 200) {
        throw new \Exception('Cannot unable to index simple item. Have you created an index for the server?');
      }

      $this->logger->notice("Querying Solr for the indexed item...");
      $data = $this->getPantheonSolrConnector()->getLuke();
      $numDocs = $data['index']['numDocs'] ?? -1;
      if ($numDocs === 1) {
        $this->logger->notice('We got exactly 1 result ✅');
      }
      else {
        $this->logger->notice('We did not get exactly 1 result ❌ (numDocs = {numDocs})', [
          'numDocs' => $numDocs,
        ]);
      }
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
    finally {
      if ($index) {
        $this->logger->notice('Removing content and index {index_id}', [
          'index_id' => $index->id(),
        ]);

        $this->deleteSingleItem('1-' . $index->id());
        $index->delete();
      }
    }
    $this->logger->notice(
      "If there's an issue with Solr, it would have shown up here. You should be good to go!"
    );
  }

  /**
   * Pings the Solr host.
   *
   * @usage search-api-pantheon:ping
   *   Ping the solr server.
   *
   * @command search-api-pantheon:ping
   * @aliases sapp
   *
   * @return mixed
   *   The latency in milliseconds if the core can be accessed,
   *   otherwise FALSE.
   */
  public function pingSolrHost(): mixed {
    try {
      return $this->getPantheonSolrConnector()->pingServer();
    }
    catch (\Exception $e) {
    }
    catch (\Throwable $t) {
    }
    return FALSE;
  }

  /**
   * Indexes a single item.
   *
   * @param string $index_id
   *   ID of index to add this item to.
   *
   * @return \Solarium\Core\Query\Result\ResultInterface|\Solarium\QueryType\Update\Result
   *   The result.
   */
  protected function indexSingleItem(string $index_id) {
    // Create a new document.
    $document = new UpdateDocument();

    // Set a field value as property.
    $document->id = "1-" . $index_id;

    $document->index_id = $index_id;

    // Set a field value as array entry.
    $document['population'] = 120000;

    // Set a field value with the setField method, including a boost.
    $document->setField('name', 'example doc', 3);

    // Add two values to a multivalue field.
    $document->addField('countries', 'NL');
    $document->addField('countries', 'UK');
    $document->addField('countries', 'US');

    // example: add / remove field with methods.
    $document->setField('dummy', 10);
    $document->removeField('dummy');

    // example: add / remove field with methods by setting NULL value.
    $document->setField('dummy', 10);
    // This removes the field.
    $document->setField('dummy', NULL);

    // Set a document boost value.
    $document->setFieldBoost('name', 2.5);

    // Set a field boost.
    $document->setFieldBoost('population', 4.5);

    // Add it to the update query and also add a commit.
    $connector = $this->getPantheonSolrConnector();
    $query = $connector->getUpdateQuery();
    $query->addDocument($document);
    // Make a hard commit.
    $query->addCommit();

    // Run it, the result should be a new document in the Solr index.
    return $connector->update($query);
  }

  /**
   * Indexes a single item.
   *
   * @param string $item_id
   *   ID of the item to delete.
   *
   * @return \Solarium\Core\Query\Result\ResultInterface
   *   The result.
   */
  protected function deleteSingleItem(string $item_id): ResultInterface {
    // Add it to the update query and also add a commit.
    $connector = $this->getPantheonSolrConnector();
    $query = $connector->getUpdateQuery();
    $query->addDeleteQuery('id:' . $item_id);
    // Make a hard commit.
    $query->addCommit();
    // Run it, the result should be a new document in the Solr index.
    return $connector->update($query);
  }

}
