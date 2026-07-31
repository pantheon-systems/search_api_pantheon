<?php

declare(strict_types=1);

namespace Drupal\Tests\search_api_pantheon\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\State\StateInterface;
use Drupal\search_api\Event\ItemsIndexedEvent;
use Drupal\search_api\IndexInterface;
use Drupal\search_api_pantheon\EventSubscriber\SolrCommitAwareCacheInvalidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

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

/**
 * Tests the SolrCommitAwareCacheInvalidator.
 *
 * @coversDefaultClass \Drupal\search_api_pantheon\EventSubscriber\SolrCommitAwareCacheInvalidator
 * @group search_api_pantheon
 */
class SolrCommitAwareCacheInvalidatorTest extends TestCase {

  /**
   * In-memory state storage for testing.
   *
   * @var array
   */
  protected array $stateStorage = [];

  /**
   * The mock state service.
   *
   * @var \Drupal\Core\State\StateInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $state;

  /**
   * The invalidator under test.
   *
   * @var \Drupal\Tests\search_api_pantheon\Unit\TestableSolrCommitAwareCacheInvalidator
   */
  protected TestableSolrCommitAwareCacheInvalidator $invalidator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->stateStorage = [];
    $this->state = $this->createMock(StateInterface::class);

    $this->state->method('get')
      ->willReturnCallback(function (string $key, $default = NULL) {
        return $this->stateStorage[$key] ?? $default;
      });
    $this->state->method('set')
      ->willReturnCallback(function (string $key, $value) {
        $this->stateStorage[$key] = $value;
      });
    $this->state->method('delete')
      ->willReturnCallback(function (string $key) {
        unset($this->stateStorage[$key]);
      });

    $this->invalidator = $this->createInvalidator(TRUE);
  }

  /**
   * Creates an invalidator with the given enabled/disabled state.
   *
   * @param bool $enabled
   *   Whether solr_commit_reinvalidation is enabled.
   *
   * @return \Drupal\Tests\search_api_pantheon\Unit\TestableSolrCommitAwareCacheInvalidator
   *   The configured invalidator.
   */
  protected function createInvalidator(bool $enabled): TestableSolrCommitAwareCacheInvalidator {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->with('solr_commit_reinvalidation')
      ->willReturn($enabled);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('search_api_pantheon.settings')
      ->willReturn($config);

    return new TestableSolrCommitAwareCacheInvalidator($this->state, $configFactory);
  }

  /**
   * Tests that search_api_list tags schedule re-invalidation.
   *
   * @covers ::invalidateTags
   * @covers ::scheduleReInvalidation
   */
  public function testSearchApiListTagsScheduleReInvalidation(): void {
    $this->invalidator->invalidateTags([
      'node:5',
      'search_api_list:primary',
      'node_list',
    ]);

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertArrayHasKey('search_api_list:primary', $pending);
    $this->assertArrayNotHasKey('node:5', $pending);
    $this->assertArrayNotHasKey('node_list', $pending);
  }

  /**
   * Tests that non-search tags are ignored.
   *
   * @covers ::invalidateTags
   * @covers ::scheduleReInvalidation
   */
  public function testNonSearchTagsIgnored(): void {
    $this->invalidator->invalidateTags([
      'node:5',
      'node_list',
      'config:views.view.search',
    ]);

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertEmpty($pending);
  }

  /**
   * Tests that ITEMS_INDEXED event schedules re-invalidation.
   *
   * @covers ::onItemsIndexed
   */
  public function testItemsIndexedSchedulesReInvalidation(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn('primary');

    $event = new ItemsIndexedEvent($index, ['item_1', 'item_2']);
    $this->invalidator->onItemsIndexed($event);

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertArrayHasKey('search_api_list:primary', $pending);
  }

  /**
   * Tests that rapid edits push the re-invalidation timestamp forward.
   *
   * @covers ::scheduleReInvalidation
   */
  public function testRapidEditsUpdateTimestamp(): void {
    $this->invalidator->invalidateTags(['search_api_list:primary']);
    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY];
    $first_timestamp = $pending['search_api_list:primary'];

    $this->invalidator->invalidateTags(['search_api_list:primary']);
    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY];
    $second_timestamp = $pending['search_api_list:primary'];

    $this->assertGreaterThanOrEqual($first_timestamp, $second_timestamp);
  }

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
   */
  public function testSubscribedEvents(): void {
    $events = SolrCommitAwareCacheInvalidator::getSubscribedEvents();
    $this->assertArrayHasKey('search_api.items_indexed', $events);
    $this->assertArrayHasKey(\Symfony\Component\HttpKernel\KernelEvents::REQUEST, $events);
  }

  /**
   * Tests that multiple search indexes are handled independently.
   *
   * @covers ::scheduleReInvalidation
   */
  public function testMultipleIndexesIndependent(): void {
    $this->invalidator->invalidateTags([
      'search_api_list:primary',
      'search_api_list:products',
    ]);

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertArrayHasKey('search_api_list:primary', $pending);
    $this->assertArrayHasKey('search_api_list:products', $pending);
    $this->assertCount(2, $pending);
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
   * Tests that disabling the config flag stops all scheduling.
   *
   * @covers ::invalidateTags
   * @covers ::isEnabled
   */
  public function testDisabledConfigSkipsScheduling(): void {
    $invalidator = $this->createInvalidator(FALSE);

    $invalidator->invalidateTags(['search_api_list:primary']);

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

  /**
   * Tests that disabling the config flag stops ITEMS_INDEXED handling.
   *
   * @covers ::onItemsIndexed
   * @covers ::isEnabled
   */
  public function testDisabledConfigSkipsItemsIndexed(): void {
    $invalidator = $this->createInvalidator(FALSE);

    $index = $this->createMock(IndexInterface::class);
    $index->method('id')->willReturn('primary');

    $event = new ItemsIndexedEvent($index, ['item_1']);
    $invalidator->onItemsIndexed($event);

    $pending = $this->stateStorage[SolrCommitAwareCacheInvalidator::STATE_KEY] ?? [];
    $this->assertEmpty($pending);
  }

  /**
   * Creates a main request event for testing.
   *
   * @return \Symfony\Component\HttpKernel\Event\RequestEvent
   *   A request event wrapping a main request.
   */
  protected function createRequestEvent(): RequestEvent {
    $kernel = $this->createMock(HttpKernelInterface::class);
    return new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST);
  }

}
