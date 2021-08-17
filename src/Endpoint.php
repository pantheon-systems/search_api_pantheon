<?php

namespace Drupal\search_api_pantheon;

use Drupal\search_api_pantheon\Utility\Cores;
use Psr\Log\LoggerAwareTrait;
use Solarium\Core\Client\Endpoint as SolariumEndpoint;

/**
 * Custom Endpoint class for Solarium.
 *
 * @package Drupal\search_api_pantheon
 */
class Endpoint extends SolariumEndpoint {

  use LoggerAwareTrait;

  /**
   * Options for putting together the endpoint urls.
   *
   * @var array
   */
  protected $options = [];

  /**
   * Class constructor.
   *
   * @param array $options
   *   Array of options for the endpoint. Currently,
   *   they are used by other functions of the endpoint.
   */
  public function __construct(array $options = []) {
    if (!$options) {
      $options = [
        'scheme' => getenv('PANTHEON_INDEX_SCHEME') ?? 'https',
        'host' => getenv('PANTHEON_INDEX_HOST') ?? 'solr8',
        'port' => getenv('PANTHEON_INDEX_PORT') ?? 8983,
        'path' => isset($_SERVER['PANTHEON_INDEX_PATH']) ? getenv('PANTHEON_INDEX_PATH') : '/',
        'core' => Cores::getMyCoreName(),
        'collection' => NULL,
        'leader' => FALSE,
      ];
    }

    parent::__construct($options);
  }

  /**
   * Get the V1 base url for all requests.
   *
   * @return string
   *   Get the base URI for the Endpoint plus the core extension.
   *
   * @throws \Solarium\Exception\UnexpectedValueException
   */
  public function getCoreBaseUri(): string {
    return Cores::getBaseCoreUri();
  }

  /**
   * Get the base url for all V1 API requests.
   *
   * @return string
   *   Get the base URI for the endpoint. At pantheon the base uri is
   *   unaccessible and the core URI should always be used.
   *
   * @throws \Solarium\Exception\UnexpectedValueException
   */
  public function getBaseUri(): string {
    return Cores::getBaseCoreUri();
  }

  /**
   * Get the base url for all V1 API requests.
   *
   * @return string
   *   Base v1 URi for the endpoint.
   *
   * @throws \Solarium\Exception\UnexpectedValueException
   */
  public function getV1BaseUri(): string {
    return Cores::getBaseCoreUri();
  }

  /**
   * Get the base url for all V2 API requests.
   *
   * @throws \Solarium\Exception\UnexpectedValueException
   *
   * @return string
   *   V2 base URI for the endpoint.
   */
  public function getV2BaseUri(): string {
    return $this->getCoreBaseUri() . '/api/';
  }

  /**
   * Get the server uri, required for non core/collection specific requests.
   *
   * @return string
   *   Base URI for the endpoint.
   */
  public function getServerUri(): string {
    return Cores::getBaseUri();
  }

}
