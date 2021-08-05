<?php

namespace Drupal\search_api_pantheon\Services;

use Drupal\search_api_pantheon\PantheonSearchApiException;
use Drupal\search_api_pantheon\Utility\Cores;
use Drupal\search_api_pantheon\Utility\SolrGuzzle;
use GuzzleHttp\Psr7\Request;

/**
 * The Pantheon SolrConfig service.
 *
 * @package Drupal\search_api_pantheon\Services
 */
class SolrConfig {

  /**
   * Returns the Solr config by config path and name.
   *
   * @param string $path
   *   The config path.
   * @param string|null $name
   *   The config name to return a part of the config or NULL to return all.
   *
   * @throws \Drupal\search_api_pantheon\PantheonSearchApiException
   *
   * @return array|null
   *   The config value.
   */
  public function get(string $path, ?string $name = NULL): ?array {
    $uri = Cores::getBaseCoreUri() . $path;

    try {
      $request = new Request('GET', $uri);
      $client = SolrGuzzle::getConfiguredClientInterface();
      $response = $client->sendRequest($request);
    }
    catch (\Throwable $e) {
      throw new PantheonSearchApiException(
        sprintf(
          'Failed requesting Solr endpoint %s: %s.',
          $uri,
          $e->getMessage()
        )
      );
    }

    if (!preg_match('/2\d\d/', $response->getStatusCode())) {
      throw new PantheonSearchApiException(
        sprintf(
          'Solr endpoint %s returned non 2xx status code: %s.',
          $uri,
          $response->getStatusCode()
        )
      );
    }

    try {
      $response_body = json_decode($response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      throw new PantheonSearchApiException(
        sprintf(
          'Failed decoding response from Solr endpoint %s: %s.',
          $uri,
          $e->getMessage()
        )
      );
    }

    if (is_null($name)) {
      return $response_body;
    }

    if (!isset($response_body[$name])) {
      return NULL;
    }

    return $response_body[$name];
  }

}
