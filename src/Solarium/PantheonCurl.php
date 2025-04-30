<?php

/**
 * @file
 * Override Solarium so that more options can be set before executing curl.
 */

namespace Drupal\search_api_pantheon\Solarium;

use Solarium\Core\Client\Adapter\Curl;

class PantheonCurl extends Curl {

  public function __construct(protected string $cert, ?array $options = NULL) {
    parent::__construct($options);
  }

  /**
   * {@inheritdoc}
   */
  public function createHandle($request, $endpoint): \CurlHandle {
    $handler = parent::createHandle($request, $endpoint);
    curl_setopt($handler, CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($handler, CURLOPT_SSLCERT, $this->cert);
    return $handler;
  }
}
