<?php

namespace Drupal\search_api_pantheon\Services;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\search_api_pantheon\Traits\EndpointAwareTrait;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Utils;
use GuzzleHttp\Handler\CurlHandler;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Solarium\Core\Client\Adapter\AdapterInterface;
use Solarium\Core\Client\Adapter\Psr18Adapter;

use function GuzzleHttp\choose_handler;

/**
 * Pantheon-specific extension of the Guzzle http query class.
 *
 * @package \Drupal\search_api_pantheon
 */
class PantheonGuzzle extends Client implements
    ClientInterface,
    LoggerAwareInterface {

  use LoggerAwareTrait;
  use EndpointAwareTrait;

  /**
   * Class constructor.
   */
  public function __construct(LoggerChannelFactoryInterface $loggerChannelFactory, Endpoint $endpoint) {

    $stack = new HandlerStack();
    $stack->setHandler(new CurlHandler());
    $stack->push(Middleware::mapRequest([$this, 'requestUriAlterForPantheonEnvironment']));
    $cert = $_SERVER['HOME'] . '/certs/binding.pem';
    $config = [
      'base_uri' => $endpoint->getCoreBaseUri(),
      'http_errors' => FALSE,
      // Putting `?debug=true` at the end of any Solr url will show you the low-level debugging from guzzle.
      // @codingStandardsIgnoreLine
      'debug' => (PHP_SAPI == 'cli' || isset($_GET['debug'])),
      'verify' => FALSE,
      'handler' => $stack,
    ];
    if (is_file($cert)) {
      $config['cert'] = $cert;
    }
    parent::__construct($config);
    $this->endpoint = $endpoint;
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
    array $guzzleOptions = ['query' => [], 'headers' => ['application/json']]
  ) {
    $response = $this->get( $path, $guzzleOptions);
    if (!in_array($response->getStatusCode(), [200, 201, 202, 203, 204])) {
      $this->logger->error('Query Failed: ' . $response->getReasonPhrase());
    }
    $content_type = $response->getHeader('Content-Type')[0] ?? '';
    if (strpos($content_type, 'application/json') !== FALSE) {
      return json_decode(
        $response->getBody(),
        TRUE,
        512,
        JSON_THROW_ON_ERROR
      );
    }
    return $response->getBody();
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

  /**
   * Request Middleware Callback.
   *
   * @param \Psr\Http\Message\RequestInterface $r
   *
   * @return \Psr\Http\Message\RequestInterface
   */
  function requestUriAlterForPantheonEnvironment(RequestInterface $r)
  {
    $uri = $r->getUri();
    $path = $uri->getPath();
    if (strpos($path, $this->endpoint->getPath()) === false) {
      $uri = $uri->withPath($this->endpoint->getPath() . $this->endpoint->getCore() . $path);
      return $r->withUri($uri);
    }
    return $r;
  }

}
