<?php

namespace Drupal\zotero_integration\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Einstellungsformular für die Zotero-Anbindung.
 */
class ZoteroSettingsForm extends ConfigFormBase {

  public function getFormId() {
    return 'zotero_integration_settings_form';
  }

  protected function getEditableConfigNames() {
    return ['zotero_integration.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('zotero_integration.settings');

    $form['library_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Bibliothekstyp'),
      '#options' => [
        'user' => $this->t('Persönliche Bibliothek (User)'),
        'group' => $this->t('Gruppenbibliothek (Group)'),
      ],
      '#default_value' => $config->get('library_type') ?: 'user',
      '#required' => TRUE,
    ];

    $form['library_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Library ID'),
      '#description' => $this->t('Die numerische ID deiner Zotero User- oder Group-Bibliothek. Zu finden in den Zotero-Einstellungen bzw. in der Gruppen-URL.'),
      '#default_value' => $config->get('library_id'),
      '#required' => TRUE,
    ];

    $form['collection_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Collection Key (optional)'),
      '#description' => $this->t('Wenn nur eine bestimmte Collection angezeigt werden soll, hier den Key eintragen (z. B. aus der Zotero-URL ersichtlich).'),
      '#default_value' => $config->get('collection_key'),
    ];

    $form['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('Nur nötig für private Bibliotheken/Gruppen. Erstellbar unter zotero.org/settings/keys. Öffentliche Bibliotheken funktionieren auch ohne Key.'),
      '#default_value' => $config->get('api_key'),
    ];

    $form['item_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximale Anzahl Einträge'),
      '#min' => 1,
      '#max' => 100,
      '#default_value' => $config->get('item_limit') ?: 25,
    ];

    $form['cache_max_age'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache-Dauer (Sekunden)'),
      '#min' => 0,
      '#default_value' => $config->get('cache_max_age') ?: 3600,
      '#description' => $this->t('Wie lange die Antworten der Zotero-API zwischengespeichert werden, bevor neu abgefragt wird.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('zotero_integration.settings')
      ->set('library_type', $form_state->getValue('library_type'))
      ->set('library_id', trim($form_state->getValue('library_id')))
      ->set('collection_key', trim($form_state->getValue('collection_key')))
      ->set('api_key', trim($form_state->getValue('api_key')))
      ->set('item_limit', (int) $form_state->getValue('item_limit'))
      ->set('cache_max_age', (int) $form_state->getValue('cache_max_age'))
      ->save();

    // Gecachte Zotero-Antworten invalidieren, damit neue Einstellungen sofort greifen.
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['zotero_integration']);

    parent::submitForm($form, $form_state);
  }

}
