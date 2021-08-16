<?php

namespace Drupal\search_api_pantheon;

use Drupal\search_api_pantheon\Utility\Cores;
use Solarium\Core\Client\Endpoint as SolariumEndpoint;

/**
 * Custom Endpoint class for Solarium.
 *
 * @package Drupal\search_api_pantheon
 */
class Endpoint extends SolariumEndpoint {

  /**
   * Class constructor.
   *
   * @param array $options
   *   Array of options for the endpoint. Currently,
   *   they are used by other functions of the endpoint.
   */
  public function __construct(array $options = []) {
    $options = array_merge([
      'scheme' => self::getSolrScheme(),
      'host' => self::getSolrHost(),
      'port' => self::getSolrPort(),
      'path' => self::getSolrPath(),
      'core' => self::getSolrCore(),
      'collection' => NULL,
      'leader' => FALSE,
    ], $options);

    parent::__construct($options);
  }

  /**
   * Returns the endpoint's scheme.
   *
   * @return string
   *   The scheme.
   */
  public static function getSolrScheme(): string {
    return getenv('PANTHEON_INDEX_SCHEME') ?? 'https';
  }

  /**
   * Returns the endpoint's host.
   *
   * @return string
   *   The host.
   */
  public static function getSolrHost(): string {
    return getenv('PANTHEON_INDEX_HOST') ?? 'solr8';
  }

  /**
   * Returns the endpoint's port.
   *
   * @return string
   *   The port.
   */
  public static function getSolrPort(): string {
    return getenv('PANTHEON_INDEX_PORT') ?? 8983;
  }

  /**
   * Returns the endpoint's path.
   *
   * @return string
   *   The path.
   */
  public static function getSolrPath(): string {
    return getenv('PANTHEON_INDEX_PATH') ?? '/';
  }

  /**
   * Returns the endpoint's path.
   *
   * @return string
   *   The path.
   */
  public static function getSolrCore(): string {
    return Cores::getMyCoreName();
  }

}
