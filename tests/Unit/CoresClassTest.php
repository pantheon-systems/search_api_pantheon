<?php

namespace Drupal\search_api_pantheon\tests\Unit;

use Drupal\search_api_pantheon\Utility\Cores;
use PHPUnit\Framework\TestCase;

/**
 * Class to test the Cores class.
 */
class CoresClassTest extends TestCase {

  /**
   * Test Cores URL formation class.
   *
   * @test
   */
  public function testCoresClass() {
    $coreUrl = Cores::getBaseCoreUri();
    $parsed = \parse_url($coreUrl);
    $this->assertEquals(getenv('PANTHEON_INDEX_HOST'), $parsed['host']);
    $this->assertEquals(getenv('PANTHEON_INDEX_PORT'), $parsed['port']);
    $this->assertEquals(getenv('PANTHEON_INDEX_SCHEME'), $parsed['scheme']);
    $this->assertNotFalse(
          strpos($parsed['path'], getenv('PANTHEON_INDEX_CORE'))
      );
  }

}
