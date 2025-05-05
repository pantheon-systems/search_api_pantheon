<?php

namespace Drupal\search_api_pantheon\Commands;

use Drupal\search_api_pantheon\Services\SchemaPoster;
use Drush\Commands\DrushCommands;
use Symfony\Component\Finder\Finder;

/**
 * Drush Search Api Pantheon Schema Commands.
 */
class Schema extends DrushCommands {

  /**
   * Class constructor.
   *
   * @param \Drupal\search_api_pantheon\Services\SchemaPoster $schemaPoster
   *   Injected by Container.
   */
  public function __construct(protected SchemaPoster $schemaPoster) {
    parent::__construct();
  }

  /**
   * Search_api_pantheon:postSchema.
   *
   * @usage search-api-pantheon:postSchema [server_id] [path]
   *   Post the latest schema to the given Server.
   *   Default server ID = pantheon_solr8.
   *   Default path = empty (build files using the search_api_solr mechanism).
   *
   * @command search-api-pantheon:postSchema
   *
   * @param $server_id
   *   Server id to post schema for.
   * @param $path
   *   Path to schema files (Leave empty to use default schema).
   *
   * @aliases sapps
   */
  public function postSchema(?string $server_id = NULL, ?string $path = NULL): void {
    try {
      $files = [];
      if ($path) {
        if (!is_dir($path)) {
          throw new \Exception("Path '$path' is not a directory.");
        }
        $finder = new Finder();
        // Only work with direct children.
        $finder->depth('== 0');
        $finder->files()->in($path);
        if (!$finder->hasResults()) {
          throw new \Exception("Path '$path' does not contain any files.");
        }
        foreach ($finder as $file) {
          $files[$file->getfilename()] = $file->getContents();
        }
      }

      $this->schemaPoster->postSchema($server_id, $files);
    }
    catch (\Exception $e) {
      $this->logger->error((string) $e);
    }
  }

  /**
   * View a Schema File.
   *
   * @param string $filename
   *   Filename to retrieve.
   *
   * @command search-api-pantheon:view-schema
   * @aliases sapvs
   * @usage sapvs schema.xml
   * @usage search-api-pantheon:view-schema elevate.xml
   *
   * @throws \Exception
   * @throws \Psr\Http\Client\ClientExceptionInterface
   */
  public function viewSchema(string $filename = 'schema.xml'): void {
    $currentSchema = $this->schemaPoster->viewSchema($filename);
    $this->logger->notice($currentSchema);
  }

}
