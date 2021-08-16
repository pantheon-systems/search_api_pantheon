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
   * Returns the endpoint's scheme.
   *
   * @return string
   *   The scheme.
   */
  public static function getSolrScheme(): string {
    return getenv('PANTHEON_INDEX_SCHEME') ?? 'https';
  }

}
