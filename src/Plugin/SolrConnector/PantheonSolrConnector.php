<?php

namespace Drupal\search_api_pantheon\Plugin\SolrConnector;

use Drupal\search_api_pantheon\Solarium\PantheonSolrCurl;
use Drupal\search_api_solr\Plugin\SolrConnector\StandardSolrConnector;
use Drupal\Core\Form\FormStateInterface;
use Solarium\Core\Client\Request;
use Solarium\Core\Client\Response;
use Solarium\QueryType\Select\Query\Query;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Standard Solr connector.
 *
 * @SolrConnector(
 *   id = "pantheon",
 *   label = @Translation("Pantheon"),
 *   description = @Translation("A connector for Pantheon's Solr server")
 * )
 */
class PantheonSolrConnector extends StandardSolrConnector {

  /**
   * Creates a pantheon solr connector instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   *
   * @SuppressWarnings(PHPMD.Superglobals)
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition) {
    // While normally reading global state should be in ::create() the very
    // point of this class is to force override the configuration with these
    // environment variables. Testability is not a problem thanks to putenv()
    // and more importantly, any testing would be pointless if it didn't
    // assert these overrides.
    if ($overrides = static::getEnvironmentVariables()) {
      $configuration = $overrides + $configuration;
      // This is used in Endpoint::getCollectionBaseUri() and similar. Usually
      // it's "solr" but the Pantheon endpoint does not have a /solr/ part in
      // their path.
      $configuration['context'] = '';
    }
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $plugin->logger = $container->get('logger.channel.search_api_pantheon');
    return $plugin;
  }

  /**
   * The environment variables overriding server configuration form values.
   *
   * @return array
   */
  public static function getEnvironmentVariables(): array {
    if (!getenv('PANTHEON_ENVIRONMENT')) {
      return [];
    }
    return [
      'scheme' => 'https',
      'host' => getenv('PANTHEON_INDEX_HOST'),
      'port' => getenv('PANTHEON_INDEX_PORT'),
      // Just the string "v1". In Endpoint::getServerUri() this will be
      // appended directly to the port which means an additional slash is
      // required.
      'path' => '/' . getenv('PANTHEON_INDEX_PATH'),
      // This is set to "/site/{site-uuid}/environment/{env}/backend" and
      // the core can't start with a slash.
      'core' => trim(getenv('PANTHEON_INDEX_CORE'), '/'),
      'solr_version' => getenv('PANTHEON_SEARCH_VERSION'),
      // This is set to "/site/{site-uuid}/environment/{env}/configs",
      // very similar to core and so also can't start with a slash. It is used
      // by ::postSchema().
      'search_api_pantheon_schema_endpoint' => trim(getenv('PANTHEON_INDEX_SCHEMA'), '/'),
      // Same for "/site/{site-uuid}/environment/{env}/reload". It is used by
      // ::reloadCore().
      'search_api_pantheon_reload_endpoint' => trim(getenv('PANTHEON_INDEX_RELOAD_PATH'), '/'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    foreach (array_keys(static::getEnvironmentVariables()) as $key) {
      if (isset($form[$key])) {
        $form[$key]['#disabled'] = TRUE;
        $form[$key]['#description'] = t('These fields are populated by Pantheon infrastructure.');
      }
    }
    // Keep workarounds and advanced disabled on local too so when config is
    // exported then there's no chance of it messing with Pantheon.
    $form['workarounds']['#access'] = FALSE;
    // JTS is disabled.
    $form['advanced']['#access'] = FALSE;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getServerInfo($reset = FALSE) {
    // The parent uses a system-wide endpoint which is not supported on
    // Pantheon but the core specific one works everywhere.
    return $this->getDataFromHandler($this->configuration['core'] . '/admin/system', $reset);
  }

  /**
   * {@inheritdoc}
   */
  protected function createClient(array &$configuration) {
    $client = parent::createClient($configuration);
    //  When running on the Pantheon platform, use the pre-configured  curl options from Pantheon Preprend file.
    if (extension_loaded('curl') && getenv('PANTHEON_ENVIRONMENT')) {
      $adapter = new PantheonSolrCurl();
      $adapter->setTimeout($configuration[self::QUERY_TIMEOUT]);
      $client->setAdapter($adapter);
    }
    return $client;
  }

  /**
   * Posts schema files to the proprietary Pantheon endpoint.
   *
   * @param array $schemaFiles
   *   A key => value paired array of filenames => file_contents.
   *
   * @return \Solarium\Core\Client\Response
   *   The Solarium response object.
   *
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   *
   * @internal
   */
  public function postSchema(array $schemaFiles): Response {
    $this->useTimeout();
    $filesToSend = [];
    foreach ($schemaFiles as $filename => $file_contents) {
      $this->logger->info($this->t('Encoding file: {filename}'), [
        'filename' => $filename,
      ]);
      $filesToSend['files'][] = [
        'filename' => $filename,
        'content' => base64_encode($file_contents),
      ];
    }

    $request = (new Request())
      ->setHandler($this->configuration['search_api_pantheon_schema_endpoint'])
      ->setIsServerRequest(TRUE)
      ->setMethod(Request::METHOD_POST)
      ->setContentType('application/json')
      ->setRawData(json_encode($filesToSend));
    $response = $this->executeRequest($request);
    $logMethod = static::getLogMethod($response);
    $this->logger->{$logMethod}($this->t('Files uploaded: {status_code} {status_message}'), [
      'status_code' => $response->getStatusCode(),
      'status_message' => $response->getStatusMessage(),
    ]);
    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function reloadCore(): bool {
    if (!isset($this->configuration['search_api_pantheon_reload_endpoint'])) {
      return parent::reloadCore();
    }
    $this->useTimeout(self::INDEX_TIMEOUT);
    $request = (new Request())
      ->setHandler($this->configuration['search_api_pantheon_reload_endpoint'])
      ->setIsServerRequest(TRUE)
      ->setMethod(Request::METHOD_POST)
      ->setContentType('application/json');
    $response = $this->executeRequest($request);
    $logMethod = static::getLogMethod($response);
    $this->logger->{$logMethod}($this->t('Core reload: {status_code} {status_message}'), [
      'status_code' => $response->getStatusCode(),
      'status_message' => $response->getStatusMessage(),
    ]);
    return $logMethod === 'info';
  }

  /**
   * @param \Solarium\Core\Client\Response $response
   *   The solarium response.
   *
   * @return string
   *   info when 2xx, error otherwise.
   */
  public static function getLogMethod(Response $response): string {
    $statusCode = (string) $response->getStatusCode();
    return ($statusCode[0] ?? '') === '2' ? 'info' : 'error';
  }

  /**
   * Gets summary information about the Solr Core.
   *
   * Overrides the parent to reliably return core name on Pantheon.
   * Uses null-safe access to handle response format differences
   * between Solr 8 and 9.
   */
  public function getStatsSummary() {
    $summary = [
      '@pending_docs' => '',
      '@core_name' => '',
      '@index_size' => '',
      '@schema_version' => '',
    ];

    $query = $this->solr->createPing();
    $query->setResponseWriter(Query::WT_PHPS);
    $query->setHandler('admin/mbeans?stats=true');
    $stats = $this->execute($query)->getData();

    if (!empty($stats)) {
      $update_handler_stats = $stats['solr-mbeans']['UPDATE']['updateHandler']['stats'] ?? [];
      $summary['@pending_docs'] = (int) ($update_handler_stats['UPDATE.updateHandler.docsPending'] ?? 0);
      $summary['@core_name'] = $stats['solr-mbeans']['CORE']['core']['class'] ?? $this->t('No information available.');
      $summary['@index_size'] = $stats['solr-mbeans']['CORE']['searcher']['stats']['SEARCHER.searcher.numDocs'] ?? $this->t('No information available.');
      $summary['@schema_version'] = $this->getSchemaVersionString(TRUE);
    }
    return $summary;
  }

}
