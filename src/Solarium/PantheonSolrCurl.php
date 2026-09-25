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
   * TLS options passed through from the Pantheon prepend file.
   *
   * On appservers the prepend disables verification for the local mTLS
   * proxy. In job runtimes (UJR) there is no local proxy, so the prepend
   * instead supplies the binding client certificate.
   */
  protected const PASSTHROUGH_OPTIONS = [
    CURLOPT_SSL_VERIFYPEER,
    CURLOPT_SSL_VERIFYHOST,
    CURLOPT_SSLCERT,
    CURLOPT_SSLKEY,
    CURLOPT_CAINFO,
  ];

  /**
   * Get TLS options from Pantheon infrastructure.
   *
   * @return array
   *   TLS verification and client certificate options.
   */
  protected static function getPantheonCurlOptions(): array {
    if (!function_exists('pantheon_curl_setup')) {
      return [];
    }
    $port = getenv('PANTHEON_INDEX_PORT');
    [, $opts] = pantheon_curl_setup('', NULL, $port, NULL);

    $ssl_options = [];
    foreach (static::PASSTHROUGH_OPTIONS as $option) {
      if (!isset($opts[$option])) {
        continue;
      }
      // The prepend returns an empty path when no binding cert is found.
      if ($opts[$option] === '') {
        continue;
      }
      $ssl_options[$option] = $opts[$option];
    }

    return $ssl_options;
  }

}
