<?php

namespace Drupal\search_api_pantheon\Utility;

use Drupal\search_api_pantheon\Endpoint;

/**
 * Generate Pantheon Core-names and URI values.
 *
 * @package Drupal\search_api_pantheon\Utility
 */
class Cores {

  /**
   * Get URL in pantheon environment to upload schema files.
   *
   * @return string
   *   The URL.
   */
  public static function getSchemaUploadUri(): string {
    return isset($_ENV['PANTHEON_ENVIRONMENT'])
      ? sprintf(
        'v1/site/%s/environment/%s/configs',
        getenv('PANTHEON_SITE'),
        static::getMyEnvironment())
      : 'solr/admin/config/' . self::getMyCoreName();
  }

  /**
   * Get Core Name.
   *
   * Core names in pantheon environment are specific to
   * both the site and environment.
   *
   * @return string
   *   Core name for Solr query URL's.
   */
  public static function getMyCoreName(): string {
    return isset($_ENV['PANTHEON_ENVIRONMENT'])
      ? sprintf(
          'v1/site/%s/environment/%s/backend',
          getenv('PANTHEON_SITE'),
          static::getMyEnvironment())
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
  public static function getMyEnvironment(): string {
    return isset($_ENV['PANTHEON_ENVIRONMENT'])
      ? getenv('PANTHEON_ENVIRONMENT')
      : getenv('ENV');
  }

  /**
   * Get the base URI plus core information.
   *
   * @return string
   *   URL for making Query Calls.
   */
  public static function getBaseCoreUri(): string {
    return vsprintf('%s/solr/%s/',
      [static::getBaseUri(), static::getMyCoreName()]);
  }

  /**
   * Get the base URI from environment variables.
   *
   * FYI: We only use PANTHEON_INDEX_SCHEME for development environments
   * where https is disabled. Default is https.  Only HOST and PORT are
   * available in the pantheon environments.
   *
   * @return string
   *   Base URL with scheme and port.
   */
  public static function getBaseUri(): string {
    return sprintf(
      '%s://%s:%d',
      Endpoint::getSolrScheme(),
      Endpoint::getSolrHost(),
      Endpoint::getSolrPort()
    );
  }

}
