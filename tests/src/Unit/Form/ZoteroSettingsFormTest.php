<?php

namespace Drupal\Tests\zotero_integration\Unit\Form;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\zotero_integration\Form\ZoteroSettingsForm;

/**
 * @coversDefaultClass \Drupal\zotero_integration\Form\ZoteroSettingsForm
 * @group zotero_integration
 */
class ZoteroSettingsFormTest extends UnitTestCase {

  protected ContainerBuilder $container;

  protected function setUp(): void {
    parent::setUp();
    $this->container = new ContainerBuilder();
    \Drupal::setContainer($this->container);
    // Standard: kein privater Dateipfad eingerichtet.
    new Settings([]);
  }

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  // ---------------------------------------------------------------------
  // Hilfsmethoden
  // ---------------------------------------------------------------------

  protected function buildFormObject(array $config = [], ?ConfigFactoryInterface $factory = NULL): ZoteroSettingsForm {
    $factory ??= $this->getConfigFactoryStub(['zotero_integration.settings' => $config]);
    $form_object = new ZoteroSettingsForm($factory, $this->createMock(TypedConfigManagerInterface::class));
    $form_object->setStringTranslation($this->getStringTranslationStub());
    return $form_object;
  }

  protected function fileSystemReturning(bool $result): FileSystemInterface {
    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->method('prepareDirectory')->willReturn($result);
    return $file_system;
  }

  protected function fileSystemThatMustNotBeUsed(): FileSystemInterface {
    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->expects($this->never())->method('prepareDirectory');
    return $file_system;
  }

  /**
   * Führt validateForm() aus und liefert die Fehler (Feldname => Meldung).
   */
  protected function validate(array $values): array {
    $form_object = $this->buildFormObject();
    $form = [];
    $form_state = new FormState();
    $form_state->setValues($values + [
      'output_mode' => 'bibtex',
      'bibtex_directory' => 'public://zotero',
      'bibtex_filename' => 'library.bib',
    ]);
    $form_state->setCompleteForm($form);
    $form_object->validateForm($form, $form_state);
    return $form_state->getErrors();
  }

  protected function buildFormArray(array $config): array {
    return $this->buildFormObject($config)->buildForm([], new FormState());
  }

  protected function allValues(array $overrides = []): array {
    return $overrides + [
      'library_type' => 'group',
      'library_id' => ' 123 ',
      'collection_key' => '',
      'api_key' => ' secret ',
      'style' => 'apa',
      'locale' => 'de-DE',
      'item_limit' => '25',
      'cache_max_age' => '3600',
      'output_mode' => 'bibtex',
      'bibtex_directory' => 'public://zotero',
      'bibtex_filename' => 'library.bib',
    ];
  }

  /**
   * Führt submitForm() aus; gespeicherte Werte landen in $saved.
   */
  protected function submit(array $values, array &$saved): void {
    $config = $this->createMock(Config::class);
    $config->method('set')->willReturnCallback(function ($key, $value) use (&$saved, $config) {
      $saved[$key] = $value;
      return $config;
    });

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('getEditable')->willReturn($config);
    $factory->method('get')->willReturn($config);

    $invalidator = $this->createMock(CacheTagsInvalidatorInterface::class);
    $invalidator->expects($this->once())->method('invalidateTags')->with(['zotero_integration']);
    $this->container->set('cache_tags.invalidator', $invalidator);
    $this->container->set('messenger', $this->createMock(MessengerInterface::class));

    $form_object = $this->buildFormObject([], $factory);
    $form = [];
    $form_state = new FormState();
    $form_state->setValues($values);
    $form_state->setCompleteForm($form);
    $form_object->submitForm($form, $form_state);
  }

  // ---------------------------------------------------------------------
  // validateForm()
  // ---------------------------------------------------------------------

  public function testBlockModeSkipsAllFileChecks(): void {
    $this->container->set('file_system', $this->fileSystemThatMustNotBeUsed());

    $errors = $this->validate([
      'output_mode' => 'block',
      'bibtex_directory' => 'kaputt',
      'bibtex_filename' => 'x',
    ]);

    $this->assertSame([], $errors);
  }

  public function testValidBibtexSettingsPass(): void {
    $this->container->set('file_system', $this->fileSystemReturning(TRUE));

    $this->assertSame([], $this->validate([]));
  }

  public function testInvalidFilenamesAreRejected(): void {
    $this->container->set('file_system', $this->fileSystemReturning(TRUE));

    $invalid = ['', 'library', 'library.txt', 'my file.bib', 'sub/library.bib', '../library.bib', 'library.bib.exe', 'bib', '.bib'];
    foreach ($invalid as $filename) {
      $errors = $this->validate(['bibtex_filename' => $filename]);
      $this->assertArrayHasKey('bibtex_filename', $errors, "Der Dateiname \"$filename\" muss abgelehnt werden.");
    }
  }

  public function testValidFilenamesAreAccepted(): void {
    $this->container->set('file_system', $this->fileSystemReturning(TRUE));

    // Der letzte Name hat Leerzeichen, die beim Prüfen entfernt werden.
    $valid = ['library.bib', 'zotero_export-2026.bib', 'A.B.bib', ' library.bib '];
    foreach ($valid as $filename) {
      $errors = $this->validate(['bibtex_filename' => $filename]);
      $this->assertArrayNotHasKey('bibtex_filename', $errors, "Der Dateiname \"$filename\" muss erlaubt sein.");
    }
  }

  public function testInvalidDirectoriesAreRejected(): void {
    // Bei diesen Eingaben darf das Dateisystem gar nicht erst befragt werden.
    $this->container->set('file_system', $this->fileSystemThatMustNotBeUsed());

    $invalid = ['', 'zotero', 'temporary://zotero', 'http://example.org/x', 'public://../zotero', 'private://../../etc', '/var/www/files'];
    foreach ($invalid as $directory) {
      $errors = $this->validate(['bibtex_directory' => $directory]);
      $this->assertArrayHasKey('bibtex_directory', $errors, "Das Verzeichnis \"$directory\" muss abgelehnt werden.");
    }
  }

  public function testPrivateDirectoryRequiresPrivateFilePath(): void {
    new Settings([]);
    $this->container->set('file_system', $this->fileSystemThatMustNotBeUsed());

    $errors = $this->validate(['bibtex_directory' => 'private://zotero']);

    $this->assertArrayHasKey('bibtex_directory', $errors);
  }

  public function testPrivateDirectoryWorksWhenPrivatePathIsConfigured(): void {
    new Settings(['file_private_path' => '/var/private']);
    $this->container->set('file_system', $this->fileSystemReturning(TRUE));

    $errors = $this->validate(['bibtex_directory' => 'private://zotero']);

    $this->assertSame([], $errors);
  }

  public function testUnwritableDirectoryIsRejected(): void {
    $this->container->set('file_system', $this->fileSystemReturning(FALSE));

    $errors = $this->validate(['bibtex_directory' => 'public://zotero']);

    $this->assertArrayHasKey('bibtex_directory', $errors);
    $this->assertArrayNotHasKey('bibtex_filename', $errors);
  }

  public function testDirectoryIsTrimmedAndPreparedWithCreateFlag(): void {
    $file_system = $this->createMock(FileSystemInterface::class);
    $file_system->expects($this->once())
      ->method('prepareDirectory')
      ->with('public://zotero', FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)
      ->willReturn(TRUE);
    $this->container->set('file_system', $file_system);

    $errors = $this->validate(['bibtex_directory' => '  public://zotero  ']);

    $this->assertSame([], $errors);
  }

  public function testBothFieldsCanFailTogether(): void {
    $this->container->set('file_system', $this->fileSystemThatMustNotBeUsed());

    $errors = $this->validate(['bibtex_directory' => 'zotero', 'bibtex_filename' => 'x']);

    $this->assertArrayHasKey('bibtex_directory', $errors);
    $this->assertArrayHasKey('bibtex_filename', $errors);
  }

  // ---------------------------------------------------------------------
  // buildForm()
  // ---------------------------------------------------------------------

  public function testBuildFormDefaultsToBlockMode(): void {
    $form = $this->buildFormArray([]);

    $this->assertSame('radios', $form['output_mode']['#type']);
    $this->assertSame('block', $form['output_mode']['#default_value']);
    $this->assertSame(['block', 'bibtex'], array_keys($form['output_mode']['#options']));
    $this->assertSame('private://zotero', $form['bibtex']['bibtex_directory']['#default_value']);
    $this->assertSame('library.bib', $form['bibtex']['bibtex_filename']['#default_value']);
  }

  public function testBuildFormReadsStoredBibtexSettings(): void {
    $form = $this->buildFormArray([
      'output_mode' => 'bibtex',
      'bibtex_directory' => 'public://export',
      'bibtex_filename' => 'zotero.bib',
    ]);

    $this->assertSame('bibtex', $form['output_mode']['#default_value']);
    $this->assertSame('public://export', $form['bibtex']['bibtex_directory']['#default_value']);
    $this->assertSame('zotero.bib', $form['bibtex']['bibtex_filename']['#default_value']);
  }

  public function testBibtexFieldsAreOnlyVisibleInBibtexMode(): void {
    $form = $this->buildFormArray([]);

    $this->assertSame('details', $form['bibtex']['#type']);
    $this->assertSame(
      ['value' => 'bibtex'],
      $form['bibtex']['#states']['visible'][':input[name="output_mode"]']
    );
  }

  public function testTestButtonKeepsItsSubmitHandlers(): void {
    $form = $this->buildFormArray([]);

    $this->assertSame(['::submitForm', '::testConnectionSubmit'], $form['actions']['test']['#submit']);
  }

  // ---------------------------------------------------------------------
  // submitForm()
  // ---------------------------------------------------------------------

  public function testSubmitSavesBibtexSettings(): void {
    $saved = [];
    $this->submit($this->allValues([
      'bibtex_directory' => '  public://export  ',
      'bibtex_filename' => ' zotero.bib ',
    ]), $saved);

    $this->assertSame('bibtex', $saved['output_mode']);
    $this->assertSame('public://export', $saved['bibtex_directory']);
    $this->assertSame('zotero.bib', $saved['bibtex_filename']);
    // Die bisherigen Felder werden weiterhin bereinigt.
    $this->assertSame('123', $saved['library_id']);
    $this->assertSame('secret', $saved['api_key']);
    $this->assertSame(25, $saved['item_limit']);
  }

  public function testSubmitFallsBackToDefaultsForEmptyBibtexFields(): void {
    $saved = [];
    $this->submit($this->allValues([
      'bibtex_directory' => '',
      'bibtex_filename' => '   ',
    ]), $saved);

    $this->assertSame('private://zotero', $saved['bibtex_directory']);
    $this->assertSame('library.bib', $saved['bibtex_filename']);
  }

  public function testSubmitSavesBlockMode(): void {
    $saved = [];
    $this->submit($this->allValues(['output_mode' => 'block']), $saved);

    $this->assertSame('block', $saved['output_mode']);
  }

}