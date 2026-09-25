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
   *   SSL verification and client certificate options.
   */
  protected static function getPantheonCurlOptions(): array {
    if (!function_exists('pantheon_curl_setup')) {
      return [];
    }
    $port = getenv('PANTHEON_INDEX_PORT');
    [, $opts] = pantheon_curl_setup('', NULL, $port, NULL);

    return static::curlOptionsFromPrepend($opts);
  }

  /**
   * Selects the prepend's curl options that apply to Solr requests.
   *
   * On appservers, Solr requests go through a local mTLS proxy that presents
   * the client certificate, and the prepend disables verification of that
   * proxy's certificate. In Unified Job Runner (UJR) jobs there is no local
   * proxy, so the prepend supplies the binding certificate instead.
   *
   * @param array $prepend_options
   *   Curl options returned by pantheon_curl_setup().
   *
   * @return array
   *   Curl options to set on Solr requests.
   */
  public static function curlOptionsFromPrepend(array $prepend_options): array {
    $options = [];
    if (isset($prepend_options[CURLOPT_SSL_VERIFYPEER])) {
      $options[CURLOPT_SSL_VERIFYPEER] = $prepend_options[CURLOPT_SSL_VERIFYPEER];
    }
    if (isset($prepend_options[CURLOPT_SSL_VERIFYHOST])) {
      $options[CURLOPT_SSL_VERIFYHOST] = $prepend_options[CURLOPT_SSL_VERIFYHOST];
    }

    // The prepend returns an empty path when no binding certificate exists.
    if (empty($prepend_options[CURLOPT_SSLCERT])) {
      return $options;
    }
    $options[CURLOPT_SSLCERT] = $prepend_options[CURLOPT_SSLCERT];

    // Connecting directly, curl negotiates HTTP/2, and the search gateway
    // resets HTTP/2 streams on schema uploads (curl error 92). Appserver
    // requests reach the gateway over HTTP/1.1 through the proxy.
    $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;

    return $options;
  }

}
