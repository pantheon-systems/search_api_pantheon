<?php

/**
 * @file
 * Override a method because pinging was failing with the parent class.
 */

namespace Drupal\search_api_pantheon\search_api_solr;
use Drupal\search_api_solr\Solr\SolrHelper;

/**
 * Contains helper methods for working with Solr.
 */
class PantheonSolrHelper extends SolrHelper {

  /**
   * {@inheritdoc}
   */
  public function pingServer() {
    // The path used in the parent class, admin/info/system, fails.
    // I don't know why.
    // @todo, remove this entire class: https://www.drupal.org/node/2761121
    return $this->doPing(['handler' => 'admin/system'], 'server');
  }
}
