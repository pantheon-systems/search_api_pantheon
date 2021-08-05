<?php

namespace Drupal\search_api_pantheon\Utility;

/**
 * Generate Pantheon Core-names and URI values
 *
 * @package Drupal\search_api_pantheon\Utility
 */
class Cores
{
  /**
   * @return string
   */
    public static function getMyCoreName(): string
    {
        return isset($_ENV['PANTHEON_ENVIRONMENT'])
        ? sprintf(
            'v1/site/%s/environment/%s/backend',
            getenv('PANTHEON_SITE'),
            static::getMyEnvironment()
        )
        : "solr/" . getenv('PROJECT_NAME');
    }

  /**
   * @return string
   */
    public static function getMyEnvironment(): string
    {
        return isset($_ENV['PANTHEON_ENVIRONMENT'])
        ? getenv('PANTHEON_ENVIRONMENT')
        : getenv('ENV');
    }

  /**
   * @return string
   */
    public static function getBaseCoreUri(): string
    {
        return vsprintf('%s/%s/', [static::getBaseUri(), static::getMyCoreName()]);
    }

  /**
   * @return string
   */
    public static function getBaseUri(): string
    {
        return sprintf(
            '%s://%s:%d',
            isset($_SERVER['PANTHEON_INDEX_SCHEME'])
            ? getenv('PANTHEON_INDEX_SCHEME')
            : 'https',
            getenv('PANTHEON_INDEX_HOST'),
            getenv('PANTHEON_INDEX_PORT')
        );
    }
}
