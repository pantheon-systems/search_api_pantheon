<?php

namespace Drupal\search_api_pantheon\Solarium;

use Solarium\Core\Client\Adapter\Curl;

/**
 * Curl handler for to use the Pantheon cert.
 */
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
