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
   * The injected Logger Factory interface.
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
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   *   Injected when called as a service.
   * @param \Drupal\search_api_pantheon\Services\PantheonGuzzle $pantheonGuzzle
   *   Injected when called as a service.
   */
  public function __construct(LoggerChannelFactoryInterface $loggerChannelFactory, PantheonGuzzle $pantheonGuzzle) {
    $this->logger = $loggerChannelFactory->get('PantheonSolr');
    $this->client = $pantheonGuzzle;
  }

  /**
   * Post a schema file to to the Pantheon Solr server.
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
   * @throws \Psr\Http\Client\ClientExceptionInterface
   */
  public function postSchema(string $server_id): array {
    $files = $this->getSolrFiles($server_id);
    $toReturn = [];
    foreach ($files as $filename => $file_contents) {
      try {
        $response = $this->uploadSchemaFile($filename, $file_contents);
        $logFunction = in_array($response->getStatusCode(), [200, 201, 202, 203]) ? 'notice' : 'error';
        $message = vsprintf('File: %s, Status code: %d - %s', [
          'filename' => $filename,
          'status_code' => $response->getStatusCode(),
          'reason' => $response->getReasonPhrase(),
        ]);
        $toReturn[] = $message;
        $this->getLogger()->{$logFunction}($message);
      }
      catch (\Exception $e) {
        $toReturn[] = vsprintf('File: %s, Status code: %d - %s', [
          'filename' => $filename,
          'status_code' => $e->getCode(),
          'reason' => $e->getMessage(),
        ]);
      }
    }
    return $toReturn;
  }

  /**
   * View a schema file on the pantheon solr server.
   *
   * @param string $server_id
   *   Search API solr server ID. Typically `pantheon_solr8`.
   * @param string $filename
   *   The filename to view. Default is Schema.xml.
   *
   * @return string|null
   *   The text of the file or null on error or if the file doesn't exist.
   *
   * @throws \Psr\Http\Client\ClientExceptionInterface
   */
  public function viewSchema(string $server_id = 'pantheon_solr8', $filename = 'schema.xml'): ?string {
    try {
      $uri = (new Uri(Cores::getBaseCoreUri() . 'admin/file'))
        ->withQuery(
          http_build_query([
            'action' => 'VIEW',
            'file' => $filename,
          ])
        );
      $this->getLogger()->debug('Upload url: ' . (string) $uri);
      $request = new Request('GET', $uri, [
        'Accept' => 'application/json',
      ]);
      $response = $this->getClient()->sendRequest($request);
      $logFunction = in_array($response->getStatusCode(), [200, 201, 202, 203]) ? 'notice' : 'error';
      $message = vsprintf('File: %s, Status code: %d - %s', [
        'filename' => $filename,
        'status_code' => $response->getStatusCode(),
        'reason' => $response->getReasonPhrase(),
      ]);
      $this->getLogger()->{$logFunction}($message);
      return $response->getBody();
    }
    catch (\Exception $e) {
      $toReturn[] = vsprintf('File: %s, Status code: %d - %s', [
        'filename' => $filename,
        'status_code' => $e->getCode(),
        'reason' => $e->getMessage(),
      ]);
    }
    return NULL;
  }

  /**
   * Get the schema and config files for posting on the solr server.
   *
   * @param string $server_id
   *   The Search API server id. Typically `pantheon_solr8`.
   *
   * @return array
   *   Array of key-value pairs: 'filename' => 'file contents'.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\search_api\SearchApiException
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function getSolrFiles(string $server_id = 'pantheon_solr8') {
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
   * @return \Psr\Http\Message\ResponseInterface|null
   *   Response from the guzzle call to upload.
   *
   * @throws \Psr\Http\Client\ClientExceptionInterface
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
    $this->getLogger()->debug('Upload url: ' . (string) $uri);
    $request = new Request('POST', $uri, [
      'Accept' => 'application/json',
      'Content-Type' => $content_type,
    ], $file_contents);
    $response = $this->getClient()->sendRequest($request);
    $logFunction = in_array($response->getStatusCode(), [200, 201, 202, 203]) ? 'notice' : 'error';
    $this->getLogger()->{$logFunction}('File: {filename}, Status code: {status_code} - {reason}', [
      'filename' => $filename,
      'status_code' => $response->getStatusCode(),
      'reason' => $response->getReasonPhrase(),
    ]);
    return $response;
  }

  /**
   * Get Client.
   *
   * @return \Psr\Http\Client\ClientInterface
   *   Psr 18 Client, typically PantheonGuzzle instance.
   */
  public function getClient(): PSR18Interface {
    return $this->client;
  }

  /**
   * Set Client.
   *
   * @param \Psr\Http\Client\ClientInterface $client
   *   Psr 18 Client, typically PantheonGuzzle instance.
   */
  public function setClient(PSR18Interface $client): void {
    $this->client = $client;
  }

  /**
   * Get Logger.
   *
   * @return \Psr\Log\LoggerInterface
   *   PSR Logger interface.
   */
  public function getLogger(): LoggerInterface {
    return $this->logger;
  }

  /**
   * Set Logger.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger Channel to which these actions will be logged.
   */
  public function setLogger(LoggerInterface $logger): void {
    $this->logger = $logger;
  }

  /**
   * Get verbosity.
   *
   * @return bool
   *   Wheither or not to turn on long debugging.
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
