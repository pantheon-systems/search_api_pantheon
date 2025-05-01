<?php

namespace Drupal\search_api_pantheon\Commands;

use Drupal\search_api\Entity\Server;
use Drupal\search_api_pantheon\Plugin\SolrConnector\PantheonSolrConnector;
use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;

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
class Diagnose extends DrushCommands {

  /**
   * Search_api_pantheon:diagnose.
   *
   * @usage search-api-pantheon:diagnose
   *   Connect to the solr8 server.
   *
   * @command search-api-pantheon:diagnose
   * @aliases sapd
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   * @throws \JsonException
   * @throws \Exception
   */
  public function solrDiagnose(): void {
    try {
      $drupal_root = \DRUPAL_ROOT;
      $pantheon_yml_contents = '';
      if (file_exists($drupal_root . '/pantheon.yml')) {
        $pantheon_yml_contents = file_get_contents($drupal_root . '/pantheon.yml');
      }
      elseif (file_exists($drupal_root . '/../pantheon.yml')) {
        $pantheon_yml_contents = file_get_contents($drupal_root . '/../pantheon.yml');
      }
      $pantheon_upstream_yml_contents = '';
      if (file_exists($drupal_root . '/pantheon.upstream.yml')) {
        $pantheon_upstream_yml_contents = file_get_contents($drupal_root . '/pantheon.upstream.yml');
      }
      elseif (file_exists($drupal_root . '/../pantheon.upstream.yml')) {
        $pantheon_upstream_yml_contents = file_get_contents($drupal_root . '/../pantheon.upstream.yml');
      }
      if (!$pantheon_yml_contents && !$pantheon_upstream_yml_contents) {
        throw new \Exception('Unable to find pantheon.yml or pantheon.upstream.yml');
      }
      $pantheon_yml = Yaml::parse($pantheon_yml_contents);
      $pantheon_upstream_yml = Yaml::parse($pantheon_upstream_yml_contents);
      $found = FALSE;
      if (empty($pantheon_yml['search']['version'])) {
        // Merge from pantheon_upstream as fallback.
        if (!empty($pantheon_upstream_yml['search']['version'])) {
          $pantheon_yml['search']['version'] = $pantheon_upstream_yml['search']['version'];
        }
      }
      if (empty($pantheon_yml['search']['version'])) {
        // If still empty, throw an exception.
        throw new \Exception('Unable to find search.version in pantheon.yml or pantheon.upstream.yml');
      }

      if ($pantheon_yml['search']['version'] != '8') {
        throw new \Exception('Unsupported search.version in pantheon.yml or pantheon.upstream.yml');
      }
      $this->logger->notice('Pantheon.yml file looks ok ✅');
      $backend = Server::load('pantheon_search')->getBackend();
      $this->logger->notice('Pantheon connector found {var}', [
        'var' => $backend instanceof PantheonSolrConnector ? '✅' : '❌',
      ]);
      if (!$backend instanceof PantheonSolrConnector) {
        return;
      }
      $this->logger->notice((string) $backend->getEndpoint());
      $response = $backend->pingServer();
      $this->logger->notice('Ping Received Response? {var}', [
        'var' => $response !== FALSE ? '✅' : '❌',
      ]);
      $indexedStats = $backend->getLuke();
      if ($this->output()->isVerbose()) {
        $this->logger->notice('Solr Index Stats: {stats}', [
          'stats' => print_r($indexedStats['index'], TRUE),
        ]);
      }
      else {
        $this->logger->notice('We got Solr stats ✅');
      }
      $beans = $backend->getServerInfo(TRUE);
      if ($this->output()->isVerbose()) {
        $this->logger->notice('Mbeans Stats: {stats}', [
          'stats' => print_r($beans['solr-mbeans'], TRUE),
        ]);
      }
      else {
        $this->logger->notice('We got Mbeans stats ✅');
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
    $this->logger->notice("If there's an issue with the connection, it would have shown up here. You should be good to go!");
  }

}
