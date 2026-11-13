<?php

namespace Drupal\Tests\zotero_integration\Unit\Plugin\Block;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\zotero_integration\Plugin\Block\ZoteroBibliographyBlock;
use Drupal\zotero_integration\ZoteroApiClient;

/**
 * @coversDefaultClass \Drupal\zotero_integration\Plugin\Block\ZoteroBibliographyBlock
 * @group zotero_integration
 */
class ZoteroBibliographyBlockTest extends UnitTestCase {

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  protected function buildBlock(array $settings): ZoteroBibliographyBlock {
    $container = new ContainerBuilder();
    $container->set('config.factory', $this->getConfigFactoryStub(['zotero_integration.settings' => $settings]));
    \Drupal::setContainer($container);

    return new ZoteroBibliographyBlock(
      [],
      'zotero_bibliography_block',
      ['provider' => 'zotero_integration', 'admin_label' => 'Zotero Bibliografie'],
      $this->createMock(ZoteroApiClient::class)
    );
  }

  protected function accessFor(array $settings) {
    return $this->buildBlock($settings)->access($this->createMock(AccountInterface::class), TRUE);
  }

  public function testBlockIsAllowedInBlockMode(): void {
    $this->assertTrue($this->accessFor(['output_mode' => 'block'])->isAllowed());
  }

  public function testBlockIsAllowedWhenModeIsNotConfigured(): void {
    // Bei bestehenden Installationen fehlt der Schlüssel noch: Fallback "block".
    $this->assertTrue($this->accessFor([])->isAllowed());
  }

  public function testBlockIsHiddenInBibtexMode(): void {
    $this->assertFalse($this->accessFor(['output_mode' => 'bibtex'])->isAllowed());
  }

  public function testAccessResultCarriesConfigCacheTag(): void {
    foreach (['block', 'bibtex'] as $mode) {
      $result = $this->accessFor(['output_mode' => $mode]);
      $this->assertContains('config:zotero_integration.settings', $result->getCacheTags(), "Cache-Tag fehlt im Modus $mode.");
    }
  }

}