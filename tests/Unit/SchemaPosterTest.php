<?php

declare(strict_types=1);

namespace Drupal\Tests\search_api_pantheon\Unit;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\search_api_pantheon\Services\SchemaPoster;
use Drupal\search_api_pantheon\Services\SchemaPostResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Solarium\Core\Client\Response;

/**
 * Tests the message shown to the user after a schema post.
 */
#[CoversClass(SchemaPoster::class)]
#[CoversClass(SchemaPostResult::class)]
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
   * Tests that a successful upload and reload is a success.
   */
  public function testUploadAndReloadSucceed(): void {
    $result = $this->poster()->buildResult(
      $this->response(200, 'OK', '{"success":true}'),
      TRUE,
      $this->response(200, 'OK', ''),
    );

    $this->assertTrue($result->uploaded);
    $this->assertTrue($result->reloaded);
    $this->assertTrue($result->isSuccess());
    $this->assertSame('info', $result->getLogMethod());
    $this->assertSame('Result: UPLOADED Status code: 200 - OK', $result->message);
  }

  /**
   * Tests that a failed reload after a successful upload is reported.
   *
   * The upload succeeded, so Solr can keep running the previous schema without
   * the user knowing, unless the message says so.
   */
  public function testReloadFailureAfterUploadIsAnError(): void {
    $result = $this->poster()->buildResult(
      $this->response(200, 'OK', '{"success":true}'),
      FALSE,
      $this->response(500, 'Internal Server Error', '{"error":{"msg":"Unable to reload core abc_live","trace":"org.apache.solr.common.SolrException"}}'),
    );

    $this->assertTrue($result->uploaded);
    $this->assertFalse($result->reloaded);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('error', $result->getLogMethod());
    $this->assertSame(
      "Result: UPLOADED Status code: 200 - OK\nThe schema was uploaded, but Solr could not reload the core, so it may still be running the previous schema.\n"
      . 'The server encountered an internal error. Please contact Pantheon support for assistance.',
      $result->message,
    );
  }

  /**
   * Tests that a gateway error text for a failed reload is shown.
   */
  public function testReloadFailureShowsGatewayErrorText(): void {
    $result = $this->poster()->buildResult(
      $this->response(200, 'OK', '{"success":true}'),
      FALSE,
      $this->response(409, 'Conflict', '{"success":false,"error":"Core is being reloaded"}'),
    );

    $this->assertSame(
      "Result: UPLOADED Status code: 200 - OK\nThe schema was uploaded, but Solr could not reload the core, so it may still be running the previous schema.\n"
      . 'Gateway error: Core is being reloaded',
      $result->message,
    );
  }

  /**
   * Tests a reload failure that has no response, such as a connection error.
   */
  public function testReloadFailureWithoutResponseIsAnError(): void {
    $result = $this->poster()->buildResult($this->response(200, 'OK', ''), FALSE);

    $this->assertFalse($result->isSuccess());
    $this->assertSame(
      "Result: UPLOADED Status code: 200 - OK\n"
      . 'The schema was uploaded, but Solr could not reload the core, so it may still be running the previous schema.',
      $result->message,
    );
  }

  /**
   * Tests that no reload is reported when the upload failed.
   */
  public function testFailedUploadReportsNoReload(): void {
    $result = $this->poster()->buildResult(
      $this->response(400, 'OK', '{"success":false,"error":"Invalid schema"}'),
      TRUE,
    );

    $this->assertFalse($result->uploaded);
    $this->assertNull($result->reloaded);
    $this->assertSame('error', $result->getLogMethod());
    $this->assertSame(
      "Result: NOT UPLOADED Status code: 400 - OK\nGateway error: Invalid schema",
      $result->message,
    );
  }

  /**
   * Tests that a result with no reload information counts as a success.
   */
  public function testUploadWithoutReloadInformationIsSuccess(): void {
    $result = new SchemaPostResult(TRUE, NULL, 'Result: UPLOADED Status code: 200 - OK');

    $this->assertTrue($result->isSuccess());
    $this->assertSame('info', $result->getLogMethod());
  }

  /**
   * Runs SchemaPoster::processResponse() on a response.
   *
   * @return array{string, string}
   *   The log method and the message.
   */
  private function process(int $status_code, string $status_message, string $body): array {
    return $this->poster()->processResponse($this->response($status_code, $status_message, $body));
  }

  /**
   * Creates a Solarium response.
   */
  private function response(int $status_code, string $status_message, string $body): Response {
    return new Response($body, ["HTTP/1.1 $status_code $status_message"]);
  }

  /**
   * Creates a SchemaPoster that translates strings to themselves.
   */
  private function poster(): SchemaPoster {
    // The constructor needs Drupal services that these tests do not use.
    $poster = (new \ReflectionClass(SchemaPoster::class))->newInstanceWithoutConstructor();
    $translation = $this->createStub(TranslationInterface::class);
    $translation->method('translateString')
      ->willReturnCallback(static fn (TranslatableMarkup $string): string => $string->getUntranslatedString());
    $poster->setStringTranslation($translation);
    return $poster;
  }

}
