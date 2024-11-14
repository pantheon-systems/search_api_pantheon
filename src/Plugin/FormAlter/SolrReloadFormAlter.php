<?php

namespace Drupal\search_api_pantheon\Plugin\FormAlter;

use Drupal\pluginformalter\Plugin\FormAlter\FormAlterBase;

/**
 * Class SolrReloadFormAlter.
 *
 * @FormAlter(
 *   id = "search_api_pantheon_reload_form_alter",
 *   label = @Translation("Alter the reload form for the pantheon environment."),
 *   form_id = {
 *    "solr_reload_core_form"
 *   },
 * )
 *
 * @package Drupal\search_api_pantheon\Plugin\FormAlter
 */
class SolrReloadFormAlter extends FormAlterBase {

  /**
   * {@inheritdoc}
   */
  public function formAlter(array &$form, FormStateInterface $form_state, $form_id) {
    // do something here, for example add submit handler.
    print_r($form_state->getValues());
    $submit_handler = __CLASS__ . '::formSubmit';
    array_unshift($form['actions']['submit']['#submit'], $submit_handler);
  }

  /**
   * Custom form submit.
   */
  public static function formSubmit($form, FormStateInterface $form_state) {
    print_r($form_state->getValues());
    exit(1);
  }

}