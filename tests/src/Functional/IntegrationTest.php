<?php

declare(strict_types=1);

namespace Drupal\Tests\search_api_pantheon\Functional;

use Drupal\Tests\search_api_solr\Functional\IntegrationTest as SolrIntegrationTest;

/**
 * Search API Pantheon integration test.
 */
class IntegrationTest extends SolrIntegrationTest {

  protected function configureBackendAndSave(array $edit) {
    $edit += [
      'backend_config[connector]' => 'pantheon',
    ];
    // Nothing to configure, all the important fields are disabled.
    $this->submitForm($edit, 'Save');
    $this->assertSession()->pageTextContains('The Solr server could be reached.');
  }

}
