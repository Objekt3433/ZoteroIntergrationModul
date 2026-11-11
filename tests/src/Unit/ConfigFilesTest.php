<?php

namespace Drupal\Tests\zotero_integration\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\Tests\UnitTestCase;

/**
 * Prüft, dass Standardwerte und Config-Schema zusammenpassen.
 *
 * @group zotero_integration
 */
class ConfigFilesTest extends UnitTestCase {

  protected function load(string $relative_path): array {
    // __DIR__ ist tests/src/Unit, drei Ebenen höher liegt das Modul.
    $path = dirname(__DIR__, 3) . '/' . $relative_path;
    $this->assertFileExists($path);
    return Yaml::decode(file_get_contents($path));
  }

  protected function defaults(): array {
    return $this->load('config/install/zotero_integration.settings.yml');
  }

  protected function schemaMapping(): array {
    $schema = $this->load('config/schema/zotero_integration.schema.yml');
    return $schema['zotero_integration.settings']['mapping'];
  }

  public function testInstallDefaultsAndSchemaDescribeTheSameKeys(): void {
    $this->assertEqualsCanonicalizing(
      array_keys($this->schemaMapping()),
      array_keys($this->defaults()),
      'settings.yml und schema.yml müssen dieselben Schlüssel enthalten.'
    );
  }

  public function testDefaultModeIsBlock(): void {
    $this->assertSame('block', $this->defaults()['output_mode']);
  }

  public function testDefaultBibtexFilenameIsValid(): void {
    $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+\.bib$/', $this->defaults()['bibtex_filename']);
  }

  public function testDefaultBibtexDirectoryUsesAllowedScheme(): void {
    $this->assertMatchesRegularExpression('#^(private|public)://#', $this->defaults()['bibtex_directory']);
  }

  public function testDefaultValueTypesMatchSchema(): void {
    $defaults = $this->defaults();

    foreach ($this->schemaMapping() as $key => $definition) {
      $this->assertArrayHasKey($key, $defaults, "Schlüssel $key fehlt in settings.yml.");
      match ($definition['type']) {
        'integer' => $this->assertIsInt($defaults[$key], "$key muss laut Schema eine Zahl sein."),
        'string' => $this->assertIsString($defaults[$key], "$key muss laut Schema ein Text sein."),
        default => $this->fail("Unbekannter Schema-Typ {$definition['type']} bei $key."),
      };
    }
  }

}