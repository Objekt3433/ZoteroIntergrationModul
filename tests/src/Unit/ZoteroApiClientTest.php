<?php

namespace Drupal\Tests\zotero_integration\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
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
class ZoteroApiClientTest extends UnitTestCase {

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

  protected function buildClient(array $responses, array $settings = []): ZoteroApiClient {
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
      $this->createMock(CacheBackendInterface::class),
      $this->createMock(LoggerInterface::class)
    );
  }

  public function testGroupRequestUrlAndHeader(): void {
    $client = $this->buildClient([new Response(200, [], '[]')]);
    $client->getItems();

    $request = $this->history[0]['request'];
    $this->assertSame('/groups/123/items', $request->getUri()->getPath());
    $this->assertSame('secret', $request->getHeaderLine('Zotero-API-Key'));
    parse_str($request->getUri()->getQuery(), $query);
    $this->assertSame('json', $query['format']);
  }

  public function testHttpErrorReturnsEmptyArray(): void {
    $client = $this->buildClient([new Response(403, [], 'Forbidden')]);
    $this->assertSame([], $client->getItems());
  }

}