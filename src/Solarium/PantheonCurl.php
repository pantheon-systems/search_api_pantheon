<?php

namespace Drupal\search_api_pantheon\Solarium;

use Solarium\Core\Client\Adapter\Curl;

/**
 * Curl handler for Pantheon mtlsproxy with self-signed certificates.
 */
class PantheonCurl extends Curl {

  /**
   * {@inheritdoc}
   */
  public function createHandle($request, $endpoint): \CurlHandle {
    $handler = parent::createHandle($request, $endpoint);
    // Disable SSL peer verification for mtlsproxy's self-signed certificates.
    curl_setopt($handler, CURLOPT_SSL_VERIFYPEER, FALSE);
    return $handler;
  }

}
