<?php

declare(strict_types=1);

namespace Drupal\search_api_pantheon\EventSubscriber;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\search_api\Event\ItemsIndexedEvent;
use Drupal\search_api\Event\SearchApiEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Re-invalidates Search API cache tags after Solr's commit window.
 *
 * Ensures listing pages reflect newly published content without waiting
 * for a manual cache clear. Enabled by default.
 *
 * @code
 * # Disable:
 * drush config:set search_api_pantheon.settings solr_commit_reinvalidation 0
 * # Re-enable:
 * drush config:set search_api_pantheon.settings solr_commit_reinvalidation 1
 * @endcode
 */
class SolrCommitAwareCacheInvalidator implements CacheTagsInvalidatorInterface, EventSubscriberInterface {

  /**
   * Seconds to wait after invalidation before re-invalidating.
   *
   * Solr autoSoftCommit is 5s on Pantheon. We add 1s buffer to ensure
   * Solr has committed before the re-invalidation fires.
   */
  const SOLR_COMMIT_BUFFER_SECONDS = 6;

  /**
   * State key for pending re-invalidation timestamps.
   */
  const STATE_KEY = 'search_api_pantheon.reinvalidate_due';

  /**
   * Guard flag to prevent infinite recursion during re-invalidation.
   *
   * When onRequest() calls Cache::invalidateTags(), that triggers all
   * registered CacheTagsInvalidatorInterface implementations — including
   * this one. This flag tells invalidateTags() to skip scheduling another
   * re-invalidation for tags we are currently re-invalidating.
   *
   * @var bool
   */
  private bool $isReInvalidating = FALSE;

  /**
   * Cached enabled state to avoid repeated config reads within a request.
   *
   * @var bool|null
   */
  private ?bool $enabled = NULL;

  /**
   * Constructs a SolrCommitAwareCacheInvalidator.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service for persisting pending re-invalidation timestamps.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory for reading the enabled flag.
   */
  public function __construct(
    protected StateInterface $state,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns whether re-invalidation is enabled.
   *
   * Caches the result for the lifetime of this service instance (one request)
   * to avoid repeated config reads.
   *
   * @return bool
   *   TRUE if solr_commit_reinvalidation is enabled.
   */
  protected function isEnabled(): bool {
    if ($this->enabled === NULL) {
      $this->enabled = (bool) $this->configFactory
        ->get('search_api_pantheon.settings')
        ->get('solr_commit_reinvalidation');
    }
    return $this->enabled;
  }

  /**
   * Intercepts search_api_list cache tag invalidations.
   *
   * Called by Drupal's cache tag invalidation chain whenever any code calls
   * Cache::invalidateTags(). We only care about search_api_list:* tags —
   * these indicate a search index's content has changed and listing pages
   * should be refreshed.
   *
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    if (!$this->isEnabled() || $this->isReInvalidating) {
      return;
    }
    $this->scheduleReInvalidation($tags);
  }

  /**
   * Handles the ITEMS_INDEXED event from Search API.
   *
   * This serves as a belt-and-suspenders backup to the CacheTagsInvalidator
   * approach. If for any reason the search_api_list:* cache tag isn't
   * invalidated during indexing, this event still schedules re-invalidation.
   *
   * @param \Drupal\search_api\Event\ItemsIndexedEvent $event
   *   The items indexed event.
   */
  public function onItemsIndexed(ItemsIndexedEvent $event): void {
    if (!$this->isEnabled()) {
      return;
    }
    $index_id = $event->getIndex()->id();
    $this->scheduleReInvalidation(["search_api_list:$index_id"]);
  }

  /**
   * Re-invalidates search cache tags after the Solr commit window.
   *
   * Checked on every main web request. If any pending re-invalidation
   * timestamps have elapsed, fires Cache::invalidateTags() for those
   * search indexes. This causes:
   * 1. Drupal's internal page cache to drop the stale entry.
   * 2. PAPC's CacheTagsInvalidator to send a CDN purge.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The kernel request event.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$this->isEnabled() || !$event->isMainRequest()) {
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

    // Clear due entries BEFORE invalidating to prevent re-trigger.
    $remaining = array_diff_key($pending, $due);
    empty($remaining)
      ? $this->state->delete(self::STATE_KEY)
      : $this->state->set(self::STATE_KEY, $remaining);

    // Set the guard flag so our own invalidateTags() skips these.
    $this->isReInvalidating = TRUE;
    $this->reInvalidateTags(array_keys($due));
    $this->isReInvalidating = FALSE;
  }

  /**
   * Fires cache tag invalidation for the given tags.
   *
   * Extracted so unit tests can override without hitting Drupal's static
   * Cache::invalidateTags() (which requires a booted container).
   *
   * @param array $tags
   *   Cache tags to invalidate.
   */
  protected function reInvalidateTags(array $tags): void {
    Cache::invalidateTags($tags);
  }

  /**
   * Filters for search_api_list tags and schedules re-invalidation.
   *
   * @param array $tags
   *   Cache tags being invalidated.
   */
  protected function scheduleReInvalidation(array $tags): void {
    $list_tags = array_filter($tags, fn($tag) => str_starts_with($tag, 'search_api_list:'));
    if (empty($list_tags)) {
      return;
    }

    $pending = $this->state->get(self::STATE_KEY, []);
    $due_at = time() + self::SOLR_COMMIT_BUFFER_SECONDS;
    foreach ($list_tags as $tag) {
      // Always update to latest timestamp — if content is edited rapidly,
      // each edit pushes the re-invalidation forward so it fires after
      // the LAST edit's Solr commit.
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
