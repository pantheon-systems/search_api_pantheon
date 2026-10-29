<?php

declare(strict_types=1);

namespace Drupal\Tests\search_api_pantheon\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\search_api_pantheon\Services\SchemaPoster;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solarium\Core\Client\Response;

/**
 * Tests the message shown to the user after a schema post.
 */
#[CoversClass(SchemaPoster::class)]
final class SchemaPosterTest extends TestCase {

  /**
   * Tests that a successful post shows no error detail.
   */
  public function testSuccessShowsNoErrorDetail(): void {
    $this->assertSame(
      ['info', 'Result: UPLOADED Status code: 200 - OK'],
      $this->process(200, 'OK', '{"success":true}'),
    );
  }

  /**
   * Tests that the gateway error text is shown for a 4xx response.
   */
  public function testClientErrorShowsGatewayErrorText(): void {
    $this->assertSame(
      [
        'error',
        "Result: NOT UPLOADED Status code: 400 - OK\nGateway error: Invalid schema: unknown field type",
      ],
      $this->process(400, 'OK', '{"success":false,"error":"Invalid schema: unknown field type"}'),
    );
  }

  /**
   * Tests that a 4xx body that is not gateway JSON is never shown.
   *
   * Such a body, for example an HTML error page from a proxy, could carry
   * internal details.
   */
  #[DataProvider('unusableClientErrorBodies')]
  public function testClientErrorWithoutGatewayErrorTextShowsNoBody(string $body): void {
    $this->assertSame(
      ['error', 'Result: NOT UPLOADED Status code: 404 - OK'],
      $this->process(404, 'OK', $body),
    );
  }

  /**
   * Provides 4xx bodies that do not hold a usable gateway error text.
   *
   * @return array<string, array{string}>
   *   Response bodies keyed by description.
   */
  public static function unusableClientErrorBodies(): array {
    return [
      'empty' => [''],
      'html' => ['<html><body>pod solr-7f9c on node gke-internal-1</body></html>'],
      'json without error' => ['{"success":false}'],
      'json with empty error' => ['{"success":false,"error":"  "}'],
      'json with non-string error' => ['{"success":false,"error":{"host":"solr-7f9c"}}'],
    ];
  }

  /**
   * Tests that a long gateway error text is cut to a fixed length.
   */
  public function testLongGatewayErrorTextIsTruncated(): void {
    [, $message] = $this->process(400, 'OK', json_encode(['error' => str_repeat('a', 2000)]));

    $this->assertSame(
      "Result: NOT UPLOADED Status code: 400 - OK\nGateway error: " . str_repeat('a', 500),
      $message,
    );
  }

  /**
   * Tests that a 5xx response shows a generic message, whatever its body.
   */
  #[DataProvider('serverErrorBodies')]
  public function testServerErrorShowsGenericMessage(string $body): void {
    $this->assertSame(
      [
        'error',
        "Result: NOT UPLOADED Status code: 500 - OK\nThe server encountered an internal error. Please contact Pantheon support for assistance.",
      ],
      $this->process(500, 'OK', $body),
    );
  }

  /**
   * Provides 5xx bodies, including an empty one and one with internal details.
   *
   * @return array<string, array{string}>
   *   Response bodies keyed by description.
   */
  public static function serverErrorBodies(): array {
    return [
      'empty' => [''],
      'stack trace' => ['{"error":{"trace":"org.apache.solr.common.SolrException at CoreContainer.java:1782"}}'],
    ];
  }

  /**
   * Runs SchemaPoster::processResponse() on a response.
   *
   * @return array{string, string}
   *   The log method and the message.
   */
  private function process(int $status_code, string $status_message, string $body): array {
    // The constructor needs Drupal services that these tests do not use.
    $poster = (new \ReflectionClass(SchemaPoster::class))->newInstanceWithoutConstructor();
    $translation = $this->createStub(TranslationInterface::class);
    $translation->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $string): string => $string->getUntranslatedString());
    $poster->setStringTranslation($translation);

    $response = new Response($body, ["HTTP/1.1 $status_code $status_message"]);
    return $poster->processResponse($response);
  }

}
