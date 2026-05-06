<?php

namespace Drupal\search_api_pantheon\Solarium;

use Solarium\Core\Client\Adapter\Curl;

/**
 * Custom Curl adapter for Pantheon Solr connectivity.
 * This adapter is only instantiated on Pantheon environments.
 */
class PantheonSolrCurl extends Curl {

  /**
   * {@inheritdoc}
   */
  public function createHandle($request, $endpoint): \CurlHandle {
    $handler = parent::createHandle($request, $endpoint);

    // Get SSL settings from Pantheon prepend file.
    $curlOpts = static::getPantheonCurlOptions();
    foreach ($curlOpts as $option => $value) {
      curl_setopt($handler, $option, $value);
    }
    return $handler;
  }

  /**
   * Get SSL options from Pantheon infrastructure.
   *
   * @return array
   *   SSL verification options.
   */
  protected static function getPantheonCurlOptions(): array {
    if (!function_exists('pantheon_curl_setup')) {
      return [];
    }
    $port = getenv('PANTHEON_INDEX_PORT');
    [, $opts] = pantheon_curl_setup('', NULL, $port, NULL);

    // Return SSL verification options.
    $ssl_options = [];
    if (isset($opts[CURLOPT_SSL_VERIFYPEER])) {
      $ssl_options[CURLOPT_SSL_VERIFYPEER] = $opts[CURLOPT_SSL_VERIFYPEER];
    }
    if (isset($opts[CURLOPT_SSL_VERIFYHOST])) {
      $ssl_options[CURLOPT_SSL_VERIFYHOST] = $opts[CURLOPT_SSL_VERIFYHOST];
    }

    return $ssl_options;
  }

}
