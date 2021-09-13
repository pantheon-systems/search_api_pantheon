<?php

namespace Drupal\search_api_pantheon\Services;

use Solarium\Core\Client\Endpoint as SolariumEndpoint;

/**
 * Custom Endpoint class for Solarium.
 *
 * This class assembles environment variables into URL's for
 * the Pantheon Solr8 implementation.
 *
 * URL Pattern for SOLR 8 QUERIES:
 *  "$SCHEME://$HOST:$PORT/$PATH/$CORE"
 *
 * URL Pattern for SOLR 8 SCHEMA UPLOADS:
 *  "$SCHEME://$HOST:$PORT/$PATH/$SCHEMA"
 *
 * @package Drupal\search_api_pantheon
 */
class Endpoint extends SolariumEndpoint {


  public static $DEFAULT_NAME = 'pantheon_solr8';

  /**
   * Default name for Endpoint.
   *
   * @var string
   */
  protected $schema;
  /**
   * Options for putting together the endpoint urls.
   *
   * @var array
   */
  protected $options = [];

  /**
   * Class constructor.
   *
   * @param array $options
   *   Array of options for the endpoint. Currently,
   *   they are used by other functions of the endpoint.
   */
  public function __construct(array $options = []) {
    if (!$options) {
      $options = [
            'scheme' => getenv('PANTHEON_INDEX_SCHEME'),
            'host' => getenv('PANTHEON_INDEX_HOST'),
            'port' => getenv('PANTHEON_INDEX_PORT'),
            'path' => getenv('PANTHEON_INDEX_PATH'),
            'core' => getenv('PANTHEON_INDEX_CORE'),
            'schema' => getenv('PANTHEON_INDEX_SCHEMA'),
            'collection' => NULL,
            'leader' => FALSE,
        ];
    }
    parent::__construct($options);
  }

  /**
   * Get the V1 base url for all requests.
   *
   * @return string
   *   Get the base URI for the Endpoint plus plath and the core vars.
   *
   * @throws \Solarium\Exception\UnexpectedValueException
   */
  public function getCoreBaseUri(): string {
    return vsprintf(
          '%s%s%s/',
          [
              $this->getBaseUri(),
              $this->getPath(),
              $this->getCore(),
          ]
      );
  }

  /**
   * Get the base URI from environment variables.
   *
   * @return string
   *   Base URL with scheme and port.
   */
  public function getBaseUri(): string {
    return vsprintf(
          '%s://%s:%d/',
          [
              $this->getScheme(),
              $this->getHost(),
              $this->getPort(),
          ]
      );
  }

  /**
   * Get the base url for all V1 API requests.
   *
   * @return string
   *   Base v1 URi for the endpoint.
   *
   * @throws \Solarium\Exception\UnexpectedValueException
   */
  public function getV1BaseUri(): string {
    return isset($_ENV['PANTHEON_ENVIRONMENT'])
      ? 'v1' : '';
  }

  /**
   * Get the base url for all V2 API requests.
   *
   * @return string
   *   V2 base URI for the endpoint.
   *
   * @throws \Solarium\Exception\UnexpectedValueException
   */
  public function getV2BaseUri(): string {
    return $this->getBaseUri() . '/api/';
  }

  /**
   * Get the server uri, required for non core/collection specific requests.
   *
   * @return string
   *   Base URI for the endpoint.
   */
  public function getServerUri(): string {
    return $this->getBaseUri();
  }

  /**
   * Get the current site name.
   *
   * Get My site name. 'site' is provided for
   * compatibility with development environments.
   *
   * @return string
   *   Site id.
   */
  public function getMySitename(): string {
    return isset($_ENV['PANTHEON_ENVIRONMENT'])
            ? getenv('PANTHEON_SITE')
            : getenv('PROJECT_NAME');
  }

  /**
   * Get the current environment name.
   *
   * Get My environment name. 'env' is provided for
   * compatibility with development environments.
   *
   * @return string
   *   Environment Name.
   */
  public function getMyEnvironment(): string {
    return isset($_ENV['PANTHEON_ENVIRONMENT'])
            ? getenv('PANTHEON_ENVIRONMENT')
            : getenv('ENV');
  }

  /**
   * Get URL in pantheon environment to upload schema files.
   *
   * @return string
   *   URL of envrionment.
   */
  public function getSchemaUploadUri(): string {
    return vsprintf(
          '%s://%s:%d/%s%s',
          [
              $this->getScheme(),
              $this->getHost(),
              $this->getPort(),
              $this->getPath(),
              $this->getSchema(),
          ]
      );
  }

  /**
   * Get the path for Schema Uploads.
   *
   * @return string
   *   The path for schema uploads.
   */
  public function getSchema(): string {
    return $this->options['schema'];
  }

  /**
   * Set the path for Schema Uploads.
   *
   * @param string $schema
   *   The path for schema uploads.
   */
  public function setSchema(string $schema): void {
    $this->options['schema'] = $schema;
  }

  /**
   * Get the name of this endpoint.
   *
   * @return string|null
   *   Always use the default name.
   */
  public function getKey(): ?string {
    return static::$DEFAULT_NAME;
  }

}
