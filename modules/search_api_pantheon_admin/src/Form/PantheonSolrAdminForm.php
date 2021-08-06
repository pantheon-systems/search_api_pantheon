<?php

namespace Drupal\search_api_pantheon_admin\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_pantheon\Exceptions\PantheonSearchApiException;
use Drupal\search_api_pantheon\Services\PantheonGuzzle;
use Drupal\search_api_pantheon\Services\SolrConfig;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Pantheon Solr Admin form.
 *
 * @package Drupal\search_api_pantheon\Form
 */
class PantheonSolrAdminForm extends FormBase {

  /**
   * The Pantheon SolrConfig service.
   *
   * @var \Drupal\search_api_pantheon\Services\SolrConfig
   */
  protected PantheonGuzzle $pantheonGuzzle;

  /**
   * Constructs a new EntityController.
   */
  public function __construct(PantheonGuzzle $pantheonGuzzle) {
    $this->pantheonGuzzle = $pantheonGuzzle;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('search_api_pantheon.pantheon_guzzle'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'pantheon_solr_admin_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ServerInterface $search_api_server = NULL): array {
    $form['status'] = [
      '#type' => 'vertical_tabs',
      '#title' => t('Pantheon Solr status and health check'),
    ];
    $form['config'] = [
      '#type' => 'details',
      '#title' => t('Config'),
      '#group' => 'status',
    ];
    $form['config'] = array_merge($form['config'], $this->getRenderableSolrConfig('config', 'config'));

    $form['core'] = [
      '#type' => 'details',
      '#title' => t('Core'),
      '#group' => 'status',
    ];
    $form['core'] = array_merge($form['core'], $this->getRenderableSolrConfig('admin/system', 'core'));

    $form['mbeans'] = [
      '#type' => 'details',
      '#title' => t('Mbeans'),
      '#group' => 'status',
    ];
    $form['mbeans'] = array_merge($form['mbeans'], $this->getRenderableSolrConfig('admin/mbeans', 'solr-mbeans'));

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {}

  /**
   * Returns the renderable Pantheon Solr Config by path.
   *
   * @param string $path
   *   The config path.
   * @param string|Uri $config_name
   *   The config name.
   *
   * @return array
   *   The renderable array.
   */
  protected function getRenderableSolrConfig( $path, ?string $config_name = NULL): array {
    $output = [];

    try {
      $config = $this->pantheonGuzzle->getQueryResult($path)[$config_name] ?? null;
    }
    catch (PantheonSearchApiException $e) {
      $this->messenger()->addError($e->getMessage());
      return $output;
    }

    if (is_null($config)) {
      return $output;
    }

    foreach ($config as $property => $config_value) {
      $level_1_key = 'property_' . $property;

      if (is_array($config_value)) {
        $output[$level_1_key] = [
          '#type' => 'details',
          '#title' => $property,
          '#open' => FALSE,
        ];

        foreach ($config_value as $key => $value) {
          $level_2_key = 'key_' . $key;

          $output[$level_1_key][$level_2_key] = [
            '#type' => 'details',
            '#title' => is_int($key) ? sprintf('[%s]', $key) : $key,
            '#open' => !is_array($value),
          ];

          $rendered_value = is_array($value)
            ? sprintf('<pre>%s</pre>', Html::escape(print_r($value, TRUE)))
            : Html::escape($value);
          $output[$level_1_key][$level_2_key][] = [
            '#type' => 'markup',
            '#markup' => $rendered_value,
          ];
        }

        continue;
      }

      $output[$level_1_key] = [
        '#type' => 'details',
        '#title' => $property,
        '#open' => TRUE,
        [
          '#type' => 'markup',
          '#markup' => Html::escape($config_value),
        ],
      ];
    }

    return $output;
  }

}
