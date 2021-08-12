<?php

namespace Drupal\search_api_pantheon\Plugin\SolrConnector;

use Drupal\search_api_pantheon\Utility\Cores;
use Drupal\search_api_pantheon\Services\PantheonGuzzle;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Url;
use Drupal\search_api\LoggerTrait;
use Drupal\search_api_solr\SolrConnector\SolrConnectorPluginBase;
use Drupal\search_api_solr\SolrConnectorInterface;
use Solarium\Core\Client\Endpoint;
use Solarium\Core\Client\Request;

/**
 * Pantheon Solr connector.
 *
 * @SolrConnector(
 *   id = "pantheon",
 *   label = @Translation("Pantheon"),
 *   description = @Translation("A connector for Pantheon's Solr server")
 * )
 */
class PantheonSolrConnector extends SolrConnectorPluginBase implements
    SolrConnectorInterface,
    PluginFormInterface {
  use LoggerTrait;

  /**
   * Pantheon pre-configured guzzle client for Solr Server for this site/env.
   *
   * @var \Drupal\search_api_pantheon\Services\PantheonGuzzle
   */
  protected PantheonGuzzle $pantheonGuzzleClient;

  /**
   * Class Constructor.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param array $plugin_definition
   *   Plugin Definition.
   *
   * @throws \Exception
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition
    );

    // @todo move these to dependency injection
    $this->logger = \Drupal::logger('PantheonSolr');
    $this->pantheonGuzzleClient = \Drupal::service('search_api_pantheon.pantheon_guzzle');
    if (!$this->pantheonGuzzleClient instanceof PantheonGuzzle) {
      throw new \Exception('Cannot instantiate the pantheon-specific guzzle service');
    }
    $this->solr = $this->pantheonGuzzleClient->getSolrClient();
  }

  /**
   * Returns the default endpoint name.
   *
   * @return string
   *   The endpoint name.
   */
  public static function getDefaultEndpoint() {
    return 'pantheon_solr8';
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    // @todo Setup via pluginDefinition.
    return 'Pantheon Solr Connection';
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return "Connection to Pantheon's Nextgen Solr 8 server interface";
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): ?array {
    return [
      'endpoint' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isCloud() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getServerLink() {
    $url_path = Cores::getBaseUri();
    $url = Url::fromUri($url_path);

    return Link::fromTextAndUrl($url_path, $url);
  }

  /**
   * {@inheritdoc}
   */
  public function getCoreLink() {
    $url_path = Cores::getBaseCoreUri();
    $url = Url::fromUri($url_path);

    return Link::fromTextAndUrl($url_path, $url);
  }

  /**
   * {@inheritdoc}
   */
  public function getLuke() {
    // @todo Try to get rid of this.
    return $this->getDataFromHandler('admin/luke', TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDataFromHandler($handler, $reset = FALSE) {
    $query = $this->solr->createApi([
      'handler' => $handler,
      'version' => Request::API_V1,
    ]);
    // @todo Implement query caching with redis.
    return $this->execute($query)->getData();
  }

  /**
   * {@inheritdoc}
   */
  public function getEndpoint($key = NULL) {
    $key = $key ?? self::getDefaultEndpoint();

    return $this->solr->getEndpoint($key);
  }

  /**
   * {@inheritdoc}
   */
  public function getCoreInfo($reset = FALSE) {
    // @todo Try to get rid of this.
    return $this->getDataFromHandler('admin/system', $reset);
  }

  /**
   * {@inheritdoc}
   */
  public function connect() {}

  /**
   * {@inheritdoc}
   */
  public function getServerInfo($reset = FALSE) {
    return $this->getDataFromHandler('admin/system', $reset);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \JsonException
   */
  public function getStatsSummary() {
    $summary = [
      '@pending_docs' => '',
      '@autocommit_time_seconds' => '',
      '@autocommit_time' => '',
      '@deletes_by_id' => '',
      '@deletes_by_query' => '',
      '@deletes_total' => '',
      '@schema_version' => '',
      '@core_name' => '',
      '@index_size' => '',
    ];
    $mbeans = new \ArrayIterator($this->pantheonGuzzleClient->getQueryResult('admin/mbeans', ['query' => ['stats' => 'true']])['solr-mbeans']) ?? NULL;
    $indexStats = $this->pantheonGuzzleClient->getQueryResult('admin/luke', ['query' => ['stats' => 'true']])['index'] ?? NULL;
    $stats = [];
    if (!empty($mbeans) && !empty($indexStats)) {
      for ($mbeans->rewind(); $mbeans->valid(); $mbeans->next()) {
        $current = $mbeans->current();
        $mbeans->next();
        $next = $mbeans->current();
        $stats[$current] = $next;
      }
      $max_time = -1;
      $update_handler_stats = $stats['UPDATE']['updateHandler']['stats'];

      $summary['@pending_docs'] = (int) $update_handler_stats['UPDATE.updateHandler.docsPending'];
      if (
        isset(
          $update_handler_stats['UPDATE.updateHandler.softAutoCommitMaxTime']
        )
      ) {
        $max_time =
          (int) $update_handler_stats['UPDATE.updateHandler.softAutoCommitMaxTime'];
      }
      $summary['@deletes_by_id'] =
        (int) $update_handler_stats['UPDATE.updateHandler.deletesById'];
      $summary['@deletes_by_query'] =
        (int) $update_handler_stats['UPDATE.updateHandler.deletesByQuery'];
      $summary['@core_name'] =
        $stats['CORE']['core']['class'] ??
        $this->t('No information available.');
      $summary['@index_size'] =
        $indexStats['numDocs'] ??
        $this->t('No information available.');

      $summary['@autocommit_time_seconds'] = $max_time / 1000;
      $summary['@autocommit_time'] = \Drupal::service(
        'date.formatter'
      )->formatInterval($max_time / 1000);
      $summary['@deletes_total'] =
        $summary['@deletes_by_id'] + $summary['@deletes_by_query'];
      $summary['@schema_version'] = $this->getSchemaVersionString(TRUE);
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function getSolrVersion($force_auto_detect = FALSE) {
    $serverInfo = $this->getServerInfo();
    if (isset($serverInfo['lucene']['solr-spec-version'])) {
      return $serverInfo['lucene']['solr-spec-version'];
    }

    return '8.8.1';
  }

  /**
   * {@inheritdoc}
   */
  public function coreRestPost($path, $command_json = '', ?Endpoint $endpoint = NULL) {
    // @todo Utilize $this->configuration['core'] to get rid of this.
    return $this->restRequest(
      Cores::getBaseCoreUri() . '/' . ltrim($path, '/'),
      Request::METHOD_POST,
      $command_json,
      $endpoint
    );
  }

  /**
   * {@inheritdoc}
   */
  public function useTimeout(string $timeout = self::QUERY_TIMEOUT, ?Endpoint $endpoint = NULL) {}

  /**
   * {@inheritdoc}
   */
  public function getOptimizeTimeout(?Endpoint $endpoint = NULL) {
    // @todo Check - do we really need this override (to return "0" instead on the default NULL)?
    return parent::getOptimizeTimeout($endpoint) ?? 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getFile($file = NULL) {
    // @todo Again, something wrong with $this->configuration['core']. Fix to remove this override.
    $query = $this->solr->createApi([
      'handler' => 'admin/file',
    ]);
    if ($file) {
      $query->addParam('file', $file);
    }

    return $this->execute($query)->getResponse();
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function viewSettings() {
    $view_settings = [];

    $view_settings[] = [
      'label' => 'Pantheon Sitename',
      'info' => Cores::getMyCoreName(),
    ];
    $view_settings[] = [
      'label' => 'Pantheon Environment',
      'info' => Cores::getMyEnvironment(),
    ];
    $view_settings[] = [
      'label' => 'Schema Version',
      'info' => $this->getSchemaVersion(TRUE),
    ];

    $core_info = $this->getCoreInfo(TRUE);
    foreach ($core_info['core'] as $key => $value) {
      if (is_string($value)) {
        $view_settings[] = [
          'label' => ucwords($key),
          'info' => $value,
        ];
      }
    }

    return $view_settings;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Exception
   */
  public function reloadCore() {
    // @todo implement "Reload Core" feature.
    throw new \Exception('Reload Core action for Pantheon Solr is not implemented yet');
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->setConfiguration($form_state->getValues());
  }

}
