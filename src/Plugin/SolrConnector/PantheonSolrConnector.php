<?php

namespace Drupal\search_api_pantheon\Plugin\SolrConnector;

use Drupal\search_api_solr\SearchApiSolrException;
use Drupal\search_api_pantheon\Utility\Cores;
use Drupal\search_api_pantheon\Services\PantheonGuzzle;
use Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Url;
use Drupal\search_api\LoggerTrait;
use Drupal\search_api_pantheon\Endpoint;
use Drupal\search_api_solr\Solarium\Autocomplete\Query as AutocompleteQuery;
use Drupal\search_api_solr\SolrConnector\SolrConnectorPluginBase;
use Drupal\search_api_solr\SolrConnectorInterface;
use Psr\Log\LoggerInterface;
use Solarium\Core\Client\Request;
use Solarium\Core\Client\Response;
use Solarium\Core\Query\QueryInterface;
use Solarium\Core\Query\Result\ResultInterface;
use Solarium\Exception\HttpException;
use Solarium\QueryType\Extract\Result as ExtractResult;
use Solarium\QueryType\Select\Query\Query;
use Solarium\QueryType\Update\Query\Query as UpdateQuery;
use ZipStream\ZipStream;

/**
 * Standard Solr connector.
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
   * Constant for Endpoint Name.
   *
   * @var string
   */
  // @codingStandardsIgnoreLine
  public static $PANTHEON_SOLR_DEFAULT_ENDPOINT = 'pantheon_solr8';

  /**
   * Event Dispatcher.
   *
   * @var object|null
   */
  protected $eventDispatcher;

  /**
   * Pantheon pre-configured guzzle client for Solr Server for this site/env.
   *
   * @var \Drupal\search_api_pantheon\Services\PantheonGuzzle|mixed
   */
  protected PantheonGuzzle $pg;

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
    if (!isset($this->eventDispatcher)) {
      $this->eventDispatcher = \Drupal::getContainer()->get('event_dispatcher');
    }
    // @todo move these to dependency injection
    $this->logger = \Drupal::logger('PantheonSolr');
    $this->pg = \Drupal::service('search_api_pantheon.pantheon_guzzle');
    if (!$this->pg instanceof PantheonGuzzle) {
      throw new \Exception('Cannot instantiate the pantheon-specific guzzle service');
    }
    $this->solr = $this->pg->getSolrClient();
  }

  /**
   * Get the Label.
   *
   * @return string
   */
  public function label() {
    return "Pantheon Solr Connection";
  }

  /**
   * Get the Description.
   *
   * @return string
   */
  public function getDescription() {
    return "Connection to Pantheon's Nextgen Solr 8 server interface";
  }

  /**
   * @return array|void
   */
  public function getConfiguration() {
    return $this->configuration;
  }

  /**
   * @return array|void
   */
  public function defaultConfiguration(): ?array {
    return [
      'endpoint' => [],
    ];
  }

  /**
   * @param \Drupal\Component\EventDispatcher\ContainerAwareEventDispatcher $eventDispatcher
   *
   * @return \Drupal\search_api_solr\SolrConnectorInterface
   */
  public function setEventDispatcher(
    ContainerAwareEventDispatcher $eventDispatcher
  ): SolrConnectorInterface {
    $this->eventDispatcher = $eventDispatcher;
    return $this;
  }

  /**
   * @return false
   */
  public function isCloud() {
    return FALSE;
  }

  /**
   * @return \Drupal\Core\Link
   */
  public function getServerLink() {
    $url_path = Cores::getBaseUri();
    $url = Url::fromUri($url_path);

    return Link::fromTextAndUrl($url_path, $url);
  }

  /**
   * @return \Drupal\Core\Link
   */
  public function getCoreLink() {
    $url_path = Cores::getBaseCoreUri();
    $url = Url::fromUri($url_path);

    return Link::fromTextAndUrl($url_path, $url);
  }

  /**
   * @return array|object
   */
  public function getLuke() {
    return $this->getDataFromHandler('admin/luke', TRUE);
  }

  /**
   * Gets data from a Solr endpoint using a given handler.
   *
   * @param string $handler
   *   The handler used for the API query.
   * @param bool $reset
   *   If TRUE the server will be asked regardless if a previous call is cached.
   *
   * @return array
   *   Response data with system information.
   *
   * @throws \Drupal\search_api_pantheon\Exceptions\SearchApiSolrException
   */
  protected function getDataFromHandler($handler, $reset = FALSE) {
    // We keep the results in a state instead of a cache because we want to
    // access parts of this data even if Solr is temporarily not reachable and
    // caches have been cleared.
    $state_key = 'search_api_solr.endpoint.data';
    $state = \Drupal::state();
    $endpoint_data = $state->get($state_key);
    $server_uri = Cores::getBaseCoreUri();

    $query = $this->solr->createApi([
      'handler' => $handler,
      'version' => Request::API_V1,
    ]);
    // @todo implement query caching with redis
    $this->getLogger()->debug(print_r($query, TRUE));
    $queryResult = $this->execute($query)->getData();
    $this->getLogger()->debug(print_r($queryResult, TRUE));
    return $queryResult;
  }

  /**
   * @return \Psr\Log\LoggerInterface
   */
  public function getLogger(): LoggerInterface {
    return $this->logger;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(
    QueryInterface $query,
    ?\Solarium\Core\Client\Endpoint $endpoint = NULL
  ) {
    try {
      return $this->solr->execute($query, $endpoint);
    }
    catch (\Exception $e) {
      $this->handleException($e);
    }
  }

  /**
   * Converts a HttpException in an easier to read SearchApiSolrException.
   *
   * Connectors must not overwrite this function. Otherwise support requests are
   * hard to handle in the issue queue. If you want to extend this function and
   * add more sophisticated error handling, please contribute a patch to
   * the search_api_solr project on drupal.org.
   *
   * @param \Solarium\Exception\HttpException $e
   *   The HttpException object.
   * @param \Solarium\Core\Client\Endpoint $endpoint
   *   The Solarium endpoint.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  final protected function handleException(\Exception $e) {
    $response_code = (int) $e->getCode();
    switch ((string) $response_code) {
      // Bad Request.
      case '400':
        $description = 'bad request';
        $response_decoded = Json::decode($body);
        if ($response_decoded && isset($response_decoded['error'])) {
          $body = $response_decoded['error']['msg'] ?? $body;
        }
        break;

      // Not Found.
      case '404':
        $description = 'not found';
        break;

      // Unauthorized.
      case '401':
        // Forbidden.
      case '403':
        $description = 'access denied';
        break;

      // Internal Server Error.
      case '500':
      case '0':
        $description = 'internal Solr server error';
        break;

      default:
        $description = 'unreachable or returned unexpected response code';
    }
    throw new SearchApiSolrException(
      sprintf(
        'Solr endpoint %s %s (%d). %s',
        $this->getEndpointUri($this->getEndpoint()),
        $description,
        $response_code,
        $e->getMessage(),
      ),
      $response_code,
      $e
    );
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
  protected function getEndpointUri(
    \Solarium\Core\Client\Endpoint $endpoint
  ): string {
    return $endpoint->getServerUri();
  }

  /**
   * {@inheritdoc}
   */
  public function getEndpoint($key = NULL) {
    $key = $key ?? static::$PANTHEON_SOLR_DEFAULT_ENDPOINT;
    return $this->solr->getEndpoint($key);
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaTargetedSolrBranch($reset = FALSE) {
    $parts = explode('-', $this->getSchemaVersionString($reset));
    return $parts[3];
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaVersionString($reset = FALSE) {
    return $this->getCoreInfo($reset)['core']['schema'];
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
  public function isJumpStartConfigSet(bool $reset = FALSE): bool {
    $parts = explode('-', $this->getSchemaVersionString($reset));
    return (bool) ($parts[4] ?? 0);
  }

  /**
   * {@inheritdoc}
   */
  public function pingCore(array $options = []): bool {
    $ping = $this->pingEndpoint(NULL, $options);
    return ($ping->getResponse()->getStatusCode() === 200);
  }

  /**
   * {@inheritdoc}
   */
  public function pingEndpoint(
    ?\Solarium\Core\Client\Endpoint $endpoint = NULL,
    array $options = []
  ) {
    return $this->connect();
  }

  /**
   *
   */
  public function connect(): ?ResultInterface {
    return $this->solr->ping($this->solr->createPing());
  }

  /**
   * {@inheritdoc}
   */
  public function pingServer() {
    return $this->getServerInfo(TRUE);
  }

  /**
   * @param false $reset
   *
   * @return array
   */
  public function getServerInfo($reset = FALSE) {
    return $this->getDataFromHandler('admin/system', $reset);
  }

  /**
   * {@inheritdoc}
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
    $mbeans = new \ArrayIterator($this->pg->getQueryResult('admin/mbeans', ['query' => ['stats' => 'true']])['solr-mbeans']) ?? NULL;
    $indexStats = $this->pg->getQueryResult('admin/luke', ['query' => ['stats' => 'true']])['index'] ?? NULL;
    $stats = [];
    if (!empty($mbeans) && !empty($indexStats)) {
      for ($mbeans->rewind(); $mbeans->valid(); $mbeans->next()) {
        $current = $mbeans->current();
        $mbeans->next();
        $next = $mbeans->current();
        $stats[$current] = $next;
      }
      $solr_version = $this->getSolrVersion(TRUE);
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
   * @param false $force_auto_detect
   *
   * @return string|void
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
  public function coreRestGet(
    $path,
    ?\Solarium\Core\Client\Endpoint $endpoint = NULL
  ) {
    return $this->restRequest(
      $this->configuration['core'] . '/' . ltrim($path, '/'),
      Request::METHOD_GET,
      '',
      $endpoint
    );
  }

  /**
   * Sends a REST request to the Solr server endpoint and returns the result.
   *
   * @param string $handler
   *   The handler used for the API query.
   * @param string $method
   *   The HTTP request method.
   * @param string $command_json
   *   The command to send encoded as JSON.
   * @param \Solarium\Core\Client\Endpoint|null $endpoint
   *
   * @return array
   *   The decoded response.
   *
   * @throws \Drupal\search_api_solr\Exceptions\SearchApiSolrException
   */
  protected function restRequest(
    $handler,
    $method = Request::METHOD_GET,
    $command_json = '',
    ?\Solarium\Core\Client\Endpoint $endpoint = NULL
  ) {
    $query = $this->solr->createApi([
      'handler' => $handler,
      'accept' => 'application/json',
      'contenttype' => 'application/json',
      'method' => $method,
      'rawdata' => Request::METHOD_POST == $method ? $command_json : NULL,
    ]);

    $response = $this->execute($query, $endpoint);
    $output = $response->getData();
    // \Drupal::logger('search_api_solr')->info(print_r($output, true));.
    if (!empty($output['errors'])) {
      throw new SearchApiSolrException(
        'Error trying to send a REST request.' .
        "\nError message(s):" .
        print_r($output['errors'], TRUE)
      );
    }
    return $output;
  }

  /**
   * {@inheritdoc}
   */
  public function coreRestPost(
    $path,
    $command_json = '',
    ?\Solarium\Core\Client\Endpoint $endpoint = NULL
  ) {
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
  public function serverRestGet($path) {
    return $this->restRequest($path);
  }

  /**
   * {@inheritdoc}
   */
  public function serverRestPost($path, $command_json = '') {
    return $this->restRequest($path, Request::METHOD_POST, $command_json);
  }

  /**
   * {@inheritdoc}
   */
  public function getUpdateQuery() {
    return $this->solr->createUpdate();
  }

  /**
   * {@inheritdoc}
   */
  public function getSelectQuery() {
    return $this->solr->createSelect();
  }

  /**
   * {@inheritdoc}
   */
  public function getMoreLikeThisQuery() {
    return $this->solr->createMoreLikeThis();
  }

  /**
   * {@inheritdoc}
   */
  public function getTermsQuery() {
    return $this->solr->createTerms();
  }

  /**
   * {@inheritdoc}
   */
  public function getSpellcheckQuery() {
    return $this->solr->createSpellcheck();
  }

  /**
   * {@inheritdoc}
   */
  public function getSuggesterQuery() {
    return $this->solr->createSuggester();
  }

  /**
   * {@inheritdoc}
   */
  public function getAutocompleteQuery() {
    $this->solr->registerQueryType('autocomplete', AutocompleteQuery::class);
    return $this->solr->createQuery('autocomplete');
  }

  /**
   * {@inheritdoc}
   */
  public function getQueryHelper(QueryInterface $query = NULL) {
    if ($query) {
      return $query->getHelper();
    }

    return \Drupal::service('solarium.query_helper');
  }

  /**
   * {@inheritdoc}
   */
  public function getExtractQuery() {
    return $this->solr->createExtract();
  }

  /**
   * {@inheritdoc}
   */
  public function search(
    Query $query,
    ?\Solarium\Core\Client\Endpoint $endpoint = NULL
  ) {
    $this->connect();

    if (!$endpoint) {
      $endpoint = $this->solr->getEndpoint(static::$PANTHEON_SOLR_DEFAULT_ENDPOINT);
    }

    $this->useTimeout(self::QUERY_TIMEOUT, $endpoint);

    // Use the 'postbigrequest' plugin if no specific http method is
    // configured. The plugin needs to be loaded before the request is
    // created.
    if ($this->configuration['http_method'] === 'AUTO') {
      $this->solr->getPlugin('postbigrequest');
    }

    // Use the manual method of creating a Solarium request so we can control
    // the HTTP method.
    $request = $this->solr->createRequest($query);

    // Set the configured HTTP method.
    if ($this->configuration['http_method'] === 'POST') {
      $request->setMethod(Request::METHOD_POST);
    }
    elseif ($this->configuration['http_method'] === 'GET') {
      $request->setMethod(Request::METHOD_GET);
    }

    return $this->executeRequest($request, $endpoint);
  }

  /**
   * {@inheritdoc}
   */
  public function useTimeout(
    string $timeout = self::QUERY_TIMEOUT,
    ?\Solarium\Core\Client\Endpoint $endpoint = NULL
  ) {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function executeRequest(
    Request $request,
    ?\Solarium\Core\Client\Endpoint $endpoint = NULL
  ) {
    if (!$endpoint) {
      $endpoint = $this->solr->getEndpoint(static::$PANTHEON_SOLR_DEFAULT_ENDPOINT);
    }

    try {
      return $this->solr->executeRequest($request, $endpoint);
    }
    catch (HttpException $e) {
      $this->handleHttpException($e, $endpoint);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createSearchResult(QueryInterface $query, Response $response) {
    return $this->solr->createResult($query, $response);
  }

  /**
   * {@inheritdoc}
   */
  public function update(
    UpdateQuery $query,
    ?\Solarium\Core\Client\Endpoint $endpoint = NULL
  ) {
    if (!$endpoint) {
      $endpoint = $this->solr->getEndpoint(static::$PANTHEON_SOLR_DEFAULT_ENDPOINT);
    }

    if ($this->configuration['commit_within']) {
      // Do a commitWithin since that is automatically a softCommit since Solr 4
      // and a delayed hard commit with Solr 3.4+.
      // By default we wait 1 second after the request arrived for solr to parse
      // the commit. This allows us to return to Drupal and let Solr handle what
      // it needs to handle.
      // @see http://wiki.apache.org/solr/NearRealtimeSearch
      /** @var \Solarium\Plugin\CustomizeRequest\CustomizeRequest $request */
      $request = $this->customizeRequest();
      if (!$request->getCustomization('commitWithin')) {
        $request
          ->createCustomization('commitWithin')
          ->setType('param')
          ->setName('commitWithin')
          ->setValue($this->configuration['commit_within']);
      }
    }

    return $this->execute($query, $endpoint);
  }

  /**
   * Creates a CustomizeRequest object.
   *
   * @return \Solarium\Plugin\CustomizeRequest\CustomizeRequest|\Solarium\Core\Plugin\PluginInterface
   *   The Solarium CustomizeRequest object.
   */
  protected function customizeRequest() {
    return $this->solr->getPlugin('customizerequest');
  }

  /**
   * {@inheritdoc}
   */
  public function autocomplete(
    AutocompleteQuery $query,
    ?\Solarium\Core\Client\Endpoint $endpoint = NULL
  ) {
    if (!$endpoint) {
      $endpoint = $this->solr->getEndpoint(static::$PANTHEON_SOLR_DEFAULT_ENDPOINT);
    }

    $this->useTimeout(self::QUERY_TIMEOUT, $endpoint);

    // Use the 'postbigrequest' plugin if no specific http method is
    // configured. The plugin needs to be loaded before the request is
    // created.
    if ($this->configuration['http_method'] === 'AUTO') {
      $this->solr->getPlugin('postbigrequest');
    }

    return $this->execute($query, $endpoint);
  }

  /**
   * {@inheritdoc}
   */
  public function optimize(?\Solarium\Core\Client\Endpoint $endpoint = NULL) {
    $this->connect();

    if (!$endpoint) {
      $endpoint = $this->solr->getEndpoint(static::$PANTHEON_SOLR_DEFAULT_ENDPOINT);
    }
    $update_query = $this->solr->createUpdate();
    $update_query->addOptimize(TRUE, FALSE);

    $this->execute($update_query, $endpoint);
  }

  /**
   * {@inheritdoc}
   */
  public function adjustTimeout(
    int $timeout,
    ?\Solarium\Core\Client\Endpoint &$endpoint = NULL
  ) {
    if (!$endpoint) {
      $endpoint = $this->solr->getEndpoint(static::$PANTHEON_SOLR_DEFAULT_ENDPOINT);
    }

    $previous_timeout = $endpoint->getOption(self::QUERY_TIMEOUT);
    $options = $endpoint->getOptions();
    $options[self::QUERY_TIMEOUT] = $timeout;
    $endpoint = new Endpoint($options);
    return $previous_timeout;
  }

  /**
   * {@inheritdoc}
   */
  public function getTimeout(?\Solarium\Core\Client\Endpoint $endpoint = NULL) {
    if (!$endpoint) {
      $endpoint = $this->solr->getEndpoint(static::$PANTHEON_SOLR_DEFAULT_ENDPOINT);
    }

    return $endpoint->getOption(self::QUERY_TIMEOUT);
  }

  /**
   * {@inheritdoc}
   */
  public function getIndexTimeout(
    ?\Solarium\Core\Client\Endpoint $endpoint = NULL
  ) {
    if (!$endpoint) {
      $endpoint = $this->solr->getEndpoint(static::$PANTHEON_SOLR_DEFAULT_ENDPOINT);
    }

    return $endpoint->getOption(self::INDEX_TIMEOUT);
  }

  /**
   * {@inheritdoc}
   */
  public function getOptimizeTimeout(
    ?\Solarium\Core\Client\Endpoint $endpoint = NULL
  ) {
    if (!$endpoint) {
      $endpoint = $this->solr->getEndpoint(static::$PANTHEON_SOLR_DEFAULT_ENDPOINT);
    }
    return $endpoint->getOption(self::OPTIMIZE_TIMEOUT) ?? 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getFinalizeTimeout(
    ?\Solarium\Core\Client\Endpoint $endpoint = NULL
  ) {
    if (!$endpoint) {
      $endpoint = $this->solr->getEndpoint(static::$PANTHEON_SOLR_DEFAULT_ENDPOINT);
    }
    return $endpoint->getOption(self::FINALIZE_TIMEOUT);
  }

  /**
   * {@inheritdoc}
   */
  public function extract(
    QueryInterface $query,
    ?\Solarium\Core\Client\Endpoint $endpoint = NULL
  ) {
    return $this->execute($query, $endpoint);
  }

  /**
   * {@inheritdoc}
   */
  public function getContentFromExtractResult(ExtractResult $result, $filepath) {
    $array_data = $result->getData();

    if (isset($array_data[basename($filepath)])) {
      return $array_data[basename($filepath)];
    }

    // In most (or every) cases when an error happens we won't reach that point,
    // because a Solr exception is already pased through. Anyway, this exception
    // will be thrown if the solarium library surprises us again. ;-)
    throw new SearchApiSolrException(
      'Unable to find extracted files within the Solr response body.'
    );
  }

  /**
   * {@inheritdoc}
   */
  public function createEndpoint(
    string $key,
    array $additional_configuration = []
  ) {
    $configuration =
      [
        'key' => $key,
        self::QUERY_TIMEOUT => $this->configuration['timeout'],
      ] +
      $additional_configuration +
      $this->configuration;
    unset($configuration['timeout']);

    return $this->solr->addEndpoint(new Endpoint($configuration), TRUE);
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
  public function viewSettings() {
    $toReturn = [];

    $toReturn[] = [
      "label" => "Pantheon Sitename",
      "info" => Cores::getMyCoreName(),
    ];
    $toReturn[] = [
      'label' => 'Pantheon Environment',
      'info' => Cores::getMyEnvironment(),
    ];
    $toReturn[] = [
      'label' => 'Schema Version',
      'info' => $this->getSchemaVersion(TRUE),
    ];
    $coreInfo = $this->getCoreInfo(TRUE);
    foreach ($coreInfo['core'] as $key => $value) {
      if (is_string($value)) {
        $toReturn[] = [
          'label' => ucwords($key),
          'info' => $value,
        ];
      }
    }
    return $toReturn;
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaVersion($reset = FALSE) {
    $parts = explode('-', $this->getSchemaVersionString($reset));
    return $parts[1];
  }

  /**
   * {@inheritdoc}
   */
  public function __sleep() {
    // It's safe to unset the solr client completely before serialization
    // because connect() will set it up again correctly after deserialization.
    unset($this->solr);
    return parent::__sleep();
  }

  /**
   * {@inheritdoc}
   */
  public function alterConfigFiles(
    array &$files,
    string $lucene_match_version,
    string $server_id = ''
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function alterConfigZip(
    ZipStream $zip,
    string $lucene_match_version,
    string $server_id = ''
  ) {
  }

  /**
   *
   */
  public function reloadCore() {
    return TRUE;
  }

  /**
   * @param \Psr\Log\LoggerInterface $logger
   */
  public function setLogger(LoggerInterface $logger): void {
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->setConfiguration($form_state->getValues());
  }

  /**
   * @param array $configuration
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = array_merge($this->configuration, $configuration);
  }

}
