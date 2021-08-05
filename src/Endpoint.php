<?php

namespace Drupal\search_api_pantheon;

use Drupal\search_api_pantheon\Utility\Cores;
use Psr\Log\LoggerAwareTrait;
use Solarium\Exception\UnexpectedValueException;

/**
 * Class Endpoint
 *
 * @package Drupal\search_api_pantheon
 */
class Endpoint extends \Solarium\Core\Client\Endpoint
{
    use LoggerAwareTrait;

    protected $options = [];

    public function __construct($options = [])
    {
        $this->logger = \Drupal::logger(__CLASS__);
        $this->logger->debug(__METHOD__);
        $this->options = array_merge([
          'scheme' => getenv('PANTHEON_INDEX_SCHEME') ?? 'https',
          'host' => getenv('PANTHEON_INDEX_HOST') ?? 'solr8',
          'port' => getenv('PANTHEON_INDEX_PORT') ?? 8983,
          'path' => isset($_SERVER['PANTHEON_INDEX_PATH']) ? getenv('PANTHEON_INDEX_PATH') : '/',
          'core' => Cores::getMyCoreName(),
          'collection' => null,
          'leader' => false,
        ], $options);
    }


  /**
   * Get the V1 base url for all requests.
   *
   * Based on host, path, port and core options.
   *
   * @throws UnexpectedValueException
   *
   * @return string
   */
    public function getCoreBaseUri(): string
    {
        $this->logger->debug(__METHOD__ . ' => ' . Cores::getBaseCoreUri());
        return Cores::getBaseCoreUri();
    }

  /**
   * Get the base url for all V1 API requests.
   *
   * @throws UnexpectedValueException
   *
   * @return string
   */
    public function getBaseUri(): string
    {
        $this->logger->debug(__METHOD__ . ' => ' . Cores::getBaseCoreUri());
        return Cores::getBaseCoreUri();
    }

  /**
   * Get the base url for all V1 API requests.
   *
   * @throws UnexpectedValueException
   *
   * @return string
   */
    public function getV1BaseUri(): string
    {
        $this->logger->debug(__METHOD__ . ' => ' . Cores::getBaseCoreUri());
        return Cores::getBaseCoreUri();
    }

  /**
   * Get the base url for all V2 API requests.
   *
   * @throws UnexpectedValueException
   *
   * @return string
   */
    public function getV2BaseUri(): string
    {
        $this->logger->debug(__METHOD__ . ' => ' . Cores::getBaseCoreUri());
        return $this->getCoreBaseUri() . '/api/';
    }

  /**
   * Get the server uri, required for non core/collection specific requests.
   *
   * @return string
   */
    public function getServerUri(): string
    {
        $this->logger->debug(__METHOD__ . ' => ' . Cores::getBaseCoreUri());
        return Cores::getBaseUri();
    }
}
