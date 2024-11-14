<?php

namespace Drupal\search_api_pantheon\Services;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class Reload implements LoggerAwareInterface {
  use LoggerAwareTrait;

  /**
   * @var \Drupal\search_api_pantheon\Services\Reload
   */
  protected $configuration;
  /**
   * @var \Drupal\search_api_pantheon\Services\Reload
   */
  protected $logger_factory;

  /**
   * @var \Drupal\search_api_pantheon\Services\PantheonGuzzle
   */
  protected PantheonGuzzle $client;

  /**
   * Class Constructor.
   */
  public function __construct(
        LoggerChannelFactoryInterface $logger_factory,
        PantheonGuzzle $client,
    ) {
    $this->setLogger($logger_factory->get('reload_service'));
    $this->client = $client;
  }

  /**
   * Reload the server after schema upload.
   *
   * @throws \Drupal\search_api_pantheon\Exceptions\PantheonSearchApiException
   */
  public function reloadServer(): bool {
    // Schema upload URL.
    $uri = new Uri(
          $this->getClient()
            ->getEndpoint()
            ->getReloadUri()
      );

    $this->logger->debug('Reload url: ' . (string) $uri);

    // Send the request.
    $request = new Request(
          'POST',
          $uri,
          [
              'Accept' => 'application/json',
              'Content-Type' => 'application/json',
          ]
      );
    $response = $this->getClient()->sendRequest($request);

    $status_code = $response->getStatusCode();
    $reload_logger_content = [
          'status_code' => $status_code,
          'reason' => $response->getReasonPhrase(),
      ];
    if ($status_code >= 200 && $status_code < 300) {
      $this->logger->info('Server reloaded: {status_code} {reason}', $reload_logger_content);
      return TRUE;
    }
    $this->logger->error('Server not reloaded: {status_code} {reason}', $reload_logger_content);
    return FALSE;
  }

  public function getClient(): PantheonGuzzle {
    return $this->client;
  }

}
