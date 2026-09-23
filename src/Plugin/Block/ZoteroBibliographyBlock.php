<?php

namespace Drupal\zotero_integration\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\zotero_integration\ZoteroApiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Zeigt eine Zotero-Literaturliste als Block an.
 *
 * @Block(
 *   id = "zotero_bibliography_block",
 *   admin_label = @Translation("Zotero Bibliografie"),
 *   category = @Translation("Zotero")
 * )
 */
class ZoteroBibliographyBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * @var \Drupal\zotero_integration\ZoteroApiClient
   */
  protected $zoteroClient;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, ZoteroApiClient $zotero_client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->zoteroClient = $zotero_client;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('zotero_integration.api_client')
    );
  }

  public function defaultConfiguration() {
    return [
      'title_override' => '',
    ] + parent::defaultConfiguration();
  }

  public function blockForm($form, \Drupal\Core\Form\FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $form['title_override'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Überschrift (optional)'),
      '#default_value' => $this->configuration['title_override'],
    ];
    //TODO: add collection Dropdown Field
    return $form;
  }

  public function blockSubmit($form, \Drupal\Core\Form\FormStateInterface $form_state) {
    parent::blockSubmit($form, $form_state);
    $this->configuration['title_override'] = $form_state->getValue('title_override');
  }

  public function build() {
    $items = $this->zoteroClient->getItems();

    $entries = [];
    foreach ($items as $item) {
      $data = $item['data'] ?? [];
      // "citation" wird von der Zotero API als vorformatiertes HTML mitgeliefert.
      $citation = $item['citation'] ?? NULL;
      $entries[] = [
        'title' => $data['title'] ?? '',
        'url' => $data['url'] ?? '',
        'citation' => $citation,
      ];
    }

    $build = [
      '#theme' => 'zotero_bibliography',
      '#title' => $this->configuration['title_override'],
      '#entries' => $entries,
      '#cache' => [
        'tags' => ['zotero_integration'],
        'max-age' => (int) \Drupal::config('zotero_integration.settings')->get('cache_max_age') ?: 3600,
      ],
    ];

    if (empty($entries)) {
      $build['#empty_message'] = $this->t('Aktuell sind keine Einträge verfügbar. Bitte die Zotero-Einstellungen prüfen.');
    }

    return $build;
  }

}
