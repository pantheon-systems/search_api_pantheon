<?php

namespace Drupal\search_api_pantheon\Commands;

use Drupal\search_api_pantheon\Endpoint;
use Drupal\search_api_pantheon\Utility\Cores;
use Drupal\search_api_pantheon\Utility\SolrGuzzle;
use Drupal\search_api_solr\SolrConnectorInterface;
use Drush\Commands\DrushCommands;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Util\Exception;
use Solarium\Client;
use Solarium\Core\Query\Result\ResultInterface;
use Solarium\QueryType\Update\Query\Document as UpdateDocument;
use Solarium\QueryType\Update\Query\Query as UpdateQuery;
use Symfony\Component\EventDispatcher\EventDispatcher;

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
class SearchApiPantheonCommands extends DrushCommands
{

  /**
   * search_api_pantheon:postSchema
   *
   * @usage search_api_pantheon:postSchema {$server_id}
   *   Post the latest schema to the given Server. Default server ID = pantheon_solr8.
   *
   * @command search_api_pantheon:postSchema ${$server_id}
   * @aliases sapps
   */
    public function postSchema(string $server_id = 'pantheon_solr8')
    {
        try {
            $schema_poster = \Drupal::service('search_api_pantheon.schema_poster');
            $schema_poster->postSchema($server_id);
        } catch (\Exception $e) {
            $this->logger()->error((string)$e);
        }
    }

  /**
   * search_api_pantheon:getSchemaFiles
   *
   * @usage search_api_pantheon:getSchemaFiles
   *   get the latest schema for the default pantheon solr server
   *
   * @command search_api_pantheon:getSchemaFiles
   * @aliases sapgsf
   */
    public function outputFiles(array $files)
    {
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
   * search_api_pantheon:test
   *
   * @usage search_api_pantheon:test
   *   connect to the solr8 server
   *
   * @command search_api_pantheon:test
   * @aliases sapt
   */
    public function testInstall()
    {
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

        $indexSingleItemQuery = $this->indexSingleItem();
        $this->logger()->notice('Solr Update index with one document Response: {code} {reason}', [
        'code' => $indexSingleItemQuery->getResponse()->getStatusCode(),
        'reason' => $indexSingleItemQuery->getResponse()->getStatusMessage(),
        ]);
        $indexedStats = $this->getIndexStats();
        $this->logger()->notice('Solr Index Stats: {stats}', [
        'stats' => print_r($indexedStats, true),
        ]);

        \Kint::dump(get_defined_vars());

        $this->logger()->notice(
            "If there's an issue with the connection, it would have shown up here."
        );
    }

  /**
   * @return \Solarium\Core\Query\Result\ResultInterface|\Solarium\QueryType\Ping\Result|void
   */
    protected function pingSolrHost()
    {
        try {
            $solr = $this->getSolrClient();
            $ping = $solr->createPing();
            return $solr->ping($ping);
        } catch (\Exception $e) {
            exit($e->getMessage());
        } catch (\Throwable $t) {
            exit($t->getMessage());
        }
    }

  /**
   * @return \Solarium\Client
   */
    protected function getSolrClient(): Client
    {
        $config = [
        'endpoint' => [],
        ];
        $solr = new Client(
            SolrGuzzle::getPsr18Adapter(true),
            new EventDispatcher(),
            $config
        );
        $endpoint = new Endpoint([
                               'collection' => null,
                               'leader' => false,
                               'timeout' => 5,
                               'solr_version' => '8',
                               'http_method' => 'AUTO',
                               'commit_within' => 1000,
                               'jmx' => false,
                               'solr_install_dir' => '',
                               'skip_schema_check' => false,
                             ]);
        $endpoint->setKey('pantheon');
        $solr->addEndpoint($endpoint);
        return $solr;
    }

  /**
   * @return \Solarium\Core\Query\Result\ResultInterface|\Solarium\QueryType\Update\Result
   */
    protected function indexSingleItem()
    {
      // create a new document
        $document = new UpdateDocument();

      // set a field value as property
        $document->id = 15;

      // set a field value as array entry
        $document['population'] = 120000;

      // set a field value with the setField method, including a boost
        $document->setField('name', 'example doc', 3);

      // add two values to a multivalue field
        $document->addField('countries', 'NL');
        $document->addField('countries', 'UK');
        $document->addField('countries', 'US');

      // example: add / remove field with methods
        $document->setField('dummy', 10);
        $document->removeField('dummy');

      // example: add / remove field with methods by setting NULL value
        $document->setField('dummy', 10);
        $document->setField('dummy', null); //this removes the field

      // set a document boost value
        $document->setFieldBoost('name', 2.5);

      // set a field boost
        $document->setFieldBoost('population', 4.5);

      // add it to the update query and also add a commit
        $query = new UpdateQuery();
        $query->addDocument($document);
        $query->addCommit();

      // run it, the result should be a new document in the Solr index
        return $this->getSolrClient()->update($query);
    }

    public function getIndexStats(): array
    {
        $client = SolrGuzzle::getConfiguredClientInterface();
        $uri = new Uri(Cores::getBaseCoreUri() . 'admin/luke?stats=true');
        $this->logger()->notice("Url: " . (string) $uri);
        $request = new Request('get', $uri);
        $response = $client->sendRequest($request);
        if ($response->getStatusCode() !== 200) {
            throw new Exception($response->getReasonPhrase());
        }
        $stats = json_decode(
            $response->getBody(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        return $stats['index'] ?? [];
    }
}
