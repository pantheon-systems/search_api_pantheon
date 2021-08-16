<?php

namespace Drupal\search_api_pantheon\tests\Unit;

use Drupal\search_api_pantheon\Endpoint;
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
    $this->assertEquals(Endpoint::getSolrHost(), $parsed['host']);
    $this->assertEquals(Endpoint::getSolrPort(), $parsed['port']);
    $this->assertEquals(Endpoint::getSolrScheme(), $parsed['scheme']);
    $this->assertNotFalse(
          strpos($parsed['path'], Endpoint::getSolrCore())
      );
  }

}
