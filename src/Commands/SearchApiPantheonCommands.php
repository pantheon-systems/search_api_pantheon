<?php

namespace Drupal\search_api_pantheon\Commands;

use Drupal\search_api_pantheon\Services\PantheonGuzzle;
use Drupal\search_api_pantheon\Services\SchemaPoster;
use Drupal\search_api_pantheon\Utility\Cores;
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
class SearchApiPantheonCommands extends DrushCommands {

  /**
   * Search_api_pantheon:postSchema.
   *
   * @usage search_api_pantheon:postSchema {$server_id}
   *   Post the latest schema to the given Server.
   *   Default server ID = pantheon_solr8.
   *
   * @command search_api_pantheon:postSchema ${$server_id}
   * @aliases sapps
   */
  public function postSchema(string $server_id = 'pantheon_solr8') {
    try {
      $schema_poster = \Drupal::service('search_api_pantheon.schema_poster');
      $schema_poster->postSchema($server_id);
    }
    catch (\Exception $e) {
      $this->logger()->error((string) $e);
    }
  }

  /**
   * Search_api_pantheon:getSchemaFiles.
   *
   * @usage search_api_pantheon:getSchemaFiles
   *   get the latest schema for the default pantheon solr server
   *
   * @command search_api_pantheon:getSchemaFiles
   * @aliases sapgsf
   */
  public function outputFiles(array $files) {
    $files = $this->getSolrFiles();
    $temp_dir = ($_SERVER['TMPDIR'] ?? getcwd()) . DIRECTORY_SEPARATOR . uniqid('search_api_pantheon-');
    $this->output()->writeln("outputingg files to $temp_dir");
    $zip_archive = new \ZipArchive();
    $zip_archive->open($temp_dir . '.zip', \ZipArchive::CREATE);
    foreach ($files as $filename => $file_contents) {
      $zip_archive->addFromString($filename, $file_contents);
    }
    $zip_archive->close();
    return $temp_dir;
  }

  /**
   * Search_api_pantheon:test.
   *
   * @usage search_api_pantheon:test
   *   connect to the solr8 server
   *
   * @command search_api_pantheon:test
   * @aliases sapt
   */
  public function testInstall() {
    $this->logger()->notice('Index SCHEME Value: {var}', [
      'var' => isset($_SERVER['PANTHEON_INDEX_SCHEME'])
      ? getenv('PANTHEON_INDEX_SCHEME')
      : 'https',
    ]);
    $this->logger()->notice('Index HOST Value:   {var}', [
      'var' => getenv('PANTHEON_INDEX_HOST'),
    ]);
    $this->logger()->notice('Index PORT Value:   {var}', [
      'var' => getenv('PANTHEON_INDEX_PORT'),
    ]);
    $this->logger()->notice('Index CORE Value:   {var}', [
      'var' => Cores::getMyCoreName(),
    ]);
    $this->logger()->notice('Index PATH Value:   {var}', [
      'var' => isset($_SERVER['PANTHEON_INDEX_PATH'])
      ? getenv('PANTHEON_INDEX_PATH')
      : '/',
    ]);
    $this->logger()->notice('Testing bare Connection...');
    $response = $this->pingSolrHost();
    $this->logger()->notice('Ping Received Response? {var}', [
      'var' => $response instanceof ResultInterface ? '✅' : '❌',
    ]);
    $this->logger()->notice('Response http status == 200? {var}', [
      'var' => $response->getResponse()->getStatusCode() === 200 ? '✅' : '❌',
    ]);
    $this->logger()->notice('Response status == 0 (no issue)? {var}', [
      'var' => $response->getStatus() === 0 ? '✅' : '❌',
    ]);
    $this->logger()->notice('Drupal Integration...');
    $manager = \Drupal::getContainer()->get(
      'plugin.manager.search_api_solr.connector'
    );
    $connectors = array_keys($manager->getDefinitions() ?? []);
    $this->logger()->notice('Pantheon Connector Plugin Exists? {var}', [
      'var' => in_array('pantheon', $connectors) ? '✅' : '❌',
    ]);
    $connectorPlugin = $manager->createInstance('pantheon');
    $this->logger()->notice('Connector Plugin Instance created {var}', [
      'var' => $connectorPlugin instanceof SolrConnectorInterface ? '✅' : '❌',
    ]);
    $this->logger()->notice('Using connector plugin to get endpoint...');
    $connectorPlugin->setLogger($this->logger);

    $info = $connectorPlugin->getServerInfo();

    $this->logger()->notice('Solr Server Version {var}', [
      'var' => $info['lucene']['solr-spec-version'] ?? '❌',
    ]);
    $pg = \Drupal::service('search_api_pantheon.pantheon_guzzle');
    if (!$pg instanceof PantheonGuzzle) {
      throw new \Exception('Cannot instantiate SolrGuzzle class from service id');
    }
    $indexSingleItemQuery = $this->indexSingleItem();
    $this->logger()->notice('Solr Update index with one document Response: {code} {reason}', [
      'code' => $indexSingleItemQuery->getResponse()->getStatusCode(),
      'reason' => $indexSingleItemQuery->getResponse()->getStatusMessage(),
    ]);
    $indexedStats = $pg->getQueryResult('admin/luke', [
      'query' => [
        'stats' => 'true',
      ],
    ]);
    $this->logger()->notice('Solr Index Stats: {stats}', [
      'stats' => print_r($indexedStats['index'], TRUE),
    ]);
    $beans = $pg->getQueryResult('admin/mbeans', [
      'query' => [
        'stats' => 'true',
      ],
    ]);

    $this->logger()->notice('Mbeans Stats: {stats}', [
      'stats' => print_r($beans['solr-mbeans'], TRUE),
    ]);
    $this->logger()->notice(
      "If there's an issue with the connection, it would have shown up here."
    );
  }

  /**
   * @return \Solarium\Core\Query\Result\ResultInterface|\Solarium\QueryType\Ping\Result|void
   */
  protected function pingSolrHost() {
    try {
      $pg = \Drupal::service('search_api_pantheon.pantheon_guzzle');
      $ping = $pg->getSolrClient()->createPing();
      return $pg->getSolrClient()->ping($ping);
    }
    catch (\Exception $e) {
      exit($e->getMessage());
    }
    catch (\Throwable $t) {
      exit($t->getMessage());
    }
  }

  /**
   * @return \Solarium\Core\Query\Result\ResultInterface|\Solarium\QueryType\Update\Result
   */
  protected function indexSingleItem() {
    // Create a new document.
    $document = new UpdateDocument();

    // Set a field value as property.
    $document->id = 15;

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
    $query = new UpdateQuery();
    $query->addDocument($document);
    $query->addCommit();
    $pg = \Drupal::service('search_api_pantheon.pantheon_guzzle');
    // Run it, the result should be a new document in the Solr index.
    return $pg->getSolrClient()->update($query);
  }

  /**
   * View a Schema File.
   *
   * @command search_api_pantheon:post_file
   * @aliases sappf
   * @usage sappf schema.xml
   * @usage search_api_pantheon:post_file elevate.xml
   *
   * @param string $filename
   *   Filename to post.
   *
   * @throws \Exception
   */
  public function postSingleSchemaFile($filename = 'schema.xml')
  {
    $contents = file_get_contents($filename);
    $schemaPoster = \Drupal::service('search_api_pantheon.schema_poster');
    if (!$schemaPoster instanceof SchemaPoster) {
      throw new \Exception('Cant get Schema Poster class. Something is wrong with the container.');
    }
    $currentSchema = $schemaPoster->uploadSchemaFile(basename($filename), $contents);
    $this->logger()->notice($currentSchema);
  }

  /**
   * View a Schema File.
   *
   * @command search_api_pantheon:view_schema
   * @aliases sapvs
   * @usage sapvs schema.xml
   * @usage search_api_pantheon:view_schema elevate.xml
   *
   * @param string $filename
   *   Filename to retrieve.
   *
   * @throws \Exception
   */
  public function viewSchema($filename = "schema.xml") {
    $schemaPoster = \Drupal::service('search_api_pantheon.schema_poster');
    if (!$schemaPoster instanceof SchemaPoster) {
      throw new \Exception('Cant get Schema Poster class. Something is wrong with the container.');
    }
    $currentSchema = $schemaPoster->viewSchema('pantheon_solr8', $filename);
    $this->logger()->notice($currentSchema);
  }

}
