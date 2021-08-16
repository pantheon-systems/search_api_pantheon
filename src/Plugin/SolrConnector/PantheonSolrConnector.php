<?php

namespace Drupal\search_api_pantheon\Plugin\SolrConnector;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\search_api_pantheon\Utility\Cores;
use Drupal\search_api_pantheon\Services\PantheonGuzzle;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\search_api_pantheon\Endpoint as PantheonSolrEndpoint;
use Drupal\search_api_solr\SolrConnector\SolrConnectorPluginBase;
use Drupal\search_api_solr\SolrConnectorInterface;
use Solarium\Core\Client\Endpoint;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pantheon Solr connector.
 *
 * @SolrConnector(
 *   id = "pantheon",
 *   label = @Translation("Pantheon Solr Connector"),
 *   description = @Translation("Connection to Pantheon's Nextgen Solr 8 server interface")
 * )
 */
class PantheonSolrConnector extends SolrConnectorPluginBase implements
    SolrConnectorInterface,
    PluginFormInterface,
    ContainerFactoryPluginInterface {

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
   * @param \Drupal\search_api_pantheon\Services\PantheonGuzzle $pantheon_guzzle
   *   The Pantheon Guzzle client.
   *
   * @throws \Exception
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    PantheonGuzzle $pantheon_guzzle
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition
    );

    $this->pantheonGuzzleClient = $pantheon_guzzle;
    $this->solr = $this->pantheonGuzzleClient->getSolrClient();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('search_api_pantheon.pantheon_guzzle'));
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
  public function defaultConfiguration() {
    $configuration = parent::defaultConfiguration();

    return array_merge($configuration, [
      'scheme' => PantheonSolrEndpoint::getSolrScheme(),
      'host' => PantheonSolrEndpoint::getSolrHost(),
      'port' => PantheonSolrEndpoint::getSolrPort(),
      'path' => PantheonSolrEndpoint::getSolrPath(),
      'core' => PantheonSolrEndpoint::getSolrCore(),
    ]);
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
  public function getEndpoint($key = NULL) {
    $key = $key ?? self::getDefaultEndpoint();

    return $this->solr->getEndpoint($key);
  }

  /**
   * {@inheritdoc}
   */
  public function connect() {}

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
  public function useTimeout(string $timeout = self::QUERY_TIMEOUT, ?Endpoint $endpoint = NULL) {}

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
    $this->logger->notice('Reload Core action for Pantheon Solr is automatic when Schema is updated.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->setConfiguration($form_state->getValues());
  }

}
