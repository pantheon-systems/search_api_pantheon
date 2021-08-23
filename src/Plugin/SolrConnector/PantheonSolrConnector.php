<?php

namespace Drupal\search_api_pantheon\Plugin\SolrConnector;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Url;
use Drupal\search_api_solr\SearchApiSolrException;
use Drupal\search_api_solr\SolrConnector\SolrConnectorPluginBase;
use Drupal\search_api_solr\SolrConnectorInterface;
use League\Container\ContainerAwareTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Solarium\Core\Client\Endpoint;
use Solarium\Core\Client\Request;
use Solarium\Core\Query\QueryInterface;
use Solarium\Core\Query\Result\ResultInterface;
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
  LoggerAwareInterface
{
  use LoggerAwareTrait;
  use ContainerAwareTrait;

  /**
   * @var object|null
   */
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
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->container = $container;
    $this->setLogger($container->get('logger.factory')->get('PantheonSolr'));
    $this->solr = $container->get('search_api_pantheon.solarium_client');
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
  public function defaultConfiguration()
  {
    return array_merge(parent::defaultConfiguration(), [
      'scheme' => getenv('PANTHEON_INDEX_SCHEME'),
      'host' => getenv('PANTHEON_INDEX_HOST'),
      'port' => getenv('PANTHEON_INDEX_PORT'),
      'path' => getenv('PANTHEON_INDEX_PATH'),
      'core' => getenv('PANTHEON_INDEX_CORE'),
      'schema' => getenv('PANTHEON_INDEX_SCHEMA'),
    ]);
  }

  /**
   * @param \Solarium\Core\Client\Endpoint|null $endpoint
   * @param array $options
   *
   * @return false|float
   */
  public function pingEndpoint(?Endpoint $endpoint = null, array $options = [])
  {
    $query = $this->solr->createPing($options);
    try {
      $start = microtime(true);
      $result = $this->solr->execute($query);
      if (
        $result instanceof ResultInterface &&
        $result->getResponse()->getStatusCode() == 200
      ) {
        // Add 1 µs to the ping time so we never return 0.
        return microtime(true) - $start + 1e-6;
      }
    } catch (HttpException $e) {
      $this->logger->error('There was an error pinging the endpoint: {error}', [
        'error' => $e->getMessage(),
      ]);
      $this->container->get('messenger')
        ->addError($e->getMessage());
    }
    return false;
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
   * @return mixed|string
   */
  public function label()
  {
    return $this->getPluginDefinition()['label'] ?? 'Pantheon Solr 8';
  }

  /**
   * @return mixed|string
   */
  public function getDescription()
  {
    return $this->getPluginDefinition()['description'] ??
      "Connection to Pantheon's Nextgen Solr 8 server interface";
  }

  /**
   * @return false
   */
  public function isCloud()
  {
    return false;
  }

  /**
   * @return \Drupal\Core\Link
   */
  public function getServerLink()
  {
    $url_path = $this->getEndpoint()->getBaseUri();
    $url = Url::fromUri($url_path);
    return Link::fromTextAndUrl($url_path, $url);
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
   * @return \Drupal\Core\Link
   */
  public function getCoreLink()
  {
    $url_path = $this->getEndpoint()->getCoreBaseUri();
    $url = Url::fromUri($url_path);
    return Link::fromTextAndUrl($url_path, $url);
  }

  /**
   * @return array|object|null
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function getLuke()
  {
    return $this->getDataFromHandler('admin/luke', true);
  }

  /**
   * @param string $handler
   * @param false $reset
   *
   * @return array|null
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  protected function getDataFromHandler($handler, $reset = false)
  {
    static $previous_calls = [];
    // We keep the results in a state instead of a cache because we want to
    // access parts of this data even if Solr is temporarily not reachable and
    // caches have been cleared.
    $state_key = 'search_api_solr.endpoint.data';
    $state = \Drupal::state();
    $endpoint_data = $state->get($state_key);
    $server_uri = $this->getServerUri();

    if (
      !isset($previous_calls[$server_uri][$handler]) ||
      !isset($endpoint_data[$server_uri][$handler]) ||
      $reset
    ) {
      // Don't retry multiple times in case of an exception.
      $previous_calls[$server_uri][$handler] = true;

      if (
        !is_array($endpoint_data) ||
        !isset($endpoint_data[$server_uri][$handler]) ||
        $reset
      ) {
        $query = $this->solr->createApi([
                                          'handler' => $handler,
                                          'version' => Request::API_V1,
                                        ]);
        $endpoint_data[$server_uri][$handler] = $this->execute(
          $query
        )->getData();
        $state->set($state_key, $endpoint_data);
      }
    }

    return $endpoint_data[$server_uri][$handler];
  }

  /**
   * @return array|string|string[]
   */
  public function getServerUri()
  {
    return $this->solr->getEndpoint()->getCoreBaseUri();
  }

  /**
   * @param \Solarium\Core\Query\QueryInterface $query
   * @param \Solarium\Core\Client\Endpoint|null $endpoint
   *
   * @return \Solarium\Core\Query\Result\ResultInterface|void
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function execute(QueryInterface $query, ?Endpoint $endpoint = null)
  {
    try {
      return $this->solr->execute($query, $this->solr->getEndpoint());
    } catch (\HttpException $e) {
      $this->handleException($e, $endpoint);
    }
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
  public function handleException(\Exception $e, $endpoint)
  {
    if ($e instanceof \HttpException) {
      $body = $e->getBody() ?? '';
      $response_code = (int)$e->getCode();
      switch ((string)$response_code) {
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
      $message = sprintf(
        'Solr endpoint %s %s (%d). %s',
        $this->getEndpointUri($endpoint),
        $description,
        $response_code,
        $body
      );
      return null;
      //throw new SearchApiSolrException($message, $response_code, $e);
    }
    $this->container->get('messenger')
      ->addError($message ?? $e->getMessage());
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
   * @param string $path
   * @param \Solarium\Core\Client\Endpoint|null $endpoint
   *
   * @return array|string
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function coreRestGet($path, ?Endpoint $endpoint = null)
  {
    // @todo Utilize $this->configuration['core'] to get rid of this.
    return $this->restRequest(
      ltrim($path, '/'),
      Request::METHOD_GET,
      '',
      $endpoint
    );
  }

  /**
   * @param string $handler
   * @param string $method
   * @param string $command_json
   * @param \Solarium\Core\Client\Endpoint|null $endpoint
   *
   * @return array
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  protected function restRequest(
    $handler,
    $method = Request::METHOD_GET,
    $command_json = '',
    ?Endpoint $endpoint = null
  ) {
    $query = $this->solr->createApi([
                                      'handler' => $handler,
                                      'accept' => 'application/json',
                                      'contenttype' => 'application/json',
                                      'method' => $method,
                                      'rawdata' => Request::METHOD_POST == $method ? $command_json : null,
                                    ]);
    $response = $this->execute($query, $this->solr->getEndpoint());
    $output = $response->getData();
    if (!empty($output['errors'])) {
      throw new SearchApiSolrException(
        'Error trying to send a REST request.' .
        "\nError message(s):" .
        print_r($output['errors'], true)
      );
    }
    return $output;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function getSolrVersion($force_auto_detect = false)
  {
    $serverInfo = $this->getServerInfo();
    if (isset($serverInfo['lucene']['solr-spec-version'])) {
      return $serverInfo['lucene']['solr-spec-version'];
    }

    return '8.8.1';
  }

  /**
   * @param false $reset
   *
   * @return array|null
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function getServerInfo($reset = false)
  {
    return $this->getDataFromHandler('admin/system', $reset);
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
   * @param false $reset
   *
   * @return array|object|null
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function getCoreInfo($reset = false)
  {
    return $this->getDataFromHandler('admin/system', $reset);
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
   * @param string $path
   * @param string $command_json
   * @param \Solarium\Core\Client\Endpoint|null $endpoint
   *
   * @return array|string
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function coreRestPost(
    $path,
    $command_json = '',
    ?Endpoint $endpoint = null
  ) {
    $this->logger->notice('REST REQUEST!');
    return $this->restRequest(
      ltrim($path, '/'),
      Request::METHOD_POST,
      $command_json,
      $endpoint
    );
  }

  /**
   * @param \Solarium\Core\Client\Request $request
   * @param \Solarium\Core\Client\Endpoint|null $endpoint
   *
   * @return \Solarium\Core\Client\Response|void
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function executeRequest(Request $request, ?Endpoint $endpoint = null)
  {
    try {
      return $this->solr->executeRequest($request, $this->solr->getEndpoint());
    } catch (\HttpException $e) {
      $this->handleException($e, $endpoint);
    }
  }

  /**
   * Ping the server.
   *
   * @return array|mixed|object
   *   Server information.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function pingServer()
  {
    return $this->getServerInfo(true);
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
   * Prepares the connection to the Solr server.
   */
  protected function connect()
  {
    return boolval($this->pingCore());
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
  public function pingCore(array $options = [])
  {
    $start = microtime(true);
    $response = $this->coreRestGet('admin/ping');
    if (
      $response instanceof ResponseInterface &&
      $response->getStatusCode() == 200
    ) {
      // Add 1 µs to the ping time so we never return 0.
      return microtime(true) - $start + 1e-6;
    }
    return -1;
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
}
