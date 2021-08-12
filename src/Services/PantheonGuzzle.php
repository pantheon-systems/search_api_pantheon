<?php

namespace Drupal\search_api_pantheon\Services;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\search_api_pantheon\Endpoint;
use Drupal\search_api_pantheon\Plugin\SolrConnector\PantheonSolrConnector;
use Drupal\search_api_pantheon\Utility\Cores;
use GuzzleHttp\Client;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareTrait;
use Solarium\Client as SolrClient;
use Solarium\Core\Client\Adapter\AdapterInterface;
use Solarium\Core\Client\Adapter\Psr18Adapter;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Pantheon-specific extension of the Guzzle http query class.
 *
 * @package \Drupal\search_api_pantheon
 */
class PantheonGuzzle extends Client implements ClientInterface {

  use LoggerAwareTrait;

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannel
   *   Logger channel to which this class will log itself.
   */
  public function __construct(LoggerChannelFactoryInterface $loggerChannel) {
    $cert = $_SERVER['HOME'] . '/certs/binding.pem';
    $config = [
      'base_uri' => Cores::getBaseUri(),
      'http_errors' => TRUE,
      'debug' => (PHP_SAPI == 'cli'),
      'verify' => FALSE,
    ];
    if (is_file($cert)) {
      $config['cert'] = $cert;
    }
    parent::__construct($config);

    $this->setLogger($loggerChannel->get('PantheonGuzzle'));
  }

  /**
   * Send a guzzle request.
   *
   * @param \Psr\Http\Message\RequestInterface $request
   *   A PSR 7 request.
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   Response from the guzzle send.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function sendRequest(RequestInterface $request): ResponseInterface {
    return $this->send($request);
  }

  /**
   * Make a query and return the JSON results.
   *
   * @param string $path
   *   URL path to add to the query.
   * @param array $guzzleOptions
   *   Options to pass to the Guzzle client.
   *
   * @throws \JsonException
   * @throws \Exception
   *
   * @return mixed
   *   Response from the query.
   */
  public function getQueryResult(
    string $path,
    array $guzzleOptions = ['query' => [], 'headers' => []]
  ): array {
    $guzzleOptions['headers']['Accept'] = 'application/json';
    $response = $this->get(Cores::getBaseCoreUri() . $path, $guzzleOptions);
    if (!in_array($response->getStatusCode(), [200, 201, 202, 203, 204])) {
      throw new \Exception($response->getReasonPhrase());
    }
    return json_decode(
      $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR
    );
  }

  /**
   * Create a Solarium client.
   *
   * @return \Solarium\Client
   *   The Solarium client in question.
   */
  public function getSolrClient(): SolrClient {
    $config = [
      'endpoint' => [],
    ];
    $solr = new SolrClient(
      $this->getPsr18Adapter(),
      new EventDispatcher(),
      $config
    );
    $endpoint = new Endpoint([
      'collection' => NULL,
      'leader' => FALSE,
      'timeout' => 5,
      'solr_version' => '8',
      'http_method' => 'AUTO',
      'commit_within' => 1000,
      'jmx' => FALSE,
      'solr_install_dir' => '',
      'skip_schema_check' => FALSE,
    ]);
    $endpoint->setKey(PantheonSolrConnector::getDefaultEndpoint());
    $solr->addEndpoint($endpoint);
    return $solr;
  }

  /**
   * Get a PSR adapter interface based on this class.
   *
   * @return \Solarium\Core\Client\Adapter\AdapterInterface
   *   The interface in question.
   */
  public function getPsr18Adapter(): AdapterInterface {
    return new Psr18Adapter(
      $this,
      new RequestFactory(),
      new StreamFactory()
    );
  }

}
