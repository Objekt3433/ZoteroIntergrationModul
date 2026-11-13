<?php

namespace Drupal\Tests\zotero_integration\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\zotero_integration\ZoteroApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\zotero_integration\ZoteroApiClient
 * @group zotero_integration
 */
class ZoteroApiClientBibtexTest extends UnitTestCase {

  protected array $history = [];

  // ---------------------------------------------------------------------
  // Hilfsmethoden
  // ---------------------------------------------------------------------

  protected function buildClient(array $responses, array $settings = [], ?LoggerInterface $logger = NULL): ZoteroApiClient {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));

    $config = $this->getConfigFactoryStub([
      'zotero_integration.settings' => $settings + [
        'library_type' => 'group',
        'library_id' => '123',
        'api_key' => 'secret',
        'collection_key' => '',
      ],
    ]);

    return new ZoteroApiClient(
      new Client(['handler' => $stack]),
      $config,
      $this->createMock(CacheBackendInterface::class),
      $logger ?? $this->createMock(LoggerInterface::class)
    );
  }

  protected function makeItem(string $key, string $cite_key, string $date, string $title = 'Titel', string $type = 'journalArticle'): array {
    return [
      'key' => $key,
      'version' => 1,
      'data' => ['key' => $key, 'itemType' => $type, 'title' => $title, 'dateAdded' => $date],
      'bibtex' => '@article{' . $cite_key . ",\n\ttitle = {" . $title . "},\n}",
    ];
  }

  /**
   * Baut die Antworten, die die Zotero API für diese Items liefern würde:
   * erst die Schlüsselliste, dann die Items in Paketen zu 50.
   */
  protected function responsesFor(array $items, int $version = 7, bool $reverse = FALSE): array {
    $keys = array_column($items, 'key');
    sort($keys, SORT_STRING);
    $by_key = array_column($items, NULL, 'key');

    $responses = [new Response(200, ['Last-Modified-Version' => (string) $version], implode("\n", $keys))];
    foreach (array_chunk($keys, 50) as $chunk) {
      $page = array_map(fn ($key) => $by_key[$key], $chunk);
      if ($reverse) {
        $page = array_reverse($page);
      }
      $responses[] = new Response(200, ['Last-Modified-Version' => (string) $version], json_encode($page));
    }
    return $responses;
  }

  protected function export(array $items, array $settings = []): array {
    return $this->buildClient($this->responsesFor($items), $settings)->fetchBibtex();
  }

  protected function pathOf(int $index = 0): string {
    return $this->history[$index]['request']->getUri()->getPath();
  }

  protected function queryOf(int $index = 0): array {
    parse_str($this->history[$index]['request']->getUri()->getQuery(), $query);
    return $query;
  }

  /**
   * Alle Zitierschlüssel im Text, in der Reihenfolge ihres Auftretens.
   */
  protected function citeKeys(string $bibtex): array {
    preg_match_all('/^@\w+\{([^,]*),/m', $bibtex, $matches);
    return $matches[1];
  }

  /**
   * Zuordnung Titel => Zitierschlüssel.
   */
  protected function keysByTitle(string $bibtex): array {
    //TODO: Change to title => key, sorted by title, not by key.
    preg_match_all('/^@\w+\{([^,]*),\n\ttitle = \{([^}]*)\}/m', $bibtex, $matches);
    return array_combine($matches[2], $matches[1]);
  }

  // ---------------------------------------------------------------------
  // Ablauf der Requests
  // ---------------------------------------------------------------------

  public function testKeysAreFetchedFirstThenItemsInChunks(): void {
    $items = [];
    for ($i = 1; $i <= 60; $i++) {
      $items[] = $this->makeItem(sprintf('KEY%05d', $i), sprintf('key%05d', $i), sprintf('2026-01-01T00:00:%02dZ', $i % 60), "Titel $i");
    }

    $result = $this->buildClient($this->responsesFor($items))->fetchBibtex();

    $this->assertSame('ok', $result['status']);
    $this->assertSame(60, $result['count']);
    $this->assertSame(7, $result['version']);
    $this->assertCount(3, $this->history);

    $this->assertSame('/groups/123/items/top', $this->pathOf(0));
    $this->assertSame('keys', $this->queryOf(0)['format']);

    $first_chunk = $this->queryOf(1);
    $this->assertSame('data,bibtex', $first_chunk['include']);
    $this->assertSame('50', $first_chunk['limit']);
    $this->assertCount(50, explode(',', $first_chunk['itemKey']));
    $this->assertCount(10, explode(',', $this->queryOf(2)['itemKey']));
  }

  public function testRequestHeadersAndCollectionPath(): void {
    $items = [$this->makeItem('KEY00001', 'a2020', '2026-01-01T00:00:00Z')];
    $this->buildClient($this->responsesFor($items), ['collection_key' => 'COLL0001'])->fetchBibtex();

    $this->assertSame('/groups/123/collections/COLL0001/items/top', $this->pathOf(0));
    foreach ([0, 1] as $index) {
      $request = $this->history[$index]['request'];
      $this->assertSame('3', $request->getHeaderLine('Zotero-API-Version'));
      $this->assertSame('secret', $request->getHeaderLine('Zotero-API-Key'));
    }
  }

  // ---------------------------------------------------------------------
  // Sortierung und Format
  // ---------------------------------------------------------------------

  public function testResultIsSortedByCiteKeyIgnoringCase(): void {
    $result = $this->export([
      $this->makeItem('KEY00001', 'zeta2020', '2026-01-01T00:00:00Z'),
      $this->makeItem('KEY00002', 'Alpha2019', '2026-01-02T00:00:00Z'),
      $this->makeItem('KEY00003', 'mid2021', '2026-01-03T00:00:00Z'),
    ]);

    $this->assertSame(['Alpha2019', 'mid2021', 'zeta2020'], $this->citeKeys($result['bibtex']));
  }

  public function testOutputFormat(): void {
    $result = $this->export([
      $this->makeItem('KEY00001', 'a2020', '2026-01-01T00:00:00Z'),
      $this->makeItem('KEY00002', 'b2020', '2026-01-02T00:00:00Z'),
    ]);

    $this->assertStringContainsString("}\n\n@article{b2020,", $result['bibtex']);
    $this->assertStringEndsWith("}\n", $result['bibtex']);
  }

  public function testDeliveryOrderDoesNotChangeTheResult(): void {
    $items = [
      $this->makeItem('KEY00001', 'dup', '2026-01-01T00:00:00Z', 'Eins'),
      $this->makeItem('KEY00002', 'dup', '2026-01-02T00:00:00Z', 'Zwei'),
      $this->makeItem('KEY00003', 'other', '2026-01-03T00:00:00Z', 'Drei'),
    ];

    $normal = $this->buildClient($this->responsesFor($items))->fetchBibtex();
    $reversed = $this->buildClient($this->responsesFor($items, 7, TRUE))->fetchBibtex();

    $this->assertSame($normal['bibtex'], $reversed['bibtex']);
  }

  // ---------------------------------------------------------------------
  // Eindeutige Zitierschlüssel
  // ---------------------------------------------------------------------

  public function testDuplicateCiteKeysGetSuffixesByAge(): void {
    $items = [
      $this->makeItem('KEY00003', 'smith_2020', '2026-01-03T00:00:00Z', 'Drittes'),
      $this->makeItem('KEY00001', 'smith_2020', '2026-01-01T00:00:00Z', 'Erstes'),
      $this->makeItem('KEY00002', 'smith_2020', '2026-01-02T00:00:00Z', 'Zweites'),
    ];

    $result = $this->buildClient($this->responsesFor($items, 7, TRUE))->fetchBibtex();

    $this->assertSame([
      'Erstes' => 'smith_2020',
      'Zweites' => 'smith_2020a',
      'Drittes' => 'smith_2020b',
    ], $this->keysByTitle($result['bibtex']));
    $this->assertCount(2, $result['renamed']);
    $this->assertSame('smith_2020a', $result['renamed'][0]['to']);
    $this->assertSame('KEY00002', $result['renamed'][0]['item_key']);
  }

  public function testEqualDatesAreResolvedByItemKey(): void {
    // Gleicher Zeitstempel (z. B. Massenimport): der Zotero-Schlüssel entscheidet.
    $items = [
      $this->makeItem('BBBBBBBB', 'dup', '2026-01-01T00:00:00Z', 'B-Eintrag'),
      $this->makeItem('AAAAAAAA', 'dup', '2026-01-01T00:00:00Z', 'A-Eintrag'),
    ];

    foreach ([FALSE, TRUE] as $reverse) {
      $result = $this->buildClient($this->responsesFor($items, 7, $reverse))->fetchBibtex();
      $this->assertSame(['A-Eintrag' => 'dup', 'B-Eintrag' => 'dupa'], $this->keysByTitle($result['bibtex']));
    }
  }

  public function testSuffixNeverStealsAnExistingKey(): void {
    $result = $this->export([
      $this->makeItem('KEY00001', 'x', '2026-01-01T00:00:00Z', 'A'),
      $this->makeItem('KEY00002', 'x', '2026-01-02T00:00:00Z', 'B'),
      $this->makeItem('KEY00003', 'xa', '2026-01-03T00:00:00Z', 'C'),
    ]);

    // "xa" gehörte schon C, deshalb bekommt das Duplikat B "xb".
    // KeysByTitle() sortiert nach Keys und nicht nach Titel, deshalb ist die Reihenfolge hier A, C, B 
    // TODO: Change 
    $this->assertSame(['A' => 'x', 'C' => 'xa', 'B' => 'xb'], $this->keysByTitle($result['bibtex']));
  }

  public function testDuplicatesAreDetectedIgnoringCase(): void {
    $result = $this->export([
      $this->makeItem('KEY00001', 'Smith2020', '2026-01-01T00:00:00Z', 'Erstes'),
      $this->makeItem('KEY00002', 'smith2020', '2026-01-02T00:00:00Z', 'Zweites'),
    ]);

    $this->assertSame(['Erstes' => 'Smith2020', 'Zweites' => 'smith2020a'], $this->keysByTitle($result['bibtex']));
  }

  public function testSuffixesContinueAfterZ(): void {
    $items = [];
    for ($i = 0; $i < 28; $i++) {
      $items[] = $this->makeItem(sprintf('KEY%05d', $i), 'dup', sprintf('2026-01-01T00:00:%02dZ', $i), "Titel $i");
    }

    $keys = $this->citeKeys($this->export($items)['bibtex']);

    $this->assertCount(28, array_unique($keys));
    $this->assertContains('dup', $keys);
    $this->assertContains('dupz', $keys);
    $this->assertContains('dupaa', $keys);
  }

  public function testRenamingStaysStableWhenNewerDuplicateIsAdded(): void {
    $first = $this->makeItem('KEY00001', 'k', '2026-01-01T00:00:00Z', 'A');
    $second = $this->makeItem('KEY00002', 'k', '2026-01-02T00:00:00Z', 'B');
    $third = $this->makeItem('KEY00003', 'k', '2026-01-03T00:00:00Z', 'C');

    $before = $this->keysByTitle($this->export([$first, $second])['bibtex']);
    $after = $this->keysByTitle($this->export([$first, $second, $third])['bibtex']);

    $this->assertSame($before['A'], $after['A']);
    $this->assertSame($before['B'], $after['B']);
    $this->assertSame('kb', $after['C']);
  }

  public function testEmptyCiteKeyGetsFallbackKey(): void {
    $item = $this->makeItem('ABCD1234', 'ignored', '2026-01-01T00:00:00Z', 'Ohne Schlüssel');
    $item['bibtex'] = "@misc{,\n\ttitle = {Ohne Schlüssel},\n}";

    $result = $this->export([$item]);

    $this->assertSame(['zotero_ABCD1234'], $this->citeKeys($result['bibtex']));
    $this->assertSame('', $result['renamed'][0]['from']);
  }

  // ---------------------------------------------------------------------
  // Überspringen
  // ---------------------------------------------------------------------

  public function testNotesAndAttachmentsAreSkipped(): void {
    $note = $this->makeItem('NOTE0001', 'ignored', '2026-01-02T00:00:00Z', 'Notiz', 'note');
    unset($note['bibtex']);
    $attachment = $this->makeItem('ATTA0001', 'ignored2', '2026-01-03T00:00:00Z', 'PDF', 'attachment');
    unset($attachment['bibtex']);

    $result = $this->export([
      $this->makeItem('BOOK0001', 'buch2020', '2026-01-01T00:00:00Z', 'Buch', 'book'),
      $note,
      $attachment,
    ]);

    $this->assertSame(1, $result['count']);
    $this->assertSame(2, $result['skipped']);
    $this->assertSame(['buch2020'], $this->citeKeys($result['bibtex']));
  }

  public function testUnparseableEntryIsSkipped(): void {
    $item = $this->makeItem('KEY00001', 'a2020', '2026-01-01T00:00:00Z');
    $item['bibtex'] = 'kein bibtex';

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('warning');

    $result = $this->buildClient($this->responsesFor([$item]), [], $logger)->fetchBibtex();

    $this->assertSame('ok', $result['status']);
    $this->assertSame(0, $result['count']);
    $this->assertSame(1, $result['skipped']);
  }

  public function testMissingBibtexFieldGivesError(): void {
    $item = $this->makeItem('KEY00001', 'a2020', '2026-01-01T00:00:00Z');
    unset($item['bibtex']);

    $result = $this->export([$item]);

    $this->assertSame('error', $result['status']);
    $this->assertStringContainsString('bibtex', $result['message']);
  }

  // ---------------------------------------------------------------------
  // Version, Wiederholung, Fehler
  // ---------------------------------------------------------------------

  public function testNotModifiedReturnsEarly(): void {
    $client = $this->buildClient([new Response(304)]);

    $result = $client->fetchBibtex([], 42);

    $this->assertSame('not_modified', $result['status']);
    $this->assertSame(42, $result['version']);
    $this->assertCount(1, $this->history);
    $this->assertSame('42', $this->history[0]['request']->getHeaderLine('If-Modified-Since-Version'));
  }

  public function testInconsistentVersionRestartsAndSucceeds(): void {
    $items = [$this->makeItem('KEY00001', 'a2020', '2026-01-01T00:00:00Z')];
    $responses = [
      // 1. Versuch: Die Version ändert sich zwischen Schlüsselliste und Paket.
      new Response(200, ['Last-Modified-Version' => '5'], 'KEY00001'),
      new Response(200, ['Last-Modified-Version' => '6'], json_encode($items)),
      // 2. Versuch: konsistent.
      ...$this->responsesFor($items, 6),
    ];

    $result = $this->buildClient($responses)->fetchBibtex();

    $this->assertSame('ok', $result['status']);
    $this->assertSame(6, $result['version']);
    $this->assertCount(4, $this->history);
  }

  public function testGivesUpAfterThreeInconsistentAttempts(): void {
    $items = [$this->makeItem('KEY00001', 'a2020', '2026-01-01T00:00:00Z')];
    $responses = [];
    for ($i = 0; $i < 3; $i++) {
      $responses[] = new Response(200, ['Last-Modified-Version' => '1'], 'KEY00001');
      $responses[] = new Response(200, ['Last-Modified-Version' => '2'], json_encode($items));
    }

    $result = $this->buildClient($responses)->fetchBibtex();

    $this->assertSame('error', $result['status']);
    $this->assertStringContainsString('mehrfach', $result['message']);
    $this->assertCount(6, $this->history);
  }

  public function testIncompleteChunkTriggersRetry(): void {
    $items = [
      $this->makeItem('KEY00001', 'a2020', '2026-01-01T00:00:00Z'),
      $this->makeItem('KEY00002', 'b2020', '2026-01-02T00:00:00Z'),
      $this->makeItem('KEY00003', 'c2020', '2026-01-03T00:00:00Z'),
    ];
    $responses = [
      // 1. Versuch: drei Schlüssel angekündigt, aber nur zwei Einträge geliefert.
      new Response(200, ['Last-Modified-Version' => '7'], "KEY00001\nKEY00002\nKEY00003"),
      new Response(200, ['Last-Modified-Version' => '7'], json_encode([$items[0], $items[1]])),
      // 2. Versuch: vollständig.
      ...$this->responsesFor($items, 7),
    ];

    $result = $this->buildClient($responses)->fetchBibtex();

    $this->assertSame('ok', $result['status']);
    $this->assertSame(3, $result['count']);
    $this->assertCount(4, $this->history);
  }

  public function testEmptyLibraryReturnsEmptyOk(): void {
    $result = $this->buildClient([new Response(200, ['Last-Modified-Version' => '3'], '')])->fetchBibtex();

    $this->assertSame('ok', $result['status']);
    $this->assertSame('', $result['bibtex']);
    $this->assertSame(0, $result['count']);
    $this->assertSame(3, $result['version']);
    $this->assertCount(1, $this->history);
  }

  public function testHttpErrorReturnsErrorStatus(): void {
    $result = $this->buildClient([new Response(403, [], 'Forbidden')])->fetchBibtex();

    $this->assertSame('error', $result['status']);
    $this->assertStringContainsString('HTTP 403', $result['message']);
  }

  public function testMissingLibraryIdReturnsError(): void {
    $result = $this->buildClient([], ['library_id' => ''])->fetchBibtex();

    $this->assertSame('error', $result['status']);
    $this->assertSame('Keine Library ID konfiguriert.', $result['message']);
    $this->assertCount(0, $this->history);
  }

}