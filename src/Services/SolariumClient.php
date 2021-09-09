<?php

namespace Drupal\search_api_pantheon\Services;

use League\Container\ContainerAwareTrait;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerAwareTrait;
use Solarium\Core\Client\Client;
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
  use ContainerAwareTrait;

  /**
   * Class constructor.
   *
   * @param \Psr\Container\ContainerInterface $container
   *   Container interface.
   */
  public function __construct(ContainerInterface $container) {
    $guzzle = $container->get('search_api_pantheon.pantheon_guzzle');
    $endpoint = $container->get('search_api_pantheon.endpoint');
    parent::__construct(
          $guzzle->getPsr18Adapter(),
          new EventDispatcher(),
          ['endpoint' => [$endpoint]]
      );
    $this->container = $container;
    $this->logger = $container
      ->get('logger.factory')
      ->get('PantheonSolariumClient');
    $this->setDefaultEndpoint($endpoint);
  }

  /**
   * Always use the default endpoint.
   *
   * @param \Solarium\Core\Query\QueryInterface $query
   * @param \Solarium\Core\Client\Endpoint|string|null $endpoint
   *
   * @return \Solarium\Core\Query\Result\ResultInterface
   */
  public function execute(QueryInterface $query, $endpoint = NULL): ResultInterface {
    return parent::execute($query, $this->defaultEndpoint);
  }

  /**
   * Always use the default endpoint.
   *
   * @param \Solarium\Core\Client\Request $request
   * @param \Solarium\Core\Client\Endpoint|string|null $endpoint
   *
   * @return \Solarium\Core\Client\Response
   */
  public function executeRequest(Request $request, $endpoint = NULL): Response {
    return parent::executeRequest($request, $this->defaultEndpoint);
  }

}
