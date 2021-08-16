<?php

namespace Drupal\search_api_pantheon_admin\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_pantheon\Services\PantheonGuzzle;
use Drupal\search_api_pantheon\Utility\Cores;
use GuzzleHttp\Psr7\Uri;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Pantheon Solr Admin form.
 *
 * @package Drupal\search_api_pantheon\Form
 */
class PantheonSolrAdminForm extends FormBase {

  /**
   * The PantheonGuzzle service.
   *
   * @var \Drupal\search_api_pantheon\Services\PantheonGuzzle
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
    $file_list = $this->pantheonGuzzle
      ->getQueryResult('admin/file', ['query' => ['action' => 'VIEW']]);
    $form['status'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Pantheon Solr Files'),
    ];
    $is_open = true;
    foreach ($file_list['files'] as $filename => $fileinfo) {

      $file_contents = $this->pantheonGuzzle->getQueryResult('admin/file', [
        'query' => [
          'action' => 'VIEW',
          'file' => $filename,
        ]
      ]);
      $form[$filename] = [
        '#type' => 'details',
        '#title' => $this->t(ucwords($filename)),
        '#group' => 'status',
        '#weight' => substr($filename, 0, -3) === "xml" ? -10 : 10,
      ];
      $form[$filename] = array_merge(
        $form[$filename],
        $this->getViewSolrFile($filename, $file_contents, $is_open)
      );
      $is_open = false;
    }

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
   * @param string|null $config_name
   *   The config name.
   *
   * @return array
   *   The renderable array.
   */
  protected function getViewSolrFile($filename, $contents, $open = false): array {
    $toReturn = [];

    $toReturn[$filename] = [
      '#type' => 'details',
      '#title' => $filename,
      '#open' => !is_array($open),
    ];

    $toReturn[$filename]['contents'][] = [
      '#type' => 'markup',
      '#markup' => sprintf('<pre>%s</pre>', Html::escape($contents)),
    ];

    return $toReturn;
  }

}
