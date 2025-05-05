<?php

declare(strict_types = 1);

namespace Drupal\search_api_pantheon\EventSubscriber;

use Drupal\search_api_solr\Event\PostConfigFilesGenerationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @issue BUGS-4278
 *
 * Implements hook_search_api_solr_config_files_alter().
 *
 * Remember to post schema after any changes to the XML files here.
 */
final class SearchApiPantheonSolrConfigFilesAlter implements EventSubscriberInterface {

  /**
   * PostConfigFilesGenerationEvent event handler.
   *
   * @param \Drupal\search_api_solr\Event\PostConfigFilesGenerationEvent $event
   */
  public function onPostConfigFilesGenerationEvent(PostConfigFilesGenerationEvent $event): void {
    $files = $event->getConfigFiles();

    // Append at the end of the file.
    $solrcore_properties = explode(PHP_EOL, $files['solrcore.properties']);
    // Remove the solr.install.dir if it exists
    foreach ($solrcore_properties as $key => $property) {
      if (substr($property, 0, 16) == 'solr.install.dir') {
        unset($solrcore_properties[$key]);
      }
    }
    // Remove the solrcore.properties file from the upload
    // This file is causing undue issues with core restarts
    unset($files['solrcore.properties']);

    $install_dir = isset($_ENV['PANTHEON_SOLR_INSTALL_DIR']) ? $_ENV['PANTHEON_SOLR_INSTALL_DIR'] : "/opt/solr/";

    $files['solrconfig.xml'] = str_replace("solr.install.dir:../../../..",
      "solr.install.dir:" . $install_dir, $files['solrconfig.xml']);

    $event->setConfigFiles($files);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      'Drupal\search_api_solr\Event\PostConfigFilesGenerationEvent' => ['onPostConfigFilesGenerationEvent'],
    ];
  }

}
