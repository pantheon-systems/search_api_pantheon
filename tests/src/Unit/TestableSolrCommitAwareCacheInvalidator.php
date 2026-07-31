<?php

declare(strict_types=1);

namespace Drupal\Tests\search_api_pantheon\Unit;

use Drupal\search_api_pantheon\EventSubscriber\SolrCommitAwareCacheInvalidator;

/**
 * Testable subclass that captures re-invalidation calls.
 */
class TestableSolrCommitAwareCacheInvalidator extends SolrCommitAwareCacheInvalidator {

  /**
   * Tags that were re-invalidated.
   *
   * @var array
   */
  public array $reInvalidatedTags = [];

  /**
   * {@inheritdoc}
   */
  protected function reInvalidateTags(array $tags): void {
    $this->reInvalidatedTags = array_merge($this->reInvalidatedTags, $tags);
  }

}
