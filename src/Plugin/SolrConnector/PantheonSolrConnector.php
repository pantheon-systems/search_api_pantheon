<?php

namespace Drupal\search_api_pantheon\Plugin\SolrConnector;


use Drupal\Component\Serialization\Json;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Url;
use Drupal\search_api_solr\SearchApiSolrException;
use Drupal\search_api_solr\SolrConnector\SolrConnectorPluginBase;
use Drupal\search_api_solr\SolrConnectorInterface;
use League\Container\ContainerAwareTrait;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Solarium\Core\Client\Endpoint;
use Solarium\Core\Client\Request;
use Solarium\Core\Query\QueryInterface;
use Solarium\Exception\HttpException;
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
    LoggerAwareInterface {

  use LoggerAwareTrait;
  use ContainerAwareTrait;

  protected $solr;

  /**
   * Class Constructor.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   Plugin ID.
   * @param array $plugin_definition
   *   Plugin Definition.
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   Drupal standard DI container.
   *
   * @throws \Exception
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    ContainerInterface $container
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition
    );
    $this->container = $container;
    $this->setLogger($container->get('logger.factory')->get('PantheonSolr'));
    $this->solr = $container->get('search_api_pantheon.solarium_client');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return array_merge(parent::defaultConfiguration(), [
      'scheme' => getenv('PANTHEON_INDEX_SCHEME'),
      'host' => getenv('PANTHEON_INDEX_HOST'),
      'port' => getenv('PANTHEON_INDEX_PORT'),
      'path' => getenv('PANTHEON_INDEX_PATH'),
      'core' => getenv('PANTHEON_INDEX_CORE'),
      'schema' => getenv('PANTHEON_INDEX_SCHEMA')
    ]);
  }


  /**
   * {@inheritdoc}
   */
  public function pingEndpoint(?Endpoint $endpoint = NULL, array $options = []) {
    $query = $this->solr->createPing($options);
    try {
      $start = microtime(TRUE);
      $result = $this->solr->execute($query);
      if ($result->getResponse()->getStatusCode() == 200) {
        // Add 1 µs to the ping time so we never return 0.
        return (microtime(TRUE) - $start) + 1E-6;
      }
    }
    catch (HttpException $e) {
      $this->logger->error("There was an error pinging the endpoint: {error}", ['error' => $e->getMessage()]);
    }
    return FALSE;
  }

  /**
   * Returns the default endpoint name.
   *
   * @return string
   *   The endpoint name.
   */
  public function getDefaultEndpoint() {
    // @codingStandardsIgnoreLine
    return \Drupal\search_api_pantheon\Services\Endpoint::$DEFAULT_NAME;
  }

  /**
   * {@inheritdoc}
   */
  public function label() {
    return $this->getPluginDefinition()['label'] ?? 'Pantheon Solr 8';
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->getPluginDefinition()['description'] ?? "Connection to Pantheon's Nextgen Solr 8 server interface";
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
    $url_path = $this->getEndpoint()
      ->getBaseUri();
    $url = Url::fromUri($url_path);
    return Link::fromTextAndUrl($url_path, $url);
  }

  /**
   * {@inheritdoc}
   */
  public function getCoreLink() {
    $url_path = $this->getEndpoint()
      ->getCoreBaseUri();
    $url = Url::fromUri($url_path);
    return Link::fromTextAndUrl($url_path, $url);
  }

  /**
   * {@inheritdoc}
   */
  public function getLuke() {
    return $this->getDataFromHandler('admin/luke', TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getCoreInfo($reset = FALSE) {
    return $this->getDataFromHandler('admin/system', $reset);
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
   * Stats Summary.
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
    $mbeans = new \ArrayIterator(($this->coreRestGet('admin/mbeans')) ?? NULL);
    $indexStats = $this->coreRestGet('admin/luke', ['query' => ['stats' => 'true']])['index'] ?? NULL;
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
   * Get general server information.
   *
   * @param false $reset
   *   Use cache?
   *
   * @return array|object
   *   Array of returned values.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  // @codingStandardsIgnoreLine
  public function getServerInfo($reset = FALSE) {
    return $this->getDataFromHandler('admin/system', $reset);
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
      'info' => $this->getEndpoint()->getCore(),
    ];
    $view_settings[] = [
      'label' => 'Pantheon Environment',
      'info' => getenv('PANTHEON_ENVIRONMENT'),
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
   * Realod the Solr Core.
   *
   * @return bool
   *   Success or Failure.
   */
  public function reloadCore() {
    $this->logger->notice('Reload Core action for Pantheon Solr is automatic when Schema is updated.');
    return TRUE;
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
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['notice'] = [
      '#markup' => "<h3>All options are configured using environment variables on Pantheon.io's custom platform</h3>",
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
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * Form submit handler.
   *
   * @param array $form
   *   Form render array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Form state object.
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->setConfiguration($form_state->getValues());
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
   * Ping the core url.
   *
   * @param array $options
   *   Request Options.
   *
   * @return float|int
   *   Length of time taken for round-trip.
   */
  public function pingCore(array $options = []) {
    $start = microtime(TRUE);
    $response = $this->coreRestGet('admin/ping');
    if ($response->getStatusCode() == 200) {
      // Add 1 µs to the ping time so we never return 0.
      return (microtime(TRUE) - $start) + 1E-6;
    }
    return -1;
  }


  public function coreRestGet($path, ?Endpoint $endpoint = NULL) {
    // @todo Utilize $this->configuration['core'] to get rid of this.
    return $this->restRequest(ltrim($path, '/'), Request::METHOD_GET, '', $endpoint);
  }


  public function coreRestPost($path, $command_json = '', ?Endpoint $endpoint = NULL) {
    $this->logger->notice('REST REQUEST!');
    return $this->restRequest(
      ltrim($path, '/'),
      Request::METHOD_POST,
      $command_json,
      $endpoint
    );
  }

  protected function restRequest($handler, $method = Request::METHOD_GET, $command_json = '', ?Endpoint $endpoint = NULL) {

    $query = $this->solr->createApi([
      'handler' => $handler,
      'accept' => 'application/json',
      'contenttype' => 'application/json',
      'method' => $method,
      'rawdata' => (Request::METHOD_POST == $method ? $command_json : NULL),
    ]);
    $response = $this->execute($query, $this->solr->getEndpoint());
    $output = $response->getData();
    if (!empty($output['errors'])) {
      throw new SearchApiSolrException('Error trying to send a REST request.' .
                                       "\nError message(s):" . print_r($output['errors'], TRUE));
    }
    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(QueryInterface $query, ?Endpoint $endpoint = NULL) {
    try {
      $this->logger->notice("connected!");
      return $this->solr->execute($query, $this->solr->getEndpoint());
    }
    catch (\Exception $e) {
      $this->handleException($e, $endpoint);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function executeRequest(Request $request, ?Endpoint $endpoint = NULL) {
    try {
      return $this->solr->executeRequest($request, $this->solr->getEndpoint());
    }
    catch (\Exception $e) {
      $this->handleException($e, $endpoint);
    }
  }


  public function handleException(\Exception $e, $endpoint)
  {
    $body = $e->getBody();
    \Kint::dump($e);
    $response_code = (int) $e->getCode();
    switch ((string) $response_code) {
      case '400': // Bad Request.
        $description = 'bad request';
        $response_decoded = Json::decode($body);
        if ($response_decoded && isset($response_decoded['error'])) {
          $body = $response_decoded['error']['msg'] ?? $body;
        }
        break;

      case '404': // Not Found.
        $description = 'not found';
        break;

      case '401': // Unauthorized.
      case '403': // Forbidden.
        $description = 'access denied';
        break;

      case '500': // Internal Server Error.
      case '0':
        $description = 'internal Solr server error';
        break;

      default:
        $description = 'unreachable or returned unexpected response code';
    }
    $message = sprintf('Solr endpoint %s %s (%d). %s', $this->getEndpointUri($endpoint), $description, $response_code, $body);
    throw new SearchApiSolrException($message, $response_code, $e);
  }


  /**
   * Prepares the connection to the Solr server.
   */
  protected function connect() {
    return boolval($this->pingCore());
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
   * Ping the server.
   *
   * @return array|mixed|object
   *   Server information.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function pingServer() {
    return $this->getServerInfo(TRUE);
  }

}
