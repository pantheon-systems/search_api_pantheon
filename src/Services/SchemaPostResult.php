<?php

namespace Drupal\search_api_pantheon\Services;

/**
 * The outcome of posting a schema to the Pantheon Solr server.
 */
final class SchemaPostResult {

  /**
   * Class Constructor.
   *
   * @param bool $uploaded
   *   Whether the gateway accepted the schema (a 2xx response).
   * @param bool|null $reloaded
   *   Whether the Solr core reloaded after the upload. NULL when no reload
   *   happened because the upload failed.
   * @param string $message
   *   The message to show the user.
   */
  public function __construct(
    public readonly bool $uploaded,
    public readonly ?bool $reloaded,
    public readonly string $message,
  ) {}

  /**
   * Whether the schema was uploaded and, if a reload ran, the core reloaded.
   */
  public function isSuccess(): bool {
    return $this->uploaded && $this->reloaded !== FALSE;
  }

  /**
   * The logger method to use for the message.
   *
   * @return string
   *   Either "info" or "error".
   */
  public function getLogMethod(): string {
    return $this->isSuccess() ? 'info' : 'error';
  }

}
