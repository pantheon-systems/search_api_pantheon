<?php

namespace Drupal\search_api_pantheon\Services;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\search_api_pantheon\Utility\Cores;
use Drupal\search_api_solr\Controller\SolrConfigSetController;
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
        $response = $this->uploadSchemaFile($filename, $file_contents);
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
      $request = new Request('GET', $uri, [
        'Accept' => 'application/json',
      ]);
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
  protected function getSolrFiles(string $server_id) {
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
   * Upload a schema file.
   *
   * @param string $filename
   *   Name of the file being uploaded.
   * @param string $file_contents
   *   Contents of the file being uploaded.
   *
   * @throws \Psr\Http\Client\ClientExceptionInterface
   * @throws \GuzzleHttp\Exception\GuzzleException
   *
   * @return \Psr\Http\Message\ResponseInterface|null
   *   Response from the Guzzle call to upload.
   */
  public function uploadSchemaFile(string $filename, string $file_contents): ?ResponseInterface {
    $content_type = 'text/plain';
    $path_info = pathinfo($filename);
    if ($path_info['extension'] == 'xml') {
      $content_type = 'application/xml';
    }

    $uri = (new Uri(Cores::getBaseCoreUri() . 'admin/file'))
      ->withQuery(
        http_build_query([
          'action' => 'UPLOAD',
          'file' => $filename,
          'contentType' => $content_type,
          'charset' => 'utf8',
        ])
      );
    $this->logger->debug('Upload url: ' . $uri);
    $request = new Request('POST', $uri, [
      'Accept' => 'application/json',
      'Content-Type' => $content_type,
    ], $file_contents);
    $response = $this->client->sendRequest($request);
    $this->logger->debug('File: {filename}, Status code: {status_code} - {reason}', [
      'filename' => $filename,
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

}
