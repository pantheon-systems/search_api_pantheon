<?php

namespace Drupal\search_api_pantheon\Services;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\search_api_pantheon\Utility\Cores;
use Drupal\search_api_solr\Controller\SolrConfigSetController;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Client\ClientInterface as PSR18Interface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Posting schema for Pantheon-specific solr driver.
 *
 * @package Drupal\search_api_pantheon
 */
class SchemaPoster {

  /**
   * Verbose debugging.
   *
   * @var bool
   */
  protected bool $verbose = FALSE;

  /**
   * The Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * GuzzleHttp\Client definition.
   *
   * @var \Psr\Http\Client\ClientInterface
   */
  protected PSR18Interface $client;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_channel_factory
   *   Injected when called as a service.
   * @param \Drupal\search_api_pantheon\Services\PantheonGuzzle $pantheon_guzzle_client
   *   Injected when called as a service.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_channel_factory, PantheonGuzzle $pantheon_guzzle_client) {
    $this->logger = $logger_channel_factory->get('PantheonSolr');
    $this->client = $pantheon_guzzle_client;
  }

  /**
   * Post a schema file to the Pantheon Solr server.
   *
   * @param string $server_id
   *   Search Api Server ID.
   *
   * @return array
   *   Array of response messages.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\search_api\SearchApiException
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function postSchema(string $server_id): array {
    $files = $this->getSolrFiles($server_id);
    $output = [];
    foreach ($files as $filename => $file_contents) {
      try {
        $response = $this->uploadSchemaFiles($files);
        $message = vsprintf('File: %s, Status code: %d - %s', [
          'filename' => $filename,
          'status_code' => $response->getStatusCode(),
          'reason' => $response->getReasonPhrase(),
        ]);
        $output[] = $message;
        $this->logger->debug($message);
      }
      catch (\Throwable $e) {
        $message = vsprintf('File: %s, Status code: %d - %s', [
          'filename' => $filename,
          'status_code' => $e->getCode(),
          'reason' => $e->getMessage(),
        ]);
        $output[] = $message;
        $this->logger->error($message);
      }
    }
    return $output;
  }

  /**
   * View a schema file on the pantheon solr server.
   *
   * @param string $filename
   *   The filename to view. Default is Schema.xml.
   *
   * @return string|null
   *   The text of the file or null on error or if the file doesn't exist.
   */
  public function viewSchema(string $filename = 'schema.xml'): ?string {
    try {
      $uri = (new Uri(Cores::getBaseCoreUri() . 'admin/file'))
        ->withQuery(
          http_build_query([
            'action' => 'VIEW',
            'file' => $filename,
          ])
        );
      $this->logger->debug('Upload url: ' . $uri);
      $request = new Request('GET', $uri);
      $response = $this->client->sendRequest($request);
      $message = vsprintf('File: %s, Status code: %d - %s', [
        'filename' => $filename,
        'status_code' => $response->getStatusCode(),
        'reason' => $response->getReasonPhrase(),
      ]);
      $this->logger->debug($message);

      return $response->getBody();
    }
    catch (\Throwable $e) {
      $message = vsprintf('File: %s, Status code: %d - %s', [
        'filename' => $filename,
        'status_code' => $e->getCode(),
        'reason' => $e->getMessage(),
      ]);
      $this->logger->error($message);
    }

    return NULL;
  }

  /**
   * Get the schema and config files for posting on the solr server.
   *
   * @param string $server_id
   *   The Search API server id. Typically, `pantheon_solr8`.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\search_api\SearchApiException
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   * @throws \Exception
   *
   * @return array
   *   Array of key-value pairs: 'filename' => 'file contents'.
   */
  public function getSolrFiles(string $server_id = 'pantheon_solr8') {
    /** @var \Drupal\search_api\ServerInterface $server */
    $server = \Drupal::entityTypeManager()
      ->getStorage('search_api_server')
      ->load($server_id);

    if (!$server instanceof EntityInterface) {
      throw new \Exception('cannot retrieve the solr server connection settings from the database');
    }
    $solr_configset_controller = new SolrConfigSetController();
    $solr_configset_controller->setServer($server);

    return $solr_configset_controller->getConfigFiles();
  }

  /**
   * Upload schema files to server.
   *
   * @param array $schemaFiles
   *   A key => value paired array of filenames => file_contents.
   *
   * @return \Psr\Http\Message\ResponseInterface|null
   *   A PSR-7 response object from the API call.
   */
  public function uploadSchemaFiles(array $schemaFiles): ?ResponseInterface {
    // Schema upload URL.
    $uri = (new Uri(Cores::getSchemaUploadUri()));
    $this->logger->debug('Upload url: ' . (string) $uri);

    // Build the files array.
    $toSend = ['files' => []];
    foreach ($schemaFiles as $filename => $file_contents) {
      $this->logger->notice('Encoding file: {filename}', [
        'filename' => $filename,
      ]);
      $toSend['files'][] = [
        'filename' => $filename,
        'content' => base64_encode($file_contents),
      ];
    }

    // Send the request.
    $request = new Request('POST', $uri, [
      'Accept' => 'application/json',
      'Content-Type' => 'application/json',
    ], json_encode($toSend));
    $response = $this->getClient()->sendRequest($request);

    // Parse the response.
    $log_function = in_array($response->getStatusCode(), [200, 201, 202, 203]) ? 'notice' : 'error';
    $this->logger->{$log_function}('Files uploaded: {status_code} {reason}', [
      'status_code' => $response->getStatusCode(),
      'reason' => $response->getReasonPhrase(),
    ]);
    return $response;
  }

  /**
   * Get verbosity.
   *
   * @return bool
   *   Whether or not to turn on long debugging.
   */
  protected function isVerbose(): bool {
    return $this->verbose;
  }

  /**
   * Set Verbosity.
   *
   * @param bool $isVerbose
   *   Verbosity value.
   */
  public function setVerbose(bool $isVerbose): void {
    $this->verbose = $isVerbose;
  }

  /**
   * Get Logger Instance.
   *
   * @return \Psr\Log\LoggerInterface
   *   Drupal's Logger Interface.
   */
  public function getLogger() {
    return $this->logger;
  }

  /**
   * Set Logger Instance.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   Drupal's Logger Interface.
   */
  public function setLogger(LoggerInterface $logger): void {
    $this->logger = $logger;
  }

  /**
   * Get Pantheon Client instance.
   *
   * @return \Psr\Http\Client\ClientInterface
   *   Pantheon Guzzle Client.
   */
  public function getClient() {
    return $this->client;
  }

  /**
   * Set Pantheon Client Instance.
   *
   * @param \Psr\Http\Client\ClientInterface $client
   *   Pantheon Guzzle Client.
   */
  public function setClient(ClientInterface $client): void {
    $this->client = $client;
  }

}
