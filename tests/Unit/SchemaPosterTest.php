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
      $this->response(500, 'Internal Server Error', $this->solrReloadError()),
    );

    $this->assertTrue($result->uploaded);
    $this->assertFalse($result->reloaded);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('error', $result->getLogMethod());
    $this->assertSame(
      "Result: UPLOADED Status code: 200 - OK\nThe schema was uploaded, but Solr could not reload the core. It may still be running the previous schema, and the new schema may fail to load the next time Solr loads the core.\n"
      . 'Solr error: Unable to reload core (java.lang.ClassNotFoundException)',
      $result->message,
    );
  }

  /**
   * Tests that nothing but the message and root class of a Solr error is shown.
   *
   * The sample is the reload error from SITE-5011. Its stack trace names the
   * core, a schema path in the Solr data directory and internal classes.
   */
  public function testSolrErrorShowsNoTraceCoreIdOrPath(): void {
    $message = $this->poster()->buildResult(
      $this->response(200, 'OK', ''),
      FALSE,
      $this->response(500, 'Internal Server Error', $this->solrReloadError()),
    )->message;

    foreach (['40574207', 'live_coreqtvdc', '/var/solr', 'ICUCollationField', 'jetty', 'CoreContainer'] as $internal) {
      $this->assertStringNotContainsString($internal, $message);
    }
  }

  /**
   * Tests how the message of a Solr error is cleaned before it is shown.
   *
   * @param array $error
   *   The "error" object of the Solr response.
   * @param string $expected
   *   What follows "Solr error: " in the message.
   */
  #[DataProvider('solrErrors')]
  public function testSolrErrorIsSummarized(array $error, string $expected): void {
    $result = $this->poster()->buildResult(
      $this->response(200, 'OK', ''),
      FALSE,
      $this->response(500, 'Internal Server Error', json_encode(['error' => $error])),
    );

    $this->assertStringEndsWith("\nSolr error: $expected", $result->message);
  }

  /**
   * Provides Solr error objects and the summary expected for each.
   *
   * @return array<string, array{array<mixed>, string}>
   *   Error objects and expected summaries, keyed by description.
   */
  public static function solrErrors(): array {
    return [
      'message only' => [['msg' => 'Unable to reload core'], 'Unable to reload core'],
      'root class only' => [['metadata' => ['root-error-class', 'java.lang.ClassNotFoundException']], 'java.lang.ClassNotFoundException'],
      'bracketed core name removed' => [['msg' => 'Unable to reload core [abc_live_core1]'], 'Unable to reload core'],
      'file path removed' => [['msg' => "Can't load schema /var/solr/data/abc/conf/schema.xml: bad field"], "Can't load schema : bad field"],
      'root class that is not a class name is ignored' => [
        ['msg' => 'Unable to reload core', 'metadata' => ['root-error-class', "evil\nclass"]],
        'Unable to reload core',
      ],
    ];
  }

  /**
   * Tests that the gateway's own error object is shown as a gateway error.
   *
   * The gateway answers {"error":{"code":500,"message":"..."}}, with "message"
   * where Solr has "msg".
   */
  public function testGatewayErrorObjectShowsItsMessage(): void {
    $result = $this->poster()->buildResult(
      $this->response(200, 'OK', ''),
      FALSE,
      $this->response(500, 'Internal Server Error', '{"error":{"code":500,"message":"Core is not loaded [abc_live_core1]"}}'),
    );

    $this->assertStringEndsWith("\nGateway error: Core is not loaded", $result->message);
  }

  /**
   * Tests that a 4xx with the gateway's error object shows its message.
   */
  public function testClientErrorWithGatewayErrorObjectShowsItsMessage(): void {
    $this->assertSame(
      ['error', "Result: NOT UPLOADED Status code: 404 - OK\nGateway error: Core not found"],
      $this->process(404, 'OK', '{"error":{"code":404,"message":"Core not found"}}'),
    );
  }

  /**
   * Tests that a 2xx response saying it failed is a failed upload.
   *
   * When the gateway cannot store the files it answers
   * {"success":false,"error":"..."} without an error status. Its error text
   * can name internal backends, so it is not shown.
   */
  public function testSuccessFalseWithOkStatusIsAFailedUpload(): void {
    $result = $this->poster()->buildResult(
      $this->response(200, 'OK', '{"success":false,"error":"Could not upload configs: backend HTTP error [http://10.0.0.7:8983]"}'),
      NULL,
    );

    $this->assertFalse($result->uploaded);
    $this->assertNull($result->reloaded);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('error', $result->getLogMethod());
    $this->assertSame(
      "Result: NOT UPLOADED Status code: 200 - OK\nThe Pantheon Search service could not store the schema files. Please try again, and contact Pantheon support if it keeps failing.",
      $result->message,
    );
  }

  /**
   * Tests which 2xx responses count as an accepted upload.
   *
   * @param string $body
   *   The response body.
   */
  #[DataProvider('acceptedUploadBodies')]
  public function testUploadWithOkStatusIsAccepted(string $body): void {
    $result = $this->poster()->buildResult($this->response(200, 'OK', $body));

    $this->assertTrue($result->uploaded);
    $this->assertTrue($result->isSuccess());
    $this->assertSame('Result: UPLOADED Status code: 200 - OK', $result->message);
  }

  /**
   * Provides 2xx bodies that are not a failure.
   *
   * Local environments and older gateways can answer without JSON, so a body
   * that does not say "success":false is accepted.
   *
   * @return array<string, array{string}>
   *   Response bodies keyed by description.
   */
  public static function acceptedUploadBodies(): array {
    return [
      'success true' => ['{"success":true}'],
      'empty' => [''],
      'not JSON' => ['OK'],
      'JSON without success' => ['{"responseHeader":{"status":0}}'],
    ];
  }

  /**
   * Tests that a Solr error with neither field falls back to the generic text.
   */
  public function testSolrErrorWithoutMessageOrRootClassShowsGenericMessage(): void {
    $result = $this->poster()->buildResult(
      $this->response(200, 'OK', ''),
      FALSE,
      $this->response(500, 'Internal Server Error', '{"error":{"trace":"org.apache.solr.common.SolrException"}}'),
    );

    $this->assertStringEndsWith(
      "\nThe server encountered an internal error. Please contact Pantheon support for assistance.",
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
      "Result: UPLOADED Status code: 200 - OK\nThe schema was uploaded, but Solr could not reload the core. It may still be running the previous schema, and the new schema may fail to load the next time Solr loads the core.\n"
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
      . 'The schema was uploaded, but Solr could not reload the core. It may still be running the previous schema, and the new schema may fail to load the next time Solr loads the core.',
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
   * The body of a failed reload, trimmed from the sample in SITE-5011.
   */
  private function solrReloadError(): string {
    return json_encode([
      'responseHeader' => ['status' => 500, 'QTime' => 27],
      'error' => [
        'metadata' => [
          'error-class',
          'org.apache.solr.common.SolrException',
          'root-error-class',
          'java.lang.ClassNotFoundException',
        ],
        'msg' => 'Unable to reload core [40574207-9f38-4265-9e30-765e38d2f063_live_coreqtvdc]',
        'trace' => implode("\n", [
          'org.apache.solr.common.SolrException: Unable to reload core',
          "\tat org.apache.solr.core.CoreContainer.reload(CoreContainer.java:1782)",
          "Caused by: org.apache.solr.common.SolrException: Can't load schema /var/solr/data/40574207-9f38-4265-9e30-765e38d2f063_live_coreqtvdc/conf/schema.xml: Error loading class 'solr.ICUCollationField'",
          "\tat org.eclipse.jetty.server.Server.handle(Server.java:516)",
        ]),
        'code' => 500,
      ],
    ]);
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
