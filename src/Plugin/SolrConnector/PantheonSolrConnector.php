<?php

namespace Drupal\search_api_pantheon\Plugin\SolrConnector;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\search_api_solr\SolrConnector\SolrConnectorPluginBase;
use Drupal\search_api_solr\SolrConnectorInterface;
use Drupal\search_api_pantheon\Services\Endpoint as PantheonEndpoint;
use League\Container\ContainerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Solarium\Client as SolariumClient;
use Solarium\Core\Client\Endpoint;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pantheon Solr connector.
 *
 * @SolrConnector(
 *   id = "pantheon",
 *   label = @Translation("Pantheon Search Connector"),
 *   description = @Translation("Connection to Pantheon's Search server interface")
 * )
 */
class PantheonSolrConnector extends SolrConnectorPluginBase implements
    SolrConnectorInterface,
    PluginFormInterface,
    ContainerFactoryPluginInterface,
    LoggerAwareInterface {
  use LoggerAwareTrait;
  use ContainerAwareTrait;

  /**
   * @var object|null
   */
  protected $solr;

  /**
   * Class constructor.
   *
   * @param array $configuration
   *   Configuration array.
   * @param $plugin_id
   *   The plugin id.
   * @param array $plugin_definition
   *   Plugin Definition array.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   Standard DJ container.
   */
  public function __construct(
        array $configuration,
        $plugin_id,
        array $plugin_definition,
        ContainerInterface $container
    ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->container = $container;
    $this->setLogger($container->get('logger.factory')->get('PantheonSearch'));
    $this->connect();
  }

  /**
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   * @param array $configuration
   * @param string $plugin_id
   * @param mixed $plugin_definition
   *
   * @return \Drupal\search_api\Plugin\ConfigurablePluginBase|\Drupal\search_api_pantheon\Plugin\SolrConnector\PantheonSolrConnector|static
   * @throws \Exception
   */
  public static function create(
        ContainerInterface $container,
        array $configuration,
        $plugin_id,
        $plugin_definition
    ) {
    return new static(
          $configuration,
          $plugin_id,
          $plugin_definition,
          $container
      );
  }

  /**
   * @return array|array[]|false[]|string[]
   */
  public function defaultConfiguration() {
    return array_merge(parent::defaultConfiguration(), [
          'scheme' => getenv('PANTHEON_INDEX_SCHEME'),
          'host' => getenv('PANTHEON_INDEX_HOST'),
          'port' => getenv('PANTHEON_INDEX_PORT'),
          'path' => getenv('PANTHEON_INDEX_PATH'),
          'core' => getenv('PANTHEON_INDEX_CORE'),
          'schema' => getenv('PANTHEON_INDEX_SCHEMA'),
          'solr_version' => '8',
      ]);
  }

  /**
   * Build form hook.
   *
   * @param array $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state object.
   *
   * @return array
   *   Form render array.
   */
  public function buildConfigurationForm(
        array $form,
        FormStateInterface $form_state
    ) {
    $form['notice'] = [
          '#markup' =>
              "<h3>All options are configured using environment variables on Pantheon.io's custom platform</h3>",
      ];
    return $form;
  }

  /**
   * Form validate handler.
   *
   * @param array $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state object.
   */
  public function validateConfigurationForm(
        array &$form,
        FormStateInterface $form_state
    ) {
  }

  /**
   * Form submit handler.
   *
   * @param array $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state object.
   */
  public function submitConfigurationForm(
        array &$form,
        FormStateInterface $form_state
    ) {
    $this->setConfiguration($form_state->getValues());
  }

  /**
   * Returns the default endpoint name.
   *
   * @return string
   *   The endpoint name.
   */
  public function getDefaultEndpoint() {
    return PantheonEndpoint::$DEFAULT_NAME;
  }

  /**
   * Stats Summary.
   *
   * @throws \JsonException
   */
  public function getStatsSummary() {
    $stats = [];
    try {
      $mbeansResponse = $this->getStatsQuery('admin/mbeans') ?? ['solr-mbeans' => []];
      $mbeans = new \ArrayIterator($mbeansResponse['solr-mbeans'] ?? []);
      for ($mbeans->rewind(); $mbeans->valid(); $mbeans->next()) {
        $current = $mbeans->current();
        $mbeans->next();
        if ($mbeans->valid() && is_string($current)) {
          $stats[$current] = $mbeans->current();
        }
      }
      $indexResponse = $this->getStatsQuery('admin/luke') ?? ['index' => []];
      $indexStats = $indexResponse['index'] ?? [];
    }
    catch (\Exception $e) {
      $this->container->get('messenger')->addError(
        $this->t('Unable to get stats from server!')
      );
    }

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

    if (empty($stats) || empty($indexStats)) {
      return $summary;
    }

    $max_time = -1;
    $update_handler_stats = $stats['UPDATE']['updateHandler']['stats'] ?? -1;
    $summary['@pending_docs'] =
            (int) $update_handler_stats['UPDATE.updateHandler.docsPending'] ?? -1;
    if (
          isset(
              $update_handler_stats['UPDATE.updateHandler.softAutoCommitMaxTime']
          )
      ) {
      $max_time =
                (int) $update_handler_stats['UPDATE.updateHandler.softAutoCommitMaxTime'];
    }
    $summary['@deletes_by_id'] =
            (int) $update_handler_stats['UPDATE.updateHandler.deletesById'] ?? -1;
    $summary['@deletes_by_query'] =
            (int) $update_handler_stats['UPDATE.updateHandler.deletesByQuery'] ?? -1;
    $summary['@core_name'] =
            $stats['CORE']['core']['class'] ??
            $this->t('No information available.');
    $summary['@index_size'] =
            $indexStats['numDocs'] ?? $this->t('No information available.');

    $summary['@autocommit_time_seconds'] = $max_time / 1000;
    $summary['@autocommit_time'] = $this->container
      ->get('date.formatter')
      ->formatInterval($max_time / 1000);
    $summary['@deletes_total'] =
            (
              intval($summary['@deletes_by_id'] ?? 0)
              + intval($summary['@deletes_by_query'] ?? 0)
          ) ?? -1;
    $summary['@schema_version'] = $this->getSchemaVersionString(TRUE);
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function useTimeout(
        string $timeout = self::QUERY_TIMEOUT,
        ?Endpoint $endpoint = NULL
    ) {
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function viewSettings() {
    $view_settings = [];

    $view_settings[] = [
          'label' => $this->t('Pantheon Sitename'),
          'info' => $this->getEndpoint()->getCore(),
      ];
    $view_settings[] = [
          'label' => $this->t('Pantheon Environment'),
          'info' => getenv('PANTHEON_ENVIRONMENT'),
      ];
    $view_settings[] = [
          'label' => $this->t('Schema Version'),
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
   * Override any other endpoints by getting the Pantheon Default endpoint.
   *
   * @param string $key
   *   The endpoint name (ignored).
   *
   * @return \Solarium\Core\Client\Endpoint
   *   The endpoint in question.
   */
  public function getEndpoint($key = 'search_api_solr') {
    return $this->solr->getEndpoint();
  }

  /**
   * Reaload the Solr Core.
   *
   * @return bool
   *   Success or Failure.
   */
  public function reloadCore() {
    $this->logger->notice(
          $this->t('Reload Core action for Pantheon Solr is automatic when Schema is updated.')
      );
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getFile($file = NULL) {
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
   */
  public function getServerInfo($reset = FALSE) {
    return $this->getDataFromHandler($this->configuration['core'] . '/admin/system', $reset);
  }

  /**
   * Prepares the connection to the Solr server.
   */
  protected function connect() {
    if (!$this->solr instanceof SolariumClient) {
      $config = $this->defaultConfiguration();
      $this->solr = $this->createClient($config);
    }
    return $this->solr;
  }

  /**
   * @param array $configuration
   *   Ignored in favor of the default pantheon config.
   *
   * @return object|\Solarium\Client|null
   */
  protected function createClient(array &$configuration) {
    return $this->container->get('search_api_pantheon.solarium_client');
  }

  /**
   * @param string $handler
   *
   * @return mixed
   */
  protected function getStatsQuery(string $handler) {
    return json_decode(
          $this->container
            ->get('search_api_pantheon.pantheon_guzzle')
            ->get(
                  $handler,
                  [
                      'query' =>
                          [
                              'stats' => 'true',
                              'wt' => 'json',
                              'accept' => 'application/json',
                              'contenttype' => 'application/json',
                              'json.nl' => 'flat',
                          ],
                      'headers' =>
                          [
                              'Content-Type' => 'application/json',
                              'Accept' => 'application/json',
                          ],
                  ]
              )
            ->getBody(),
          TRUE,
          JSON_THROW_ON_ERROR
      );
  }

}
