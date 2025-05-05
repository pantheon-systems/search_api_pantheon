<?php

namespace Drupal\search_api_pantheon\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api_pantheon\Plugin\SolrConnector\PantheonSolrConnector;
use Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend;
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
class Diagnose extends PantheonCommandBase {

  public function __construct(EntityTypeManagerInterface $entityTypeManager, protected string $drupalRoot) {
    parent::__construct($entityTypeManager);
  }

  /**
   * Search_api_pantheon:diagnose.
   *
   * @usage search-api-pantheon:diagnose
   *   Connect to the solr8 server.
   *
   * @command search-api-pantheon:diagnose
   * @aliases sapd
   */
  public function solrDiagnose(): void {
    try {
      $this->verifyYamlFiles();
      $backend = $this->getPantheonSolrServer()->getBackend();
      assert($backend instanceof SearchApiSolrBackend);
      $connector = $backend->getSolrConnector();
      $this->logger->notice('Pantheon connector found {var}', [
        'var' => $connector instanceof PantheonSolrConnector ? '✅' : '❌',
      ]);
      if (!$connector instanceof PantheonSolrConnector) {
        return;
      }
      $this->logger->notice((string) $connector->getEndpoint());
      $response = $connector->pingServer();
      $this->logger->notice('Ping Received Response? {var}', [
        'var' => $response !== FALSE ? '✅' : '❌',
      ]);
      foreach ($backend->viewSettings() as $setting) {
        if (isset($setting['status'])) {
          $setting['status'] = ['ok' => '✅', 'error' => '❌'][$setting['status']];
          $this->logger->notice('{label}: {info} {status}', $setting);
        }
        else {
          $this->logger->notice('{label}: {info}', $setting);
        }
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

  /**
   * @return void
   * @throws \Exception
   */
  public function verifyYamlFiles(): void {
    $pantheon_yml_contents = '';
    if (file_exists($this->drupalRoot . '/pantheon.yml')) {
      $pantheon_yml_contents = file_get_contents($this->drupalRoot . '/pantheon.yml');
    }
    elseif (file_exists($this->drupalRoot . '/../pantheon.yml')) {
      $pantheon_yml_contents = file_get_contents($this->drupalRoot . '/../pantheon.yml');
    }
    $pantheon_upstream_yml_contents = '';
    if (file_exists($this->drupalRoot . '/pantheon.upstream.yml')) {
      $pantheon_upstream_yml_contents = file_get_contents($this->drupalRoot . '/pantheon.upstream.yml');
    }
    elseif (file_exists($this->drupalRoot . '/../pantheon.upstream.yml')) {
      $pantheon_upstream_yml_contents = file_get_contents($this->drupalRoot . '/../pantheon.upstream.yml');
    }
    if (!$pantheon_yml_contents && !$pantheon_upstream_yml_contents) {
      throw new \Exception('Unable to find pantheon.yml or pantheon.upstream.yml');
    }
    $pantheon_yml = Yaml::parse($pantheon_yml_contents);
    $pantheon_upstream_yml = Yaml::parse($pantheon_upstream_yml_contents);
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
  }

}
