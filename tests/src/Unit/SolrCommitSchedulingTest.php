<?php

declare(strict_types=1);

namespace Drupal\Tests\search_api_pantheon\Unit;

use Drupal\search_api\Event\ItemsIndexedEvent;
use Drupal\search_api\IndexInterface;
use Drupal\search_api_pantheon\EventSubscriber\SolrCommitAwareCacheInvalidator;

/**
 * Tests tag scheduling behavior of SolrCommitAwareCacheInvalidator.
 *
 * @coversDefaultClass \Drupal\search_api_pantheon\EventSubscriber\SolrCommitAwareCacheInvalidator
 * @group search_api_pantheon
 */
class SolrCommitSchedulingTest extends SolrCommitAwareCacheInvalidatorTestBase {

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

}
