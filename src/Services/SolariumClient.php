<?php

namespace Drupal\search_api_pantheon\Services;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerAwareTrait;
use Solarium\Core\Client\Client;
use Solarium\Core\Client\Endpoint;
use Solarium\Core\Client\Request;
use Solarium\Core\Client\Response;
use Solarium\Core\Query\QueryInterface;
use Solarium\Core\Query\Result\ResultInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Customized Solrium Client to send Guzzle debugging to log entries.
 */
class SolariumClient extends Client {

  use LoggerAwareTrait;

  /**
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerChannelFactory
   * @param \Drupal\search_api_pantheon\Services\PantheonGuzzle $guzzle
   * @param \Drupal\search_api_pantheon\Services\Endpoint $endpoint
   */
  public function __construct(LoggerChannelFactoryInterface $loggerChannelFactory, PantheonGuzzle $guzzle, Endpoint $endpoint) {
    parent::__construct(
      $guzzle->getPsr18Adapter(),
      new EventDispatcher(),
      [ 'endpoint' => [$endpoint] ]
    );
    $this->logger = $loggerChannelFactory->get('PantheonSolariumClient');
    $this->setDefaultEndpoint($endpoint);
  }

  /**
   * Execute a query.
   *
   * @param QueryInterface       $query
   * @param Endpoint|string|null $endpoint
   *
   * @return ResultInterface
   */
  public function execute(QueryInterface $query, $endpoint = null): ResultInterface
  {
    return parent::execute($query, $this->defaultEndpoint);
  }

  /**
   * Execute a request and return the response.
   *
   * @param Request              $request
   * @param Endpoint|string|null $endpoint
   *
   * @return Response
   */
  public function executeRequest(Request $request, $endpoint = null): Response
  {
    return parent::executeRequest($request, $this->defaultEndpoint);
  }

}
