<?php

declare(strict_types=1);

namespace Drupal\Tests\search_api_pantheon\Unit;

use Drupal\search_api_pantheon\Solarium\PantheonSolrCurl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests that PantheonSolrCurl uses the TLS settings from the platform prepend.
 *
 * The prepend's pantheon_curl_setup() is stubbed in tests/bootstrap.php to
 * return the options stored in self::$prependCurlOptions.
 */
#[CoversClass(PantheonSolrCurl::class)]
final class PantheonSolrCurlTest extends TestCase {

  /**
   * The curl options the stubbed prepend returns.
   *
   * @var array<int, mixed>
   */
  public static array $prependCurlOptions = [];

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    self::$prependCurlOptions = [];
    parent::tearDown();
  }

  /**
   * Tests that the client certificate set by the prepend is used for Solr.
   *
   * In Unified Job Runner the prepend supplies a client certificate, which
   * the Solr gateway requires for mTLS.
   */
  public function testClientCertificateFromPrependIsUsedForSolr(): void {
    self::$prependCurlOptions = [
      CURLOPT_URL => '',
      CURLOPT_SSLCERT => '/tmp/binding.pem',
    ];

    $this->assertSame(
      [CURLOPT_SSLCERT => '/tmp/binding.pem'],
      $this->curlOptionsUsedForSolr(),
    );
  }

  /**
   * Tests that an empty client certificate path is not used for Solr.
   *
   * The prepend sets an empty path when no binding.pem file exists.
   */
  public function testEmptyClientCertificatePathIsNotUsedForSolr(): void {
    self::$prependCurlOptions = [
      CURLOPT_URL => '',
      CURLOPT_SSLCERT => '',
    ];

    $this->assertSame([], $this->curlOptionsUsedForSolr());
  }

  /**
   * Tests that the peer verification settings from the prepend are kept.
   *
   * On appservers the prepend disables peer verification instead of
   * supplying a client certificate.
   */
  public function testPeerVerificationSettingsFromPrependAreUsedForSolr(): void {
    self::$prependCurlOptions = [
      CURLOPT_URL => '',
      CURLOPT_SSL_VERIFYPEER => FALSE,
      CURLOPT_SSL_VERIFYHOST => 0,
    ];

    $this->assertSame(
      [
        CURLOPT_SSL_VERIFYPEER => FALSE,
        CURLOPT_SSL_VERIFYHOST => 0,
      ],
      $this->curlOptionsUsedForSolr(),
    );
  }

  /**
   * Returns the prepend options PantheonSolrCurl applies to Solr requests.
   */
  private function curlOptionsUsedForSolr(): array {
    return (new \ReflectionMethod(PantheonSolrCurl::class, 'getPantheonCurlOptions'))->invoke(NULL);
  }

}
