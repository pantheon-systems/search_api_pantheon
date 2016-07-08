<?php

namespace Drupal\search_api_pantheon;
use Solarium\Core\Client\Adapter\Curl;

class PantheonCurl extends Curl {

  /**
   * {@inheritdoc}
   */
  public function createHandle($request, $endpoint) {
    $handler = parent::createHandle($request, $endpoint);
    curl_setopt($handler, CURLOPT_SSL_VERIFYPEER, false);
    $client_cert = '../certs/binding.pem';
    curl_setopt($handler, CURLOPT_SSLCERT, $client_cert);
    return $handler;
  }
}
