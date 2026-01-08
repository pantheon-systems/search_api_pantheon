<?php

namespace Drupal\search_api_pantheon\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\search_api_pantheon\GetPantheonSolrServerTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush Search Api Pantheon Schema Commands.
 */
abstract class PantheonCommandBase extends DrushCommands {

  use GetPantheonSolrServerTrait;

  /**
   * Construct a Query command object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct();
    $this->storage = $entityTypeManager->getStorage('search_api_server');
  }

}
