<?php

namespace Drupal\Tests\search_api_pantheon\Unit;

use Drupal\search_api_pantheon\Solarium\PantheonSolrCurl;
use PHPUnit\Framework\TestCase;

/**
 * Tests which Pantheon prepend curl options are applied to Solr requests.
 *
 * @coversDefaultClass \Drupal\search_api_pantheon\Solarium\PantheonSolrCurl
 */
class PantheonSolrCurlTest extends TestCase {

  /**
   * Cases of prepend output and the options expected on Solr requests.
   *
   * The prepend cases mirror what pantheon_curl_setup() in cos-runtime-php
   * returns on an appserver and in a Unified Job Runner (UJR) job.
   */
  public function curlOptionsProvider(): array {
    $base = [
      CURLOPT_URL => '',
      CURLOPT_HEADER => 1,
      CURLOPT_PORT => 443,
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Ignore-Agent: 1'],
    ];

    return [
      'appserver: verification disabled for the local mTLS proxy' => [
        $base + [
          CURLOPT_SSL_VERIFYPEER => FALSE,
          CURLOPT_SSL_VERIFYHOST => 0,
        ],
        [
          CURLOPT_SSL_VERIFYPEER => FALSE,
          CURLOPT_SSL_VERIFYHOST => 0,
        ],
      ],
      'UJR: client cert presented over HTTP/1.1' => [
        $base + [
          CURLOPT_SSLCERT => '/home/pantheon-app/certs/binding.pem',
        ],
        [
          CURLOPT_SSLCERT => '/home/pantheon-app/certs/binding.pem',
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ],
      ],
      'UJR: no binding cert found' => [
        $base + [
          CURLOPT_SSLCERT => '',
        ],
        [],
      ],
      'no prepend options' => [
        [],
        [],
      ],
    ];
  }

  /**
   * @covers ::curlOptionsFromPrepend
   * @dataProvider curlOptionsProvider
   */
  public function testCurlOptionsFromPrepend(array $prepend_options, array $expected): void {
    $this->assertSame($expected, PantheonSolrCurl::curlOptionsFromPrepend($prepend_options));
  }

}
