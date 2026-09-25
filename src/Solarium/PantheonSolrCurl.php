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
    if (!empty($opts[CURLOPT_SSLCERT])) {
      $ssl_options[CURLOPT_SSLCERT] = $opts[CURLOPT_SSLCERT];
      // With a client cert, requests go to the search gateway directly rather
      // than through the appserver's HTTP/1.1 mTLS proxy. The gateway resets
      // HTTP/2 streams on schema uploads (curl error 92), so use HTTP/1.1.
      $ssl_options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
    }

    return $ssl_options;
  }

}
