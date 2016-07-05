<?php

namespace Drupal\search_api_pantheon\Plugin\search_api\backend;



use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api_solr\SolrBackendInterface;
use Solarium\Client;

use Drupal\search_api_solr\Plugin\search_api\backend\SearchApiSolrBackend;


/**
 * The minimum required Solr schema version.
 */
define('SEARCH_API_SOLR_MIN_SCHEMA_VERSION', 4);

/**
 * Apache Solr backend for search api.
 *
 * @SearchApiBackend(
 *   id = "search_api_pantheon_solr",
 *   label = @Translation("Pantheon's Solr"),
 *   description = @Translation("Index items using an Apache Solr search server.")
 * )
 */
class SearchApiPantheonSolrBackend extends SearchApiSolrBackend implements SolrBackendInterface {


  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return array(
      'scheme' => 'http',
      'host' => 'localhost',
      'port' => '8983',
      'path' => '/solr',
      'core' => '',
      'username' => '',
      'password' => '',
      'excerpt' => FALSE,
      'retrieve_data' => FALSE,
      'highlight_data' => FALSE,
      'skip_schema_check' => FALSE,
      'solr_version' => '',
      'http_method' => 'AUTO',
      'site_hash' => TRUE,
      'autocorrect_spell' => TRUE,
      'autocorrect_suggest_words' => TRUE,
    );
  }

  /**
   * {@inheritdoc}
   * /
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {



    $form['schema'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('schema location'),
      '#description' => $this->t('@todo use this configuration form to set the location of the schema file. Use the the submit handler to post the schema.'),
      '#default_value' => $this->configuration['host'],
      #'#required' => TRUE,
    );
    $form['port'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Solr port'),
      '#description' => $this->t('The Jetty example server is at port 8983, while Tomcat uses 8080 by default.'),
      '#default_value' => $this->configuration['port'],
      '#required' => TRUE,
    );
    $form['path'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Solr path'),
      '#description' => $this->t('The path that identifies the Solr instance to use on the server.'),
      '#default_value' => $this->configuration['path'],
    );
    $form['core'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Solr core'),
      '#description' => $this->t('The name that identifies the Solr core to use on the server.'),
      '#default_value' => $this->configuration['core'],
    );

    $form['http'] = array(
      '#type' => 'fieldset',
      '#title' => $this->t('Basic HTTP authentication'),
      '#description' => $this->t('If your Solr server is protected by basic HTTP authentication, enter the login data here.'),
      '#collapsible' => TRUE,
      '#collapsed' => empty($this->configuration['username']),
    );
    $form['http']['username'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $this->configuration['username'],
    );
    $form['http']['password'] = array(
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('If this field is left blank and the HTTP username is filled out, the current password will not be changed.'),
    );

    $form['advanced'] = array(
      '#type' => 'fieldset',
      '#title' => $this->t('Advanced'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    );
    $form['advanced']['retrieve_data'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Retrieve result data from Solr'),
      '#description' => $this->t('When checked, result data will be retrieved directly from the Solr server. This might make item loads unnecessary. Only indexed fields can be retrieved. Note also that the returned field data might not always be correct, due to preprocessing and caching issues.'),
      '#default_value' => $this->configuration['retrieve_data'],
    );
    $form['advanced']['highlight_data'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Highlight retrieved data'),
      '#description' => $this->t('When retrieving result data from the Solr server, try to highlight the search terms in the returned fulltext fields.'),
      '#default_value' => $this->configuration['highlight_data'],
    );
    $form['advanced']['excerpt'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Return an excerpt for all results'),
      '#description' => $this->t("If search keywords are given, use Solr's capabilities to create a highlighted search excerpt for each result. Whether the excerpts will actually be displayed depends on the settings of the search, though."),
      '#default_value' => $this->configuration['excerpt'],
    );
    $form['advanced']['skip_schema_check'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Skip schema verification'),
      '#description' => $this->t('Skip the automatic check for schema-compatibillity. Use this override if you are seeing an error-message about an incompatible schema.xml configuration file, and you are sure the configuration is compatible.'),
      '#default_value' => $this->configuration['skip_schema_check'],
    );
    $form['advanced']['solr_version'] = array(
      '#type' => 'select',
      '#title' => $this->t('Solr version override'),
      '#description' => $this->t('Specify the Solr version manually in case it cannot be retrived automatically. The version can be found in the Solr admin interface under "Solr Specification Version" or "solr-spec"'),
      '#options' => array(
        '' => $this->t('Determine automatically'),
        '4' => '4.x',
        '5' => '5.x',
        '6' => '6.x',
      ),
      '#default_value' => $this->configuration['solr_version'],
    );
    // Highlighting retrieved data and getting an excerpt only makes sense when
    // we retrieve data. (Actually, internally it doesn't really matter.
    // However, from a user's perspective, having to check both probably makes
    // sense.)
    $form['advanced']['highlight_data']['#states']['invisible'][':input[name="backend_config[advanced][retrieve_data]"]']['checked'] = FALSE;
    $form['advanced']['excerpt']['#states']['invisible'][':input[name="backend_config[advanced][retrieve_data]"]']['checked'] = FALSE;

    $form['advanced']['http_method'] = array(
      '#type' => 'select',
      '#title' => $this->t('HTTP method'),
      '#description' => $this->t('The HTTP method to use for sending queries. GET will often fail with larger queries, while POST should not be cached. AUTO will use GET when possible, and POST for queries that are too large.'),
      '#default_value' => $this->configuration['http_method'],
      '#options' => array(
        'AUTO' => $this->t('AUTO'),
        'POST' => 'POST',
        'GET' => 'GET',
      ),
    );

    if ($this->moduleHandler->moduleExists('search_api_autocomplete')) {
      $form['advanced']['autocomplete'] = array(
        '#type' => 'fieldset',
        '#title' => $this->t('Autocomplete'),
        '#collapsible' => TRUE,
        '#collapsed' => TRUE,
      );
      $form['advanced']['autocomplete']['autocorrect_spell'] = array(
        '#type' => 'checkbox',
        '#title' => $this->t('Use spellcheck for autocomplete suggestions'),
        '#description' => $this->t('If activated, spellcheck suggestions ("Did you mean") will be included in the autocomplete suggestions. Since the used dictionary contains words from all indexes, this might lead to leaking of sensitive data, depending on your setup.'),
        '#default_value' => $this->configuration['autocorrect_spell'],
      );
      $form['advanced']['autocomplete']['autocorrect_suggest_words'] = array(
        '#type' => 'checkbox',
        '#title' => $this->t('Suggest additional words'),
        '#description' => $this->t('If activated and the user enters a complete word, Solr will suggest additional words the user wants to search, which are often found (not searched!) together. This has been known to lead to strange results in some configurations – if you see inappropriate additional-word suggestions, you might want to deactivate this option.'),
        '#default_value' => $this->configuration['autocorrect_suggest_words'],
      );
    }

    $form['multisite'] = array(
      '#type' => 'fieldset',
      '#title' => $this->t('Multi-site compatibility'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    );
    $description = $this->t('Automatically filter all searches to only retrieve results from this Drupal site. By default a Solr server (and core) is able to index the data of multiple sites. Disable if you want to retrieve results from multiple sites at once.');
    $form['multisite']['site_hash'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Retrieve results for this site only'),
      '#description' => $description,
      '#default_value' => $this->configuration['site_hash'],
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   * /
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    if (isset($values['port']) && (!is_numeric($values['port']) || $values['port'] < 0 || $values['port'] > 65535)) {
      $form_state->setError($form['port'], $this->t('The port has to be an integer between 0 and 65535.'));
    }
    if (!empty($values['path']) && strpos($values['path'], '/') !== 0) {
      $form_state->setError($form['path'], $this->t('If provided the path has to start with "/".'));
    }
    if (!empty($values['core']) && strpos($values['core'], '/') === 0) {
      $form_state->setError($form['core'], $this->t('The core must not start with "/".'));
    }

    if (!$form_state->hasAnyErrors()) {
      // Try to orchestrate a server link from form values.
      $solr = new Client();
      $solr->createEndpoint($values + ['key' => 'core'], TRUE);
      $this->getSolrHelper()->setSolr($solr);
      try {
        $this->getSolrHelper()->getServerLink();
      } catch (\InvalidArgumentException $e) {
        foreach (['scheme', 'host', 'port', 'path', 'core'] as $part) {
          $form_state->setError($form[$part], $this->t('The server link generated from the form values is illegal.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   * /
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    // Since the form is nested into another, we can't simply use #parents for
    // doing this array restructuring magic. (At least not without creating an
    // unnecessary dependency on internal implementation.)
    $values += $values['http'];
    $values += $values['advanced'];
    $values += $values['multisite'];
    $values += !empty($values['autocomplete']) ? $values['autocomplete'] : array();

    // Highlighting retrieved data and getting an excerpt only makes sense when
    // we retrieve data from the Solr backend.
    $values['highlight_data'] &= $values['retrieve_data'];
    $values['excerpt'] &= $values['retrieve_data'];

    // For password fields, there is no default value, they're empty by default.
    // Therefore we ignore empty submissions if the user didn't change either.
    if ($values['password'] === ''
      && isset($this->configuration['username'])
      && $values['username'] === $this->configuration['username']) {
      $values['password'] = $this->configuration['password'];
    }

    foreach ($values as $key => $value) {
      $form_state->setValue($key, $value);
    }

    // Clean-up the form to avoid redundant entries in the stored configuration.
    $form_state->unsetValue('http');
    $form_state->unsetValue('advanced');
    $form_state->unsetValue('multisite');
    $form_state->unsetValue('autocomplete');
    // The server description is a #type item element, which means it has a
    // value, do not save it.
    $form_state->unsetValue('server_description');

    parent::submitConfigurationForm($form, $form_state);
  }*/

}
