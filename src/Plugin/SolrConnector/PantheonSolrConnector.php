<?php

namespace Drupal\search_api_pantheon\Plugin\SolrConnector;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\search_api_solr\SolrConnector\SolrConnectorPluginBase;
use Drupal\search_api_solr\SolrConnectorInterface;
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
 *   label = @Translation("Pantheon Solr Connector"),
 *   description = @Translation("Connection to Pantheon's Nextgen Solr 8 server interface")
 * )
 */
class PantheonSolrConnector extends SolrConnectorPluginBase implements
  SolrConnectorInterface,
  PluginFormInterface,
  ContainerFactoryPluginInterface,
  LoggerAwareInterface
{
  use LoggerAwareTrait;
  use ContainerAwareTrait;

  /**
   * @var object|null
   */
  protected $solr;


  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    ContainerInterface $container
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->container = $container;
    $this->setLogger($container->get('logger.factory')->get('PantheonSolr'));
    $this->connect();
  }

  /**
   * Prepares the connection to the Solr server.
   */
  protected function connect()
  {
    if (!$this->solr instanceof SolariumClient) {
      $config = $this->defaultConfiguration();
      $this->solr = $this->createClient($config);
    }
    return $this->solr;
  }

  /**
   * @return array|array[]|false[]|string[]
   */
  public function defaultConfiguration()
  {
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
   * @param array $configuration
   *   Ignored in favor of the default pantheon config.
   *
   * @return object|\Solarium\Client|null
   */
  protected function createClient(array &$configuration)
  {
    return $this->container->get('search_api_pantheon.solarium_client');
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
   * @return array|string|string[]
   */
  public function getServerUri()
  {
    return $this->solr->getEndpoint()->getCoreBaseUri();
  }

  /**
   * Returns the default endpoint name.
   *
   * @return string
   *   The endpoint name.
   */
  public function getDefaultEndpoint()
  {
    // @codingStandardsIgnoreLine
    return \Drupal\search_api_pantheon\Services\Endpoint::$DEFAULT_NAME;
  }

  /**
   * Stats Summary.
   *
   * @throws \JsonException
   */
  public function getStatsSummary()
  {
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
    $mbeans = new \ArrayIterator($this->coreRestGet('admin/mbeans') ?? null);
    $indexStats = $this->coreRestGet('admin/luke?STATS=true')['index'] ?? null;
    $stats = [];

    if (!empty($mbeans) && !empty($indexStats)) {
      for ($mbeans->rewind(); $mbeans->valid(); $mbeans->next()) {
        $current = $mbeans->current();
        $mbeans->next();
        $next = $mbeans->valid() ? $mbeans->current() : null;
        $stats[$current] = $next;
      }
      $max_time = -1;
      $update_handler_stats = $stats['UPDATE']['updateHandler']['stats'] ?? -1;

      $summary['@pending_docs'] =
        (int)$update_handler_stats['UPDATE.updateHandler.docsPending'] ?? -1;
      if (
        isset(
          $update_handler_stats['UPDATE.updateHandler.softAutoCommitMaxTime']
        )
      ) {
        $max_time =
          (int)$update_handler_stats['UPDATE.updateHandler.softAutoCommitMaxTime'];
      }
      $summary['@deletes_by_id'] =
        (int)$update_handler_stats['UPDATE.updateHandler.deletesById'] ?? -1;
      $summary['@deletes_by_query'] =
        (int)$update_handler_stats['UPDATE.updateHandler.deletesByQuery'] ?? -1;
      $summary['@core_name'] =
        $stats['CORE']['core']['class'] ??
        $this->t('No information available.');
      $summary['@index_size'] =
        $indexStats['numDocs'] ?? $this->t('No information available.');

      $summary['@autocommit_time_seconds'] = $max_time / 1000;
      $summary['@autocommit_time'] = \Drupal::service(
        'date.formatter'
      )->formatInterval($max_time / 1000);
      $summary['@deletes_total'] =
        (
          intval($summary['@deletes_by_id'])
          + intval($summary['@deletes_by_query'])
        ) ?? -1;
      $summary['@schema_version'] = $this->getSchemaVersionString(true);
    }
    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function useTimeout(
    string $timeout = self::QUERY_TIMEOUT,
    ?Endpoint $endpoint = null
  ) {
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function viewSettings()
  {
    $view_settings = [];

    $view_settings[] = [
      'label' => 'Pantheon Sitename',
      'info' => $this->getEndpoint()->getCore(),
    ];
    $view_settings[] = [
      'label' => 'Pantheon Environment',
      'info' => getenv('PANTHEON_ENVIRONMENT'),
    ];
    $view_settings[] = [
      'label' => 'Schema Version',
      'info' => $this->getSchemaVersion(true),
    ];

    $core_info = $this->getCoreInfo(true);
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
  public function getEndpoint($key = 'search_api_solr')
  {
    return $this->solr->getEndpoint();
  }

  /**
   * Realod the Solr Core.
   *
   * @return bool
   *   Success or Failure.
   */
  public function reloadCore()
  {
    $this->logger->notice(
      'Reload Core action for Pantheon Solr is automatic when Schema is updated.'
    );
    return true;
  }

  /**
   * {@inheritdoc}
   */
  public function getFile($file = null)
  {
    $query = $this->solr->createApi([
                                      'handler' => 'admin/file',
                                    ]);
    if ($file) {
      $query->addParam('file', $file);
    }
    return $this->execute($query)->getResponse();
  }

  /**
   * Gets a string representation of the endpoint URI.
   *
   * Could be overwritten by other connectors according to their needs.
   *
   * @param \Solarium\Core\Client\Endpoint $endpoint
   *
   * @return string
   */
  protected function getEndpointUri(Endpoint $endpoint = null): string
  {
    return $this->solr->getEndpoint()->getBaseUri();
  }

}
