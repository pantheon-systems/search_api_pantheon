<?php

namespace Drupal\search_api_pantheon\Utility;

use Drupal\Core\Entity\EntityInterface;
use Drupal\search_api_solr\Controller\SolrConfigSetController;
use GuzzleHttp\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Class SchemaPoster.
 *
 * @package Drupal\search_api_pantheon
 */
class SchemaPoster
{

  /**
   * @var bool $_isVerbose
   */
  protected bool $_isVerbose = false;

  /**
   * @var
   */
  protected LoggerInterface $logger;

  /**
   * GuzzleHttp\Client definition.
   *
   * @var GuzzleHttp\Client
   */
  protected ClientInterface $client;

  /**
   * Constructor.
   */
  public function __construct()
  {
    $this->logger = \Drupal::logger(__CLASS__);
    $this->client = SolrGuzzle::getConfiguredClientInterface();
  }

  /**
   * Post a schema file to to the Pantheon Solr server.
   */
  public function postSchema(string $server_id):array
  {
    $files = $this->getSolrFiles($server_id);
    $toReturn = [];
    foreach ($files as $filename => $file_contents) {
      try {
        $response = $this->uploadASchemaFile($filename, $file_contents);
        $success = in_array($response->getStatusCode(), [200, 201, 202, 203]);
        if (!$success) {
          $this->getLogger()
            ->error('Schema failed to post: {reason}',
                    ['reason' => $response->getReasonPhrase()]);
          $toReturn[] = sprintf('%s was not posted', $filename);
        } else {
          $this->getLogger()
            ->info('Schema posted');
          $toReturn[] = sprintf('%s posted', $filename);
        }
      } catch (\Exception $e) {
        $this->getLogger()
          ->error('Schema failed to post {reason}',
                  ['reason' => $e->getMessage()]);
      }
    }
    return $toReturn;
  }

  /**
   * @param $server_id
   *
   * @return array
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\search_api\SearchApiException
   * @throws \Drupal\search_api_solr\SearchApiSolrException
   */
  protected function getSolrFiles($server_id)
  {
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
   * @param string $filename
   * @param string $file_contents
   *
   * @return \Psr\Http\Message\ResponseInterface|null
   */
  protected function uploadASchemaFile(string $filename, string $file_contents): ?ResponseInterface
  {
    $content_type = 'text/plain';
    $path_info = pathinfo($filename);
    if ($path_info['extension'] == 'xml') {
      $content_type = 'application/xml';
    }
    $request_config = [
      'headers' => [
        'Accept' => 'application/json',
        'Content-Type' => $content_type,
      ],
      'query' => [
        'action' => 'UPLOAD',
        'file' => $filename,
        'contentType' => $content_type,
        'charset' => 'utf8',
      ],
      'body' => $file_contents,
      'debug' => $this->isVerbose(),
    ];
    $response = $this->getClient()->post(Cores::getBaseCoreUri() . 'admin/file', $request_config);
    $this->getLogger()->notice('File: {filename}, Status code: {status_code}', [
      'filename' => $filename,
      'status_code' => $response->getStatusCode(),
    ]);
    return $response;
  }

  /**
   * @return mixed
   */
  public function getLogger(): LoggerInterface
  {
    return $this->logger;
  }

  /**
   * @param mixed $logger
   */
  public function setLogger(LoggerInterface $logger): void
  {
    $this->logger = $logger;
  }



  /**
   * @return bool
   */
  protected function isVerbose(): bool
  {
    return $this->_isVerbose;
  }

  /**
   * @return \GuzzleHttp\ClientInterface
   */
  public function getClient()
  {
    return $this->client;
  }

  /**
   * @param \GuzzleHttp\ClientInterface $client
   */
  public function setClient(ClientInterface $client): void
  {
    $this->client = $client;
  }

  /**
   * @param bool $isVerbose
   */
  public function setVerbose(bool $isVerbose): void
  {
    $this->_isVerbose = $isVerbose;
  }


}
