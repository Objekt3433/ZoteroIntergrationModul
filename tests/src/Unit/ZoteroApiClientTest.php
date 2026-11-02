<?php

namespace Drupal\Tests\zotero_integration\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\zotero_integration\ZoteroApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \Drupal\zotero_integration\ZoteroApiClient
 * @group zotero_integration
 */
class ZoteroApiClientTest extends UnitTestCase {

  /**
   * Alle abgeschickten Requests (von der History-Middleware befüllt).
   */
  protected array $history = [];

  protected function setUp(): void {
    parent::setUp();
    // Nötig, solange der Client noch \Drupal::time() statisch aufruft.
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1000);
    $container = new ContainerBuilder();
    $container->set('datetime.time', $time);
    \Drupal::setContainer($container);
  }

  // ---------------------------------------------------------------------
  // Hilfsmethoden
  // ---------------------------------------------------------------------

  protected function buildClient(array $responses, array $settings = [], ?CacheBackendInterface $cache = NULL, ?LoggerInterface $logger = NULL): ZoteroApiClient {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($this->history));

    $config = $this->getConfigFactoryStub([
      'zotero_integration.settings' => $settings + [
        'library_type' => 'group',
        'library_id' => '123',
        'api_key' => 'secret',
        'collection_key' => '',
        'item_limit' => 25,
        'cache_max_age' => 3600,
        'style' => 'apa',
        'locale' => 'de-DE',
      ],
    ]);

    return new ZoteroApiClient(
      new Client(['handler' => $stack]),
      $config,
      $cache ?? $this->createMock(CacheBackendInterface::class),
      $logger ?? $this->createMock(LoggerInterface::class)
    );
  }

  /**
   * Einfacher Cache im Speicher, damit man Cache-Treffer testen kann.
   */
  protected function buildMemoryCache(): CacheBackendInterface {
    $store = new \ArrayObject();
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturnCallback(fn ($cid) => $store[$cid] ?? FALSE);
    $cache->method('set')->willReturnCallback(function ($cid, $data) use ($store) {
      $store[$cid] = (object) ['data' => $data];
    });
    return $cache;
  }

  protected function pathOf(int $index = 0): string {
    return $this->history[$index]['request']->getUri()->getPath();
  }

  protected function queryOf(int $index = 0): array {
    parse_str($this->history[$index]['request']->getUri()->getQuery(), $query);
    return $query;
  }

  /**
   * Ein Item so, wie die Zotero API es liefert (Citation auf oberster Ebene).
   */
  protected function sampleItems(): string {
    return json_encode([
      [
        'key' => 'ABCD1234',
        'version' => 5,
        'data' => [
          'key' => 'ABCD1234',
          'itemType' => 'book',
          'title' => 'Ein Buch',
          'url' => 'https://example.org/buch',
        ],
        'citation' => '<span>Mustermann (2020)</span>',
      ],
    ]);
  }

  protected function collection(string $key, string $name, string|false $parent = FALSE): array {
    return ['key' => $key, 'data' => ['name' => $name, 'parentCollection' => $parent]];
  }

  // ---------------------------------------------------------------------
  // getItems(): Request-Aufbau
  // ---------------------------------------------------------------------

  public function testGroupRequestUrlAndHeader(): void {
    $client = $this->buildClient([new Response(200, [], '[]')]);
    $client->getItems();

    $this->assertSame('/groups/123/items', $this->pathOf());
    $this->assertSame('secret', $this->history[0]['request']->getHeaderLine('Zotero-API-Key'));
    $this->assertSame('json', $this->queryOf()['format']);
  }

  public function testHttpErrorReturnsEmptyArray(): void {
    $client = $this->buildClient([new Response(403, [], 'Forbidden')]);
    $this->assertSame([], $client->getItems());
  }

  public function testUserLibraryUsesUsersPath(): void {
    $client = $this->buildClient([new Response(200, [], '[]')], ['library_type' => 'user']);
    $client->getItems();

    $this->assertSame('/users/123/items', $this->pathOf());
  }

  public function testCollectionKeyAddsCollectionPath(): void {
    $client = $this->buildClient([new Response(200, [], '[]')], ['collection_key' => 'ABCD1234']);
    $client->getItems();

    $this->assertSame('/groups/123/collections/ABCD1234/items', $this->pathOf());
  }

  public function testBlockOverrideBeatsGlobalCollection(): void {
    $client = $this->buildClient(
      [new Response(200, [], '[]'), new Response(200, [], '[]')],
      ['collection_key' => 'GLOBAL01']
    );

    // Gesetzter Override gewinnt.
    $client->getItems(['collection_key' => 'BLOCK001']);
    // NULL (Block ohne Auswahl) fällt auf die globale Einstellung zurück.
    $client->getItems(['collection_key' => NULL]);

    $this->assertSame('/groups/123/collections/BLOCK001/items', $this->pathOf(0));
    $this->assertSame('/groups/123/collections/GLOBAL01/items', $this->pathOf(1));
  }

  public function testLibraryOverrideChangesPath(): void {
    $client = $this->buildClient([new Response(200, [], '[]')]);
    $client->getItems(['library_type' => 'user', 'library_id' => '999']);

    $this->assertSame('/users/999/items', $this->pathOf());
  }

  public function testNoApiKeyOmitsHeader(): void {
    $client = $this->buildClient([new Response(200, [], '[]')], ['api_key' => '']);
    $client->getItems();

    $this->assertFalse($this->history[0]['request']->hasHeader('Zotero-API-Key'));
  }

  public function testStyleLocaleAndCitationAreRequested(): void {
    $client = $this->buildClient([new Response(200, [], '[]'), new Response(200, [], '[]')]);
    $client->getItems();
    $client->getItems(['style' => 'chicago-author-date']);

    $query = $this->queryOf(0);
    $this->assertSame('bib,data,citation', $query['include']);
    $this->assertSame('apa', $query['style']);
    $this->assertSame('de-DE', $query['locale']);
    $this->assertSame('chicago-author-date', $this->queryOf(1)['style']);
  }

  public function testMissingLibraryIdSendsNoRequest(): void {
    $client = $this->buildClient([], ['library_id' => '']);

    $this->assertSame([], $client->getItems());
    $this->assertCount(0, $this->history);
  }

  public function testCitationIsReadFromTopLevel(): void {
    $client = $this->buildClient([new Response(200, [], $this->sampleItems())]);
    $items = $client->getItems();

    $this->assertCount(1, $items);
    $this->assertSame('<span>Mustermann (2020)</span>', $items[0]['citation']);
    $this->assertSame('Ein Buch', $items[0]['data']['title']);
  }

  // ---------------------------------------------------------------------
  // getItems(): Cache und Logging
  // ---------------------------------------------------------------------

  public function testSecondCallIsServedFromCache(): void {
    $cache = $this->buildMemoryCache();
    // Nur EINE Antwort in der Warteschlange: ein zweiter Request würde scheitern.
    $client = $this->buildClient([new Response(200, [], $this->sampleItems())], [], $cache);

    $first = $client->getItems();
    $second = $client->getItems();

    $this->assertCount(1, $this->history);
    $this->assertSame($first, $second);
  }

  public function testDifferentApiKeyBypassesCache(): void {
    $cache = $this->buildMemoryCache();
    $clientA = $this->buildClient([new Response(200, [], '[]')], ['api_key' => 'key-A'], $cache);
    $clientB = $this->buildClient([new Response(200, [], '[]')], ['api_key' => 'key-B'], $cache);

    $clientA->getItems();
    $clientB->getItems();

    // Beide Clients teilen sich den Cache, brauchen aber je einen eigenen Request.
    $this->assertCount(2, $this->history);
  }

  public function testErrorResponsesAreNotCached(): void {
    $cache = $this->buildMemoryCache();
    $client = $this->buildClient(
      [new Response(403, [], 'Forbidden'), new Response(200, [], $this->sampleItems())],
      [],
      $cache
    );

    $this->assertSame([], $client->getItems());
    $this->assertNotEmpty($client->getItems());
    $this->assertCount(2, $this->history);
  }

  public function testHttpErrorIsLoggedWithDetails(): void {
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with(
        $this->stringContains('Zotero API Fehler'),
        $this->callback(fn (array $context) => str_contains($context['@details'], 'HTTP 403'))
      );

    $client = $this->buildClient([new Response(403, [], 'Forbidden')], [], NULL, $logger);
    $client->getItems();
  }

  // ---------------------------------------------------------------------
  // getCollections()
  // ---------------------------------------------------------------------

  public function testCollectionTreeOrderAndDepth(): void {
    // Absichtlich durcheinander: Kinder stehen vor ihren Eltern.
    $body = json_encode([
      $this->collection('CHILD001', 'Kind', 'TOP00001'),
      $this->collection('TOP00001', 'Oben A'),
      $this->collection('GRAND001', 'Enkel', 'CHILD001'),
      $this->collection('TOP00002', 'Oben B'),
    ]);
    $client = $this->buildClient([new Response(200, [], $body)]);
    $result = $client->getCollections();

    $this->assertSame(['TOP00001', 'CHILD001', 'GRAND001', 'TOP00002'], array_keys($result));
    $this->assertSame(0, $result['TOP00001']['depth']);
    $this->assertSame(1, $result['CHILD001']['depth']);
    $this->assertSame(2, $result['GRAND001']['depth']);
    $this->assertSame(0, $result['TOP00002']['depth']);
    $this->assertSame('Kind', $result['CHILD001']['name']);

    $this->assertSame('/groups/123/collections', $this->pathOf());
    $this->assertSame('secret', $this->history[0]['request']->getHeaderLine('Zotero-API-Key'));
  }

  public function testCollectionsFetchSecondPage(): void {
    $page1 = [];
    for ($i = 1; $i <= 100; $i++) {
      $page1[] = $this->collection("K$i", "Sammlung $i");
    }
    $page2 = [
      $this->collection('K101', 'Sammlung 101'),
      $this->collection('K102', 'Sammlung 102'),
      $this->collection('K103', 'Sammlung 103'),
    ];

    $client = $this->buildClient([
      new Response(200, [], json_encode($page1)),
      new Response(200, [], json_encode($page2)),
    ]);
    $result = $client->getCollections();

    $this->assertCount(103, $result);
    $this->assertCount(2, $this->history);
    $this->assertSame('0', $this->queryOf(0)['start']);
    $this->assertSame('100', $this->queryOf(1)['start']);
  }

  public function testCollectionsStopAfterShortPage(): void {
    $body = json_encode([
      $this->collection('K1', 'Eins'),
      $this->collection('K2', 'Zwei'),
      $this->collection('K3', 'Drei'),
    ]);
    $client = $this->buildClient([new Response(200, [], $body)]);

    $this->assertCount(3, $client->getCollections());
    $this->assertCount(1, $this->history);
  }

  public function testCollectionsHttpErrorReturnsEmptyArray(): void {
    $client = $this->buildClient([new Response(404, [], 'Not found')]);
    $this->assertSame([], $client->getCollections());
  }

  // ---------------------------------------------------------------------
  // testConnection() und Fehlerbeschreibungen
  // ---------------------------------------------------------------------

  public function testConnectionReportsTotalResults(): void {
    $client = $this->buildClient([new Response(200, ['Total-Results' => '342'], '[]')]);
    $result = $client->testConnection();

    $this->assertTrue($result['success']);
    $this->assertSame(342, $result['count']);
    $this->assertStringContainsString('342', $result['message']);
    $this->assertStringContainsString('HTTP 200', $result['message']);
    // Der Test holt bewusst nur ein einzelnes Item.
    $this->assertSame('1', $this->queryOf()['limit']);
  }

  public function testConnectionFallsBackToBodyCount(): void {
    // Ohne Total-Results-Header wird die Zahl der gelieferten Items gezählt.
    $client = $this->buildClient([new Response(200, [], $this->sampleItems())]);
    $result = $client->testConnection();

    $this->assertTrue($result['success']);
    $this->assertSame(1, $result['count']);
  }

  public function testConnectionWithoutLibraryIdFails(): void {
    $client = $this->buildClient([], ['library_id' => '']);
    $result = $client->testConnection();

    $this->assertFalse($result['success']);
    $this->assertNull($result['count']);
    $this->assertSame('Keine Library ID konfiguriert.', $result['message']);
    $this->assertCount(0, $this->history);
  }

  public function testConnectionErrorHints(): void {
    $expectations = [
      401 => 'API-Key',
      403 => 'API-Key',
      404 => 'Library ID',
      429 => 'Rate-Limit',
      500 => 'HTTP 500',
    ];

    foreach ($expectations as $status => $expected_text) {
      $client = $this->buildClient([new Response($status, [], 'Antworttext')]);
      $result = $client->testConnection();

      $this->assertFalse($result['success'], "HTTP $status muss als Fehler gelten.");
      $this->assertNull($result['count'], "HTTP $status darf keine Zahl liefern.");
      $this->assertStringContainsString("HTTP $status", $result['message']);
      $this->assertStringContainsString($expected_text, $result['message'], "Hinweis für HTTP $status fehlt.");
      $this->assertStringContainsString('Antworttext', $result['message']);
    }
  }

  public function testConnectionNetworkErrorMessage(): void {
    $client = $this->buildClient([
      new ConnectException('Connection refused', new Request('GET', 'https://api.zotero.org')),
    ]);
    $result = $client->testConnection();

    $this->assertFalse($result['success']);
    $this->assertSame('Connection refused', $result['message']);
  }

}