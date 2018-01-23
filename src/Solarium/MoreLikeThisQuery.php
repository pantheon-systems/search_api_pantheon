<?php

/**
 * @file
 * Override MoreLikeThis for Solr 3 compatiblity.
 */

namespace Drupal\search_api_pantheon\Solarium;
use Solarium\Core\Client\Adapter\Curl;
use Solarium\QueryType\MoreLikeThis\Query;

class MoreLikeThisQuery extends Query {

  /**
   * {@inheritdoc}
   */
  public function __construct($options = NULL) {
    parent::__construct($options);
    // Force the handler to select because Panthon uses Solr 3
    // which did have a separate handler for mlt (I think?)
    $this->options['handler'] = 'select';
  }

  /**
   * {@inheritdoc}
   */
  public function setQuery($query, $bind = NULL) {
    $this->addParam("qt", "mlt");
    return parent::setQuery($query, $bind);
  }
}
