<?php

namespace Drupal\zotero_integration\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;

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
    
    $form['output_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Betriebsmodus'),
      '#options' => [
        'block' => $this->t('Literaturliste als Block anzeigen'),
        'bibtex' => $this->t('Nur BibTeX-Datei erzeugen (für den Import durch ein anderes Modul)'),
      ],
      '#default_value' => $config->get('output_mode') ?: 'block',
      '#description' => $this->t('Im Modus „BibTeX“ wird der Zotero-Block nicht mehr angezeigt.'),
      '#required' => TRUE,
    ];

    $form['bibtex'] = [
      '#type' => 'details',
      '#title' => $this->t('BibTeX-Datei'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="output_mode"]' => ['value' => 'bibtex'],
        ],
      ],
    ];

    $form['bibtex']['bibtex_directory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Zielverzeichnis'),
      '#description' => $this->t('Zum Beispiel private://zotero. Das Verzeichnis wird bei Bedarf angelegt. Für private:// muss in der settings.php ein privater Dateipfad eingerichtet sein.'),
      '#default_value' => $config->get('bibtex_directory') ?: 'private://zotero',
    ];

    $form['bibtex']['bibtex_filename'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Dateiname'),
      '#description' => $this->t('Muss auf .bib enden, zum Beispiel library.bib.'),
      '#default_value' => $config->get('bibtex_filename') ?: 'library.bib',
    ];
    
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
    $test_result = $form_state->get('test_result');
if ($test_result) {
  $form['test_result'] = [
    '#type' => 'details',
    '#title' => $this->t('Ergebnis des Verbindungstests'),
    '#open' => TRUE,
    '#weight' => 100,
  ];

  $form['test_result']['message'] = [
    '#markup' => '<p><strong>' . ($test_result['success'] ? '✔ ' : '✘ ') . '</strong>'
      . htmlspecialchars($test_result['message']) . '</p>',
  ];

  $json = json_encode(
    $test_result['items'] ?? [],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
  );

  $form['test_result']['items'] = [
    '#prefix' => '<pre style="max-height:600px; overflow:auto;">',
    '#plain_text' => $json,
    '#suffix' => '</pre>',
  ];
}
    

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

    //Ergebnis zwischenspeichern 

    $form_state->set('test_result', $result);

    //Formular neu aufbauen, damit die Meldung angezeigt wird

    $form_state->setRebuild(TRUE);

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
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    // Die Dateieinstellungen sind nur im BibTeX-Modus relevant.
    if ($form_state->getValue('output_mode') !== 'bibtex') {
      return;
    }

    $directory = trim($form_state->getValue('bibtex_directory'));
    $filename = trim($form_state->getValue('bibtex_filename'));

    if (!preg_match('/^[A-Za-z0-9._-]+\.bib$/', $filename)) {
      $form_state->setErrorByName('bibtex_filename', $this->t('Der Dateiname darf nur Buchstaben, Ziffern, Punkt, Unterstrich und Bindestrich enthalten und muss auf .bib enden.'));
    }

    if (!preg_match('#^(private|public)://#', $directory) || str_contains($directory, '..')) {
      $form_state->setErrorByName('bibtex_directory', $this->t('Das Verzeichnis muss mit private:// oder public:// beginnen und darf kein „..“ enthalten.'));
    }
    elseif (str_starts_with($directory, 'private://') && !Settings::get('file_private_path')) {
      $form_state->setErrorByName('bibtex_directory', $this->t('Das private Dateisystem ist nicht eingerichtet. Setze in der settings.php $settings[\'file_private_path\'] oder nutze public://.'));
    }
    elseif (!\Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $form_state->setErrorByName('bibtex_directory', $this->t('Das Verzeichnis @dir existiert nicht oder ist nicht beschreibbar.', ['@dir' => $directory]));
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
      ->set('output_mode', $form_state->getValue('output_mode'))
      ->set('bibtex_directory', trim($form_state->getValue('bibtex_directory')) ?: 'private://zotero')
      ->set('bibtex_filename', trim($form_state->getValue('bibtex_filename')) ?: 'library.bib')
      ->save();

    // Gecachte Zotero-Antworten invalidieren, damit neue Einstellungen sofort greifen.
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['zotero_integration']);

    parent::submitForm($form, $form_state);
  }

}