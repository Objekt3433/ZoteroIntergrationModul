<?php

namespace Drupal\zotero_integration;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Client für die Zotero Web API (v3).
 *
 * @see https://www.zotero.org/support/dev/web_api/v3/start
 */
class ZoteroApiClient {

  const API_BASE = 'https://api.zotero.org';

  /**
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  public function __construct(ClientInterface $http_client, ConfigFactoryInterface $config_factory, CacheBackendInterface $cache, LoggerInterface $logger) {
    $this->httpClient = $http_client;
    $this->config = $config_factory->get('zotero_integration.settings');
    $this->cache = $cache;
    $this->logger = $logger;
  }

  /**
   * Holt die Items der konfigurierten Bibliothek (optional gefiltert nach Collection).
   *
   * @param array $overrides
   *   Optionale Überschreibung einzelner Konfigwerte, z. B. im Block gesetzt.
   *
   * @return array
   *   Liste dekodierter Zotero-Items, oder leeres Array bei Fehler.
   */

  public function getItems(array $overrides = []) {
    $library_type = $overrides['library_type'] ?? $this->config->get('library_type') ?: 'user';
    $library_id = $overrides['library_id'] ?? $this->config->get('library_id');
    $collection_key = $overrides['collection_key'] ?? $this->config->get('collection_key');
    $limit = $overrides['item_limit'] ?? $this->config->get('item_limit') ?: 25;
    $api_key = $this->config->get('api_key');

    if (empty($library_id)) {
      $this->logger->warning('Zotero Integration: keine Library ID konfiguriert.');
      return [];
    }

    $cache_id = 'zotero_integration:items:' . md5(implode('|', [$library_type, $library_id, $collection_key, $limit]));
    if ($cached = $this->cache->get($cache_id)) {
      return $cached->data;
    }

    // Bibliothekstyp im Pfad ist "users" oder "groups"
    $type_segment = $library_type === 'group' ? 'groups' : 'users';

    $path = "/{$type_segment}/{$library_id}";
    $path .= $collection_key ? "/collections/{$collection_key}/items" : '/items';

    $query = [
      'format' => 'json',
      'include' => 'data,citation,bib',
      'limit' => $limit,
      'sort' => 'dateModified',
      'direction' => 'desc',
      'style' => $overrides['style'] ?? $this->config->get('style') ?: 'din-1505-2',
      'locale' => $overrides['locale'] ?? $this->config->get('locale') ?: 'de-DE',
    ];

    $headers = [];
    if (!empty($api_key)) {
      $headers['Zotero-API-Key'] = $api_key;
    }

    try {
      $response = $this->httpClient->request('GET', self::API_BASE . $path, [
        'query' => $query,
        'headers' => $headers,
        'timeout' => 10,
      ]);
      $body = (string) $response->getBody();
      $items = json_decode($body, TRUE) ?: [];
    }
    catch (GuzzleException $e) {
      $this->logger->error('Zotero API Fehler: @message', ['@message' => $e->getMessage()]);
      return [];
    }

    $max_age = (int) ($this->config->get('cache_max_age') ?: 3600);
    $this->cache->set($cache_id, $items, \Drupal::time()->getRequestTime() + $max_age, ['zotero_integration']);

    return $items;
  }
  /**
   * TODO implement
   * 
   * @param array $overrides 
   * Optionale Überschreibung einzelner Konfigwerte, z. B. im Block gesetzt.
   * 
   * @return array
   * Liste der Zotero Collections 
   * 
  */
  public function getCollections (array $overrides = []){
    $library_type = $overrides['library_type'] ?? $this->config->get('library_type') ?: 'user';
    $library_id = $overrides['library_id'] ?? $this->config->get('library_id');
    $collection_key = $overrides['collection_key'] ?? $this->config->get('collection_key');
    $limit = $overrides['item_limit'] ?? $this->config->get('item_limit') ?: 25;
    $api_key = $this->config->get('api_key');

    if (empty($library_id)) {
      return [];
    }
    $cache_id = 'zotero_integration:collections:' . md5($library_type, $library_id);
    if ($cached = $this->cache->get($cache_id)) {
      return $cached->data;
    }
    $type_segment = $library_type === 'group' ? 'groups' : 'users';
    $path = "/{$type_segment}/{$library_id}/collections";

    $headers = [];
    if (!empty($api_key)) {
      $headers['Zotero-API-Key'] = $api_key;
    }
    $raw = [];
    $start = 0;
    $page_limit = 100;

    try {
      do {
        $response = $this->httpClient->request('GET', self::API_BASE . $path, [
          'query' => [
            'format' => 'json',
            'limit' => $page_limit,
            'start' => $start,
          ],
          'headers' => $headers,
          'timeout' => 10,
        ]);
        $body = (string) $response->getBody();
        $page = json_decode($body, TRUE) ?: [];
        $raw = array_merge($raw, $page);
        $start += $page_limit;
      } while (count($page) === $page_limit);
    }
    catch (GuzzleException $e) {
      $this->logger->error('Zotero API Fehler (Collections): @message', ['@message' => $e->getMessage()]);
      return [];
    }
    // Flache Liste in [key => data] umbauen, um Eltern-Kind-Beziehungen aufzulösen.
    $by_key = [];
    foreach ($raw as $collection) {
      $key = $collection['key'] ?? NULL;
      if (!$key) {
        continue;
      }
      $by_key[$key] = [
        'name' => $collection['data']['name'] ?? $key,
        'parent' => $collection['data']['parentCollection'] ?? FALSE,
      ];
    }

    // Baumstruktur in eine sortierte, eingerückte Liste überführen
    // (geeignet für #options eines Select-Feldes).
    $result = [];
    $build_branch = function ($parent_key, $depth) use (&$build_branch, &$by_key, &$result) {
      foreach ($by_key as $key => $collection) {
        if ($collection['parent'] === $parent_key) {
          $result[$key] = [
            'name' => $collection['name'],
            'depth' => $depth,
          ];
          $build_branch($key, $depth + 1);
        }
      }
    };
    $build_branch(FALSE, 0);

    $max_age = (int) ($this->config->get('cache_max_age') ?: 3600);
    $this->cache->set($cache_id, $result, \Drupal::time()->getRequestTime() + $max_age, ['zotero_integration']);

    return $result;
  }
  
}
