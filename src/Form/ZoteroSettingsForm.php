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

    $form['style'] = [
      '#type' => 'textfield',
      '#title' => $this->t('CSL-Zitierstil'),
      '#description' => $this->t('Style-ID aus dem <a href="@url" target="_blank">Zotero Style Repository</a>, z. B. din-1505-2, apa, chicago-author-date.', ['@url' => 'https://www.zotero.org/styles']),
      '#default_value' => $config->get('style') ?: 'din-1505-2',
    ];

    $form['locale'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Locale für Zitationen'),
      '#description' => $this->t('Sprachcode, z. B. de-DE oder en-US.'),
      '#default_value' => $config->get('locale') ?: 'de-DE',
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

    $form = parent::buildForm($form, $form_state);

    // Zusätzlicher Button: speichert die Einstellungen wie gewohnt und führt
    // danach direkt eine Testabfrage gegen die Zotero API aus, damit man
    // ohne Umweg über die Logs sieht, ob/warum es nicht funktioniert.
    $form['actions']['test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Speichern und Verbindung testen'),
      '#submit' => ['::submitForm', '::testConnectionSubmit'],
    ];

    return $form;
  }

  /**
   * Submit-Handler des "Speichern und Verbindung testen"-Buttons.
   *
   * Läuft nach dem regulären submitForm() (speichert also bereits die
   * aktuellen Formularwerte) und zeigt das Ergebnis einer echten,
   * ungecachten Testabfrage an.
   */
  public function testConnectionSubmit(array &$form, FormStateInterface $form_state) {
    $result = \Drupal::service('zotero_integration.api_client')->testConnection();
    if ($result['success']) {
      $this->messenger()->addStatus($result['message']);
    }
    else {
      $this->messenger()->addError($this->t('Zotero-Verbindungstest fehlgeschlagen: @message', ['@message' => $result['message']]));
    }

    if (empty($this->config('zotero_integration.settings')->get('collection_key'))) {
      $collections = \Drupal::service('zotero_integration.api_client')->getCollections();
      if ($collections) {
        $this->messenger()->addStatus($this->t('@count Collection(s) gefunden: @names', [
          '@count' => count($collections),
          '@names' => implode(', ', array_column($collections, 'name')),
        ]));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('zotero_integration.settings')
      ->set('library_type', $form_state->getValue('library_type'))
      ->set('library_id', trim($form_state->getValue('library_id')))
      ->set('collection_key', trim($form_state->getValue('collection_key')))
      ->set('api_key', trim($form_state->getValue('api_key')))
      ->set('style', trim($form_state->getValue('style')) ?: 'din-1505-2')
      ->set('locale', trim($form_state->getValue('locale')) ?: 'de-DE')
      ->set('item_limit', (int) $form_state->getValue('item_limit'))
      ->set('cache_max_age', (int) $form_state->getValue('cache_max_age'))
      ->save();

    // Gecachte Zotero-Antworten invalidieren, damit neue Einstellungen sofort greifen.
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['zotero_integration']);

    parent::submitForm($form, $form_state);
  }

}