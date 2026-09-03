<?php

declare(strict_types=1);

namespace Drupal\Tests\search_api_pantheon\Unit;

use Drupal\search_api_pantheon\EventSubscriber\SolrCommitAwareCacheInvalidator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests request handling behavior of SolrCommitAwareCacheInvalidator.
 *
 * @coversDefaultClass \Drupal\search_api_pantheon\EventSubscriber\SolrCommitAwareCacheInvalidator
 * @group search_api_pantheon
 */
class SolrCommitRequestTest extends SolrCommitTestBase {

  /**
   * Tests that onRequest does not fire before the buffer period.
   *
   * @covers ::onRequest
   */
  public function testOnRequestDoesNotFireEarly(): void {
    $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] = [
      'search_api_list:primary' => time() + 6,
    ];

    $this->invalidator->onRequest($this->createRequestEvent());

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertArrayHasKey('search_api_list:primary', $pending);
    $this->assertEmpty($this->invalidator->reInvalidatedTags);
  }

  /**
   * Tests that onRequest fires and clears due entries.
   *
   * @covers ::onRequest
   */
  public function testOnRequestFiresWhenDue(): void {
    $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] = [
      'search_api_list:primary' => time() - 1,
    ];

    $this->invalidator->onRequest($this->createRequestEvent());

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertArrayNotHasKey('search_api_list:primary', $pending);
    $this->assertContains('search_api_list:primary', $this->invalidator->reInvalidatedTags);
  }

  /**
   * Tests that onRequest preserves not-yet-due entries.
   *
   * @covers ::onRequest
   */
  public function testOnRequestPreservesNotDueEntries(): void {
    $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] = [
      'search_api_list:primary' => time() - 1,
      'search_api_list:secondary' => time() + 60,
    ];

    $this->invalidator->onRequest($this->createRequestEvent());

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertArrayNotHasKey('search_api_list:primary', $pending);
    $this->assertArrayHasKey('search_api_list:secondary', $pending);
    $this->assertContains('search_api_list:primary', $this->invalidator->reInvalidatedTags);
    $this->assertNotContains('search_api_list:secondary', $this->invalidator->reInvalidatedTags);
  }

  /**
   * Tests that sub-requests are ignored.
   *
   * @covers ::onRequest
   */
  public function testSubRequestIgnored(): void {
    $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] = [
      'search_api_list:primary' => time() - 1,
    ];

    $kernel = $this->createMock(HttpKernelInterface::class);
    $event = new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST);
    $this->invalidator->onRequest($event);

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertArrayHasKey('search_api_list:primary', $pending);
    $this->assertEmpty($this->invalidator->reInvalidatedTags);
  }

  /**
   * Tests that onRequest with empty state does nothing.
   *
   * @covers ::onRequest
   */
  public function testOnRequestEmptyStateNoop(): void {
    $this->invalidator->onRequest($this->createRequestEvent());
    $this->assertArrayNotHasKey(SolrCommitAwareCacheInvalidator::STATE_KEY, $this->stateStorage);
    $this->assertEmpty($this->invalidator->reInvalidatedTags);
  }

  /**
   * Tests event subscriber registration.
   *
   * @covers ::getSubscribedEvents
   *
   * @SuppressWarnings(PHPMD.StaticAccess)
   */
  public function testSubscribedEvents(): void {
    $events = SolrCommitAwareCacheInvalidator::getSubscribedEvents();
    $this->assertArrayHasKey('search_api.items_indexed', $events);
    $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
  }

  /**
   * Tests that re-invalidation during onRequest doesn't re-schedule.
   *
   * @covers ::invalidateTags
   * @covers ::onRequest
   */
  public function testReInvalidationDoesNotReschedule(): void {
    $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] = [
      'search_api_list:primary' => time() - 1,
    ];

    $this->invalidator->onRequest($this->createRequestEvent());

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertEmpty($pending);
  }

  /**
   * Tests that disabling the config flag stops onRequest processing.
   *
   * @covers ::onRequest
   * @covers ::isEnabled
   */
  public function testDisabledConfigSkipsOnRequest(): void {
    $invalidator = $this->createInvalidator(FALSE);

    $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] = [
      'search_api_list:primary' => time() - 1,
    ];

    $invalidator->onRequest($this->createRequestEvent());

    // State should NOT be cleared — the handler didn't run.
    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertArrayHasKey('search_api_list:primary', $pending);
    $this->assertEmpty($invalidator->reInvalidatedTags);
  }

}
