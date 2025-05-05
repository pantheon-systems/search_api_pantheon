<?php

namespace Drupal\search_api_pantheon\Services;

use Drupal\Component\FileSystem\FileSystem;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_pantheon\GetPantheonSolrServerTrait;
use Drupal\search_api_pantheon\Plugin\SolrConnector\PantheonSolrConnector;
use Drupal\search_api_solr\Controller\SolrConfigSetController;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Solarium\Core\Client\Response;

/**
 * Posting schema for the Pantheon specific SOLR driver.
 *
 * @package Drupal\search_api_pantheon
 */
class SchemaPoster implements LoggerAwareInterface {

  use LoggerAwareTrait;
  use StringTranslationTrait;
  use GetPantheonSolrServerTrait;

  /**
   * Class Constructor.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    // ::uploadSchemaAsZip() needs this.
    protected ClientInterface $client,
    protected ModuleExtensionList $moduleExtensionList,
  ) {
    $this->logger = $logger_factory->get('PantheonSearch');
  }

  /**
   * Post a schema file to the Pantheon Solr server.
   *
   * @param ?string $server_id
   *   Search Api Server ID.
   * @param array $files
   *   Array of files to post.
   *
   * @return array
   *   Message to be displayed to the user (type, message).
   *
   * @throws \Drupal\search_api\SearchApiException
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function postSchema(?string $server_id, array $files = []): array {
    $server = $this->getPantheonSolrServer($server_id);
    // PANTHEON Environment.
    if (isset($_ENV['PANTHEON_ENVIRONMENT'])) {
      if (!$files) {
        $files = $this->getSolrFiles($server);
      }
      $response = $this->getPantheonSolrConnector($server)->postSchema($files);
    }
    // LOCAL DOCKER.
    if (isset($_SERVER['ENV']) && $_SERVER['ENV'] === 'local') {
      $response = $this->uploadSchemaAsZip($server);
    }
    if (!isset($response)) {
      throw new \Exception('Cannot post schema to environment url.');
    }

    $status_code = $response->getStatusCode();
    $this->logger->info('Status code: ' . $status_code);
    if ($status_code >= 200 && $status_code < 300) {
      // Call reload on the server.
      $this->getPantheonSolrConnector($server)->reloadCore();
    }
    return $this->processResponse($response);
  }

  /**
   * Process response and return message to be shown to the user.
   *
   * @param \Solarium\Core\Client\Response $response
   *   Response object from Solarium.
   *
   * @return array
   *   Message to be displayed to the user (type, message).
   */
  public function processResponse(Response $response): array {
    $logMethod = PantheonSolrConnector::getLogMethod($response);
    $message = vsprintf($this->t('Result: %s Status code: %d - %s'), [
      $logMethod == 'error' ? 'NOT UPLOADED' : 'UPLOADED',
      $response->getStatusCode(),
      $response->getStatusMessage(),
    ]);
    return [$logMethod, $message];
  }

  /**
   * Upload one at a time to docker-compose's Solr instance.
   *
   * @param string $server_id
   *   Server ID to upload to.
   *
   * @return array
   *   Status messages from each of the calls.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\search_api\SearchApiException
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
    // @codingStandardsIgnoreLine

  /**
   * Get the schema and config files for posting on the solr server.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The server the files will be uploaded to.
   *
   * @return array
   *   Array of key-value pairs: 'filename' => 'file contents'.
   *
   * @throws \Drupal\search_api\SearchApiException
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function getSolrFiles(ServerInterface $server): array {
    $solr_config_set_controller = new SolrConfigSetController($this->moduleExtensionList);
    $solr_config_set_controller->setServer($server);

    return $solr_config_set_controller->getConfigFiles();
  }

  /**
   * Upload the schema files as a zipped archive.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The server the files will be uploaded to.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *
   * @throws \Drupal\search_api\SearchApiException
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function uploadSchemaAsZip(ServerInterface $server): ResponseInterface {
    $path_to_zip = $this->getSolrFilesAsZip($server);
    $endpoint = $this->getPantheonSolrConnector($server)->getEndpoint();
    // There's no way to get a URL without a path from Solarium, so this
    // needs to reassemble the URL.
    $url = sprintf('%s://%s:%s/api/core/configs/_default', $endpoint->getScheme(), $endpoint->getHost(), $endpoint->getPort());
    // @todo convert this to Solarium as well.
    return $this->client->put(
      $url,
      [
        'query' => [
          'action' => 'UPLOAD',
          'name' => '_default',
          'overwrite' => 'TRUE',
          'configSet' => '_default',
          'create' => 'TRUE',
        ],
        'body' => Utils::tryFopen($path_to_zip, 'r'),
        'headers' => [
          'Content-Type' => 'application/octet-stream',
        ],
      ]
    );
  }

  /**
   * Get the solr schema files as a zip archive.
   *
   * @param \Drupal\search_api\ServerInterface $server
   *   The server the files will be uploaded to.
   *
   * @return string
   *   The path to the zip file.
   *
   * @throws \Drupal\search_api\SearchApiException
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  public function getSolrFilesAsZip(ServerInterface $server): string {
    $files = $this->getSolrFiles($server);
    $temp_dir =
            FileSystem::getOsTemporaryDirectory() .
            DIRECTORY_SEPARATOR .
            uniqid('search_api_pantheon-');
    $zip_archive = new \ZipArchive();
    $zip_archive->open($temp_dir . '.zip', \ZipArchive::CREATE);
    foreach ($files as $filename => $file_contents) {
      $zip_archive->addFromString($filename, $file_contents);
    }
    $zip_archive->close();
    return $temp_dir . '.zip';
  }

  /**
   * View a schema file on the pantheon solr server.
   *
   * @param string $filename
   *   The filename to view. The default is Schema.xml.
   *
   * @return string|null
   *   The text of the file or null on error or if the file doesn't exist.
   */
  public function viewSchema(string $filename = 'schema.xml'): ?string {
    try {
      $response = $this->getPantheonSolrConnector()->getFile($filename);
      $message = vsprintf($this->t('File: %s, Status code: %d - %s'), [
        'filename' => $filename,
        'status_code' => $response->getStatusCode(),
        'status_message' => $response->getStatusMessage(),
      ]);
      $this->logger->debug($message);

      return $response->getBody();
    }
    catch (\Throwable $e) {
      $message = vsprintf($this->t('File: %s, Status code: %d - %s'), [
        'filename' => $filename,
        'status_code' => $e->getCode(),
        'message' => $e->getMessage(),
      ]);
      $this->logger->error($message);
    }

    return NULL;
  }

}
