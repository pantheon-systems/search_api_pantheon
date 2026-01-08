<?php

namespace Drupal\search_api_pantheon\Commands;

/**
 * Drush Search Api Pantheon Schema Commands.
 */
class Reload extends PantheonCommandBase {

  /**
   * Search_api_pantheon:reloadSchema.
   *
   * @usage search-api-pantheon:reloadSchema
   *  Reload the latest schema
   *
   * @command search-api-pantheon:reloadSchema
   */
  public function reloadSchema() {
    try {
      $this->getPantheonSolrConnector()->reloadCore();
    }
    catch (\Exception $e) {
      $this->logger->error((string) $e);
    }
  }

}
