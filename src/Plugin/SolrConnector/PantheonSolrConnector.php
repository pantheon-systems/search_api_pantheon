<?php

namespace Drupal\search_api_pantheon\Plugin\SolrConnector;

use Drupal\search_api_pantheon\Solarium\PantheonCurl;
use Drupal\search_api_solr\Plugin\SolrConnector\StandardSolrConnector;
use Drupal\Core\Form\FormStateInterface;
use Solarium\Core\Client\Request;
use Solarium\Core\Client\Response;
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

  public function __construct(array $configuration, $plugin_id, array $plugin_definition) {
    // While normally reading global state should be in ::create() the very
    // point of this class is to force override the configuration with these
    // environment variables. Testability is not a problem thanks to putenv()
    // and more importantly, any testing would be pointless if it didn't
    // assert these overrides.
    if (getenv('PANTHEON_ENVIRONMENT')) {
      $configuration = static::getEnvironmentVariables() + $configuration;
      $configuration['search_api_pantheon_cert'] = ($_SERVER['HOME'] ?? '') . '/certs/binding.pem';
      // This is used in Endpoint::getCollectionBaseUri() and similar.
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
      'solr_version' => 8,
      // This is set to "/site/{site-uuid}/environment/{env}/configs",
      // very similar to core and so also can't start with a slash.
      'search_api_pantheon_schema_endpoint' => trim(getenv('PANTHEON_INDEX_SCHEMA'), '/'),
      // Same for "/site/{site-uuid}/environment/{env}/reload",
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
        $form[$key]['#description'] = t('These fields are populated by Pantheon infrastructure".');
      }
    }
    $form['workarounds']['#access'] = FALSE;
    // @todo explore whether jts works.
    $form['advanced']['#access'] = FALSE;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getServerInfo($reset = FALSE) {
    // The parent uses a system-wide endpoint which is not supported on
    // Pantheon.
    return $this->getDataFromHandler($this->configuration['core'] . '/admin/system', $reset);
  }

  /**
   * {@inheritdoc}
   */
  protected function createClient(array &$configuration) {
    $client = parent::createClient($configuration);
    if (extension_loaded('curl') && isset($this->configuration['search_api_pantheon_cert'])) {
      $adapter = new PantheonCurl($this->configuration['search_api_pantheon_cert']);
      // This line is copy-pasted from the parent method.
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
  public function reloadCore(): void {
    parent::reloadCore();
    $request = (new Request())
      ->setHandler($this->configuration['search_api_pantheon_reload_endpoint'])
      ->setMethod(Request::METHOD_POST)
      ->setContentType('application/json');
    $response = $this->executeRequest($request);
    $logMethod = static::getLogMethod($response);
    $this->logger->{$logMethod}($this->t('Core reload: {status_code} {status_message}'), [
      'status_code' => $response->getStatusCode(),
      'status_message' => $response->getStatusMessage(),
    ]);
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

}
