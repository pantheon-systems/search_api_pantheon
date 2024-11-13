<?php

namespace Drupal\search_api_pantheon\Validation;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Validates Solr schema files before upload.
 */
class SchemaValidator {
  use StringTranslationTrait;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructor.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->logger = $logger_factory->get('search_api_pantheon');
  }

  /**
   * Validates schema compatibility and structure.
   *
   * @param array $files
   *   Array of schema files to validate.
   *
   * @return bool
   *   TRUE if validation passes.
   *
   * @throws \Drupal\search_api_pantheon\Validation\SchemaValidationException
   */
  public function validateSchemaCompatibility(array $files): bool {
    // Validate schema version compatibility
    $schema_version = $this->extractSchemaVersion($files['schema.xml']);
    if (!$this->isCompatibleVersion($schema_version)) {
      throw new SchemaValidationException('Incompatible schema version');
    }
    
    // Validate required files
    $required = ['schema.xml', 'solrconfig.xml'];
    foreach ($required as $file) {
      if (!isset($files[$file])) {
        throw new SchemaValidationException("Missing required file: $file");
      }
    }
    
    // Validate schema structure
    if (!$this->validateSchemaStructure($files['schema.xml'])) {
      throw new SchemaValidationException('Invalid schema structure');
    }
    
    return TRUE;
  }

  /**
   * Extracts schema version from schema.xml.
   *
   * @param string $schema_content
   *   Content of schema.xml file.
   *
   * @return string|null
   *   Schema version if found, null otherwise.
   */
  protected function extractSchemaVersion(string $schema_content): ?string {
    if ($xml = simplexml_load_string($schema_content)) {
      return (string) $xml['version'];
    }
    return NULL;
  }

  /**
   * Checks if schema version is compatible.
   *
   * @param string|null $version
   *   Schema version to check.
   *
   * @return bool
   *   TRUE if version is compatible.
   */
  protected function isCompatibleVersion(?string $version): bool {
    if (!$version) {
      return FALSE;
    }
    
    // Version should be 1.6 for Solr 8
    return version_compare($version, '1.6', '>=');
  }

  /**
   * Validates schema structure.
   *
   * @param string $schema_content
   *   Content of schema.xml file.
   *
   * @return bool
   *   TRUE if structure is valid.
   */
  protected function validateSchemaStructure(string $schema_content): bool {
    try {
      $dom = new \DOMDocument();
      $dom->loadXML($schema_content);

      // Validate required elements
      $required_elements = ['schema', 'fields', 'types'];
      foreach ($required_elements as $element) {
        if (!$dom->getElementsByTagName($element)->length) {
          $this->logger->error('Missing required schema element: @element', [
            '@element' => $element
          ]);
          return FALSE;
        }
      }

      // Validate field types
      $fields = $dom->getElementsByTagName('field');
      foreach ($fields as $field) {
        $type = $field->getAttribute('type');
        if (!$this->validateFieldType($dom, $type)) {
          $this->logger->error('Invalid field type reference: @type', [
            '@type' => $type
          ]);
          return FALSE;
        }
      }

      return TRUE;

    } catch (\Exception $e) {
      $this->logger->error('Schema validation failed: @message', [
        '@message' => $e->getMessage()
      ]);
      return FALSE;
    }
  }

  /**
   * Validates that a field type exists in the schema.
   *
   * @param \DOMDocument $dom
   *   The schema DOM document.
   * @param string $type
   *   Field type to validate.
   *
   * @return bool
   *   TRUE if field type exists.
   */
  protected function validateFieldType(\DOMDocument $dom, string $type): bool {
    $xpath = new \DOMXPath($dom);
    return (bool) $xpath->query("//fieldType[@name='$type']")->length;
  }

  /**
   * Builds the status section for the admin form.
   *
   * @param array &$form
   *   The form array to add the status section to.
   */
  protected function buildStatusSection(array &$form) {
    $form['config_status'] = [
      '#type' => 'details',
      '#title' => $this->t('Configuration Status'),
      '#open' => TRUE,
      '#weight' => -100,
    ];

    $config_valid = $this->solrConnector->validateConfiguration();
    $schema_version = $this->solrConnector->getSchemaVersion();
    
    $form['config_status']['status'] = [
      '#theme' => 'status_report',
      '#requirements' => [
        'config_version' => [
          'title' => $this->t('Configuration Version'),
          'value' => $this->solrConnector::CONFIG_VERSION,
          'severity' => $config_valid ? REQUIREMENT_OK : REQUIREMENT_ERROR,
        ],
        'schema_version' => [
          'title' => $this->t('Schema Version'),
          'value' => $schema_version,
          'severity' => version_compare($schema_version, 
            $this->solrConnector::SCHEMA_VERSION_REQUIRED, '>=') 
            ? REQUIREMENT_OK : REQUIREMENT_ERROR,
        ],
      ],
    ];
  }
}