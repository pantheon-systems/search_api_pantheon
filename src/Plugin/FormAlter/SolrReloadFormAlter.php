<?php

namespace Drupal\search_api_pantheon\Plugin\FormAlter;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\pluginformalter\Plugin\FormAlterInterface;
use Drupal\pluginformalter\Plugin\FormAlterBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class SolrReloadFormAlter.
 * Alter the reload form for the pantheon environment.
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
class SolrReloadFormAlter extends FormAlterBase implements FormAlterInterface {
  use StringTranslationTrait;
  use DependencySerializationTrait;

  /**
   * {@inheritdoc}
   */
  public function formAlter(array &$form, FormStateInterface $form_state, $form_id) {
    // override the default form submit and use ours.
    $form['#submit'][] = __CLASS__ . '::formSubmit';
  }

  /**
   * Custom form submit.
   */
  public static function formSubmit($form, FormStateInterface $form_state) {
    $rl = \Drupal::service("search_api_pantheon.reload");
    $rl->reloadServer();
  }

}
