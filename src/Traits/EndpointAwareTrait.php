<?php

namespace Drupal\search_api_pantheon\Traits;

use Drupal\search_api_pantheon\Services\Endpoint;

/**
 * Endpoint Aware Trait
 */
trait EndpointAwareTrait {

  /**
   * @var
   */
  protected $endpoint;

  /**
   * @return \Solarium\Core\Client\Endpoint
   */
  public function getEndpoint(): Endpoint {
    return $this->endpoint;
  }

  /**
   * @param \Solarium\Core\Client\Endpoint $endpoint
   */
  public function setEndpoint(Endpoint $endpoint){
    $this->endpoint = $endpoint;
  }


}
