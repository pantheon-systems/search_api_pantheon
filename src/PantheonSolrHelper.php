<?php

namespace Drupal\search_api_pantheon;
use Drupal\search_api_solr\Solr\SolrHelper;


/**
 * Contains helper methods for working with Solr.
 */
class PantheonSolrHelper extends SolrHelper {

  /**
   * Pings the Solr server to tell whether it can be accessed.
   *
   * @return mixed
   *   The latency in milliseconds if the core can be accessed,
   *   otherwise FALSE.
   */
  public function pingServer() {
    return $this->doPing(['handler' => 'admin/system'], 'server'); // @todo remove this change https://www.drupal.org/node/2761121
  }



}
