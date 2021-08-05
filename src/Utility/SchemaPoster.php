<?php

namespace Drupal\search_api_pantheon\Utility;

use Drupal\Core\Entity\EntityInterface;
use Drupal\search_api_solr\Controller\SolrConfigSetController;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Client\ClientInterface as PSR18Interface;
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
    protected bool $verbose = false;

  /**
   * @var
   */
    protected LoggerInterface $logger;

  /**
   * GuzzleHttp\Client definition.
   *
   * @var PSR18Interface
   */
    protected PSR18Interface $client;

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
    public function postSchema(string $server_id): array
    {
        $files = $this->getSolrFiles($server_id);
        $toReturn = [];
        foreach ($files as $filename => $file_contents) {
            try {
                $response = $this->uploadASchemaFile($filename, $file_contents);
                $logFunction = in_array($response->getStatusCode(), [200, 201, 202, 203]) ? 'notice' : 'error';
                $message = vsprintf('File: %s, Status code: %d - %s', [
                'filename' => $filename,
                'status_code' => $response->getStatusCode(),
                'reason' => $response->getReasonPhrase(),
                ]);
                $toReturn[] = $message;
                $this->getLogger()->{$logFunction}($message);
            } catch (\Exception $e) {
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
   * @throws \Psr\Http\Client\ClientExceptionInterface
   */
    protected function uploadASchemaFile(string $filename, string $file_contents): ?ResponseInterface
    {
        $content_type = 'text/plain';
        $path_info = pathinfo($filename);
        if ($path_info['extension'] == 'xml') {
            $content_type = 'application/xml';
        }

        $uri = new Uri(Cores::getBaseCoreUri() . 'admin/file');
        $uri->withQuery(
            http_build_query([
                         'action' => 'UPLOAD',
                         'file' => $filename,
                         'contentType' => $content_type,
                         'charset' => 'utf8',
                       ])
        );
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
   * @return PSR18Interface
   */
    public function getClient(): PSR18Interface
    {
        return $this->client;
    }

  /**
   * @param PSR18Interface $client
   */
    public function setClient(PSR18Interface $client): void
    {
        $this->client = $client;
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
        return $this->verbose;
    }

  /**
   * @param bool $isVerbose
   */
    public function setVerbose(bool $isVerbose): void
    {
        $this->verbose = $isVerbose;
    }
}
