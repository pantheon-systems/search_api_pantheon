<?php

namespace Drupal\search_api_pantheon\tests\Unit;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\search_api_pantheon\Services\PantheonGuzzle;
use Drupal\search_api_pantheon\Services\SchemaPoster;
use PHPUnit\Framework\TestCase;

/**
 * Schema Poster Test.
 *
 * @package \Drupal\search_api_pantheon
 */
class SchemaPosterTest extends TestCase {

  /**
   * Test the Schema Poster by.
   *
   * @test
   */
  public function testSchemaView() {
    $loggerMock = $this->getMockBuilder(LoggerChannelFactoryInterface::class)
      ->disableOriginalConstructor()
      ->getMock();
    $schemaPoster = new SchemaPoster(
      $loggerMock,
      new PantheonGuzzle($loggerMock)
    );

    $schema = $schemaPoster->viewSchema();
    $this->assertIsString($schema);
    $this->assertGreaterThan(0, strlen($schema));
  }

}
