<?php

declare(strict_types=1);

namespace Drupal\search_api_pantheon_cache\EventSubscriber;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\State\StateInterface;
use Drupal\search_api\Event\ItemsIndexedEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Re-invalidates Search API cache tags after Solr's commit window.
 *
 * Solr's autoSoftCommit (5s on Pantheon) creates a race condition: Drupal
 * invalidates cache tags at entity-save time, but Solr hasn't committed yet.
 * A visitor in that 0-5s window caches stale Solr results. This service
 * schedules a second invalidation after the commit window to clear stale
 * cached pages.
 *
 * Enable by installing the search_api_pantheon_cache submodule:
 * @code
 * drush en search_api_pantheon_cache
 * @endcode
 */
class SolrCommitAwareCacheInvalidator implements CacheTagsInvalidatorInterface, EventSubscriberInterface {

  /**
   * Seconds to wait after invalidation before re-invalidating.
   *
   * Solr autoSoftCommit is 5s on Pantheon. 1s buffer ensures Solr has
   * committed before the re-invalidation fires.
   */
  const SOLR_COMMIT_BUFFER_SECONDS = 6;

  const STATE_KEY = 'search_api_pantheon.reinvalidate_due';

  /**
   * Guard flag to prevent infinite recursion during re-invalidation.
   */
  private bool $isReInvalidating = FALSE;

  public function __construct(
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    if ($this->isReInvalidating) {
      return;
    }
    $this->scheduleReInvalidation($tags);
  }

  /**
   * Handles the ITEMS_INDEXED event from Search API.
   */
  public function onItemsIndexed(ItemsIndexedEvent $event): void {
    $index_id = $event->getIndex()->id();
    $this->scheduleReInvalidation(["search_api_list:$index_id"]);
  }

  /**
   * Re-invalidates search cache tags after the Solr commit window.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $pending = $this->state->get(self::STATE_KEY, []);
    if (empty($pending)) {
      return;
    }

    $now = time();
    $due = array_filter($pending, fn($time) => $now >= $time);
    if (empty($due)) {
      return;
    }

    $remaining = array_diff_key($pending, $due);
    empty($remaining)
      ? $this->state->delete(self::STATE_KEY)
      : $this->state->set(self::STATE_KEY, $remaining);

    $this->isReInvalidating = TRUE;
    $this->reInvalidateTags(array_keys($due));
    $this->isReInvalidating = FALSE;
  }

  /**
   * Fires cache tag invalidation for the given tags.
   */
  protected function reInvalidateTags(array $tags): void {
    Cache::invalidateTags($tags);
  }

  /**
   * Filters for search_api_list tags and schedules re-invalidation.
   */
  protected function scheduleReInvalidation(array $tags): void {
    $list_tags = array_filter($tags, fn($tag) => str_starts_with($tag, 'search_api_list:'));
    if (empty($list_tags)) {
      return;
    }

    $pending = $this->state->get(self::STATE_KEY, []);
    $due_at = time() + self::SOLR_COMMIT_BUFFER_SECONDS;
    foreach ($list_tags as $tag) {
      $pending[$tag] = $due_at;
    }
    $this->state->set(self::STATE_KEY, $pending);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      SearchApiEvents::ITEMS_INDEXED => 'onItemsIndexed',
      KernelEvents::REQUEST => ['onRequest', 100],
    ];
  }

}
