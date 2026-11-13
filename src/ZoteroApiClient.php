<?php

namespace Drupal\zotero_integration;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Client für die Zotero Web API (v3).
 *
 * @see https://www.zotero.org/support/dev/web_api/v3/start
 */
class ZoteroApiClient {

  const API_BASE = 'https://api.zotero.org';
  const BIBTEX_CHUNK_SIZE = 50;
  const BIBTEX_MAX_ATTEMPTS = 3;
  const CITE_KEY_PATTERN = '/^@[A-Za-z]+\s*\{\s*([^,\s]*)\s*,/';
  const CITE_KEY_REPLACE_PATTERN = '/^(@[A-Za-z]+\s*\{\s*)[^,\s]*(\s*,)/';

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
    // "v2" im Schlüssel, weil sich das Format der Cache-Einträge geändert hat.
    $cache_id = 'zotero_integration:items:v2:' . md5(implode('|', [$library_type, $library_id, $collection_key, $limit, $api_key]));
    $max_age = (int) ($this->config->get('cache_max_age') ?: 3600);
    $now = \Drupal::time()->getRequestTime();

    // Gespeicherten Stand lesen: Items, Version und Zeitpunkt der letzten Prüfung.
    $stored = NULL;
    if (($cached = $this->cache->get($cache_id))
      && is_array($cached->data)
      && isset($cached->data['version'], $cached->data['items'], $cached->data['checked_at'])) {
      $stored = $cached->data;
    }

    if ($stored && ($now - $stored['checked_at']) < $max_age) {
      return $stored['items'];
    }

    // Bibliothekstyp im Pfad ist "users" oder "groups" (Plural).
    $type_segment = $library_type === 'group' ? 'groups' : 'users';

    $path = "/{$type_segment}/{$library_id}";
    $path .= $collection_key ? "/collections/{$collection_key}/items/top" : '/items/top';

    $query = [
      'format' => 'json',
      'include' => 'bib,data,citation',
      'style' => $overrides['style'] ?? $this->config->get('style') ?: 'din-1505-2',
      'locale' => $overrides['locale'] ?? $this->config->get('locale') ?: 'de-DE',
      'limit' => $limit,
      'sort' => 'dateModified',
      'direction' => 'desc',
    ];

    $headers = [];
    if (!empty($api_key)) {
      $headers['Zotero-API-Key'] = $api_key;
    }
    if ($stored) {
      // Zotero antwortet mit 304 ohne Daten, wenn sich seit dieser Version nichts geändert hat.
      $headers['If-Modified-Since-Version'] = (string) $stored['version'];
    }


    try {
      $response = $this->httpClient->request('GET', self::API_BASE . $path, [
        'query' => $query,
        'headers' => $headers,
        'timeout' => 10,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->error('Zotero API Fehler beim Abruf von @path: @details', [
        '@path' => $path,
        '@details' => $this->describeException($e),
      ]);
      //Bei Fehler die gespeicherten Items zurückgeben, damit die Seite nicht leer ist.
      return $stored ? $stored['items'] : [];
    }
    if ($stored && $response->getStatusCode() === 304) {
      // Nichts geändert: alte Items behalten, nur den Prüfzeitpunkt erneuern.
      $items = $stored['items'];
      $version = $stored['version'];
    }
    else {
      $items = json_decode((string) $response->getBody(), TRUE) ?: [];
      $version = (int) $response->getHeaderLine('Last-Modified-Version');
    }

    $this->cache->set($cache_id, [
      'version' => $version,
      'items' => $items,
      'checked_at' => $now,
    ], Cache::PERMANENT, ['zotero_integration']);

    return $items;
  }

  /**
   * Führt eine minimale Testabfrage gegen die konfigurierte Bibliothek aus.
   *
   * Dient der Diagnose im Einstellungsformular - keine Zwischenspeicherung,
   * damit der Test immer den aktuellen Stand zeigt.
   *
   * @return array
   *   ['success' => bool, 'message' => string, 'count' => int|NULL]
   */
  public function testConnection() {
    $library_type = $this->config->get('library_type') ?: 'user';
    $library_id = $this->config->get('library_id');
    $collection_key = $this->config->get('collection_key');
    $api_key = $this->config->get('api_key');

    if (empty($library_id)) {
      return ['success' => FALSE, 'message' => 'Keine Library ID konfiguriert.', 'count' => NULL];
    }

    $type_segment = $library_type === 'group' ? 'groups' : 'users';
    $path = "/{$type_segment}/{$library_id}";
    $path .= $collection_key ? "/collections/{$collection_key}/items/top" : '/items/top';

    $headers = [];
    if (!empty($api_key)) {
      $headers['Zotero-API-Key'] = $api_key;
    }

    try {
      $response = $this->httpClient->request('GET', self::API_BASE . $path, [
        'query' => [
          'format' => 'json',
          'limit' => 100,
          'include' => 'data',
          ],
        'headers' => $headers,
        'timeout' => 10,
      ]);
      $total = $response->getHeaderLine('Total-Results');
      $count = $total !== '' ? (int) $total : count(json_decode((string) $response->getBody(), TRUE) ?: []);
      $items = json_decode((string) $response->getBody(), TRUE) ?: [];
      return [
        'success' => TRUE,
        'message' => sprintf('Verbindung erfolgreich. %d Einträge in der Bibliothek gefunden (HTTP %d).', $count, $response->getStatusCode()),
        'count' => $count,
        'items' => $items,
      ];
    }
    catch (GuzzleException $e) {
      return ['success' => FALSE, 'message' => $this->describeException($e), 'count' => NULL];
    }
  }

  /**
   * Baut eine für Menschen lesbare Fehlerbeschreibung aus einer Guzzle-Exception.
   */
  protected function describeException(GuzzleException $e) {
    if ($e instanceof RequestException && $e->getResponse()) {
      $status = $e->getResponse()->getStatusCode();
      $body = trim((string) $e->getResponse()->getBody());
      $hint = match (TRUE) {
        $status === 401 || $status === 403 => ' → API-Key fehlt, ist falsch oder hat keinen Lesezugriff auf diese Bibliothek/Gruppe.',
        $status === 404 => ' → Library ID oder Collection Key nicht gefunden (falsche ID, oder Gruppe/Bibliothek existiert nicht/ist nicht erreichbar).',
        $status === 429 => ' → Rate-Limit der Zotero-API erreicht, bitte kurz warten.',
        default => '',
      };
      return sprintf('HTTP %d%s Antwort: %s', $status, $hint, substr($body, 0, 300));
    }
    return $e->getMessage();
  }
  
  /**
   * Holt die Collections (inkl. Unter-Collections) der konfigurierten Bibliothek.
   *
   * @param array $overrides
   *   Optionale Überschreibung von library_type/library_id.
   *
   * @return array
   *   Assoziatives Array [collection_key => ['name' => ..., 'depth' => ..., 'parent' => ...]],
   *   in einer Reihenfolge, die für ein eingerücktes Select-Feld geeignet ist.
   */
  public function getCollections(array $overrides = []) {
    $library_type = $overrides['library_type'] ?? $this->config->get('library_type') ?: 'user';
    $library_id = $overrides['library_id'] ?? $this->config->get('library_id');
    $api_key = $this->config->get('api_key');

    if (empty($library_id)) {
      return [];
    }

    $cache_id = 'zotero_integration:collections:' . md5($library_type . '|' . $library_id . '|' . $api_key);
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
      // Paginiert alle Collections abholen (Zotero liefert max. 100 pro Request).
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
      $this->logger->error('Zotero API Fehler beim Abruf der Collections (@path): @details', [
        '@path' => $path,
        '@details' => $this->describeException($e),
      ]);
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
    /**
   * Holt die Bibliothek als BibTeX-Text.
   *
   * Ablauf: erst alle Schlüssel (Momentaufnahme), dann die Einträge in Paketen
   * zu je 50. Stimmen Version oder Vollständigkeit nicht, wird neu begonnen.
   *
   * @param array $overrides
   *   Optional: library_type, library_id, collection_key.
   * @param int|null $known_version
   *   Bibliotheksversion des letzten erfolgreichen Exports. Hat sich die
   *   Bibliothek seitdem nicht geändert, endet der Aufruf nach einem Request
   *   mit Status "not_modified".
   *
   * @return array
   *   - status: "ok", "not_modified" oder "error"
   *   - bibtex: der fertige BibTeX-Text (nur bei "ok")
   *   - version: Bibliotheksversion dieses Abrufs
   *   - count: Anzahl der Einträge im Text
   *   - skipped: übersprungene Einträge (Notizen, Anhänge, ohne BibTeX)
   *   - renamed: Liste umbenannter Zitierschlüssel (item_key, from, to)
   *   - message: Fehlermeldung (nur bei "error")
   */
  public function fetchBibtex(array $overrides = [], ?int $known_version = NULL): array {
    $library_type = $overrides['library_type'] ?? $this->config->get('library_type') ?: 'user';
    $library_id = $overrides['library_id'] ?? $this->config->get('library_id');
    $collection_key = $overrides['collection_key'] ?? $this->config->get('collection_key');
    $api_key = $this->config->get('api_key');

    if (empty($library_id)) {
      return $this->bibtexResult('error', ['message' => 'Keine Library ID konfiguriert.']);
    }

    $type_segment = $library_type === 'group' ? 'groups' : 'users';
    $path = "/{$type_segment}/{$library_id}";
    $path .= $collection_key ? "/collections/{$collection_key}/items/top" : '/items/top';

    $headers = ['Zotero-API-Version' => '3'];
    if (!empty($api_key)) {
      $headers['Zotero-API-Key'] = $api_key;
    }

    try {
      for ($attempt = 1; $attempt <= self::BIBTEX_MAX_ATTEMPTS; $attempt++) {
        // Phase 1: Schlüsselliste als Momentaufnahme.
        $keys_headers = $headers;
        if ($attempt === 1 && $known_version) {
          $keys_headers['If-Modified-Since-Version'] = (string) $known_version;
        }

        $response = $this->httpClient->request('GET', self::API_BASE . $path, [
          'query' => ['format' => 'keys'],
          'headers' => $keys_headers,
          'timeout' => 30,
        ]);

        if ($response->getStatusCode() === 304) {
          return $this->bibtexResult('not_modified', ['version' => (int) $known_version]);
        }

        $version = (int) $response->getHeaderLine('Last-Modified-Version');
        $keys = array_values(array_filter(
          array_map('trim', explode("\n", (string) $response->getBody())),
          fn (string $key) => $key !== ''
        ));
        // Feste Reihenfolge, damit die Abfolge der Requests reproduzierbar ist.
        sort($keys, SORT_STRING);

        // Phase 2: Einträge samt BibTeX in Paketen holen.
        $items = [];
        $stable = TRUE;

        foreach (array_chunk($keys, self::BIBTEX_CHUNK_SIZE) as $chunk) {
          $response = $this->httpClient->request('GET', self::API_BASE . $path, [
            'query' => [
              'format' => 'json',
              'include' => 'data,bibtex',
              'itemKey' => implode(',', $chunk),
              'limit' => self::BIBTEX_CHUNK_SIZE,
            ],
            'headers' => $headers,
            'timeout' => 30,
          ]);

          // Hat sich die Bibliothek zwischen den Requests geändert? Dann neu beginnen.
          if ((int) $response->getHeaderLine('Last-Modified-Version') !== $version) {
            $stable = FALSE;
            break;
          }

          $page = json_decode((string) $response->getBody(), TRUE);
          if (!is_array($page)) {
            $this->logger->error('Zotero BibTeX-Abruf: Antwort für @path ist kein gültiges JSON.', ['@path' => $path]);
            return $this->bibtexResult('error', ['message' => 'Ungültige Antwort der Zotero API (kein JSON).']);
          }

          foreach ($page as $item) {
            if (isset($item['key'])) {
              $items[(string) $item['key']] = $item;
            }
          }

          // Zotero bittet bei Überlastung um eine Pause.
          if ($response->hasHeader('Backoff')) {
            $this->pause(min((int) $response->getHeaderLine('Backoff'), 30));
          }
        }

        if ($stable && count($items) === count($keys)) {
          return $this->buildBibtexResult($items, $version);
        }
      }
    }
    catch (GuzzleException $e) {
      $this->logger->error('Zotero BibTeX-Abruf fehlgeschlagen (@path): @details', [
        '@path' => $path,
        '@details' => $this->describeException($e),
      ]);
      return $this->bibtexResult('error', ['message' => $this->describeException($e)]);
    }

    $this->logger->warning('Zotero BibTeX-Abruf: Bibliothek war nach @count Versuchen nicht konsistent abrufbar.', ['@count' => self::BIBTEX_MAX_ATTEMPTS]);
    return $this->bibtexResult('error', ['message' => 'Die Bibliothek hat sich während des Abrufs mehrfach geändert oder Zotero lieferte unvollständige Daten. Bitte später erneut versuchen.']);
  }

  /**
   * Wandelt die geholten Items in den fertigen BibTeX-Text um.
   */
  protected function buildBibtexResult(array $items, int $version): array {
    $entries = [];
    $skipped = 0;
    $eligible = 0;
    $with_field = 0;

    foreach ($items as $item) {
      $type = $item['data']['itemType'] ?? '';
      if (in_array($type, ['attachment', 'note', 'annotation'], TRUE)) {
        $skipped++;
        continue;
      }

      $eligible++;
      $raw = $item['bibtex'] ?? ($item['data']['bibtex'] ?? NULL);
      if ($raw !== NULL) {
        $with_field++;
      }

      $bibtex = trim((string) $raw);
      if ($bibtex === '') {
        $skipped++;
        continue;
      }

      $entries[] = [
        'item_key' => (string) $item['key'],
        'date_added' => (string) ($item['data']['dateAdded'] ?? ''),
        'bibtex' => $bibtex,
      ];
    }

    if ($eligible > 0 && $with_field === 0) {
      $this->logger->error('Zotero BibTeX-Abruf: Die API-Antwort enthält kein Feld "bibtex".');
      return $this->bibtexResult('error', ['message' => 'Die Zotero-Antwort enthält kein Feld „bibtex“. Bitte die API-Antwort mit curl prüfen (include=data,bibtex).']);
    }

    $unique = $this->ensureUniqueCiteKeys($entries);
    $entries = $unique['entries'];
    $skipped += $unique['invalid'];

    if ($unique['renamed']) {
      $this->logger->notice('Zotero BibTeX: @count doppelte Zitierschlüssel wurden eindeutig gemacht.', ['@count' => count($unique['renamed'])]);
    }

    $bibtex = $entries ? implode("\n\n", array_column($entries, 'bibtex')) . "\n" : '';

    return $this->bibtexResult('ok', [
      'bibtex' => $bibtex,
      'version' => $version,
      'count' => count($entries),
      'skipped' => $skipped,
      'renamed' => $unique['renamed'],
    ]);
  }

  /**
   * Macht alle Zitierschlüssel eindeutig und sortiert die Einträge.
   *
   * Reihenfolge der Vergabe: dateAdded, bei Gleichstand der Zotero-Schlüssel.
   * Der älteste Eintrag behält seinen Schlüssel, spätere Duplikate bekommen
   * ein Suffix (a, b, ... z, aa, ab, ...). Dadurch bleiben bestehende Schlüssel
   * stabil, wenn neue Einträge hinzukommen. Die Groß-/Kleinschreibung wird beim
   * Vergleich ignoriert. Ein Suffix übernimmt nie einen Schlüssel, den ein
   * anderer Eintrag ursprünglich schon hatte.
   *
   * Das Ergebnis ist alphabetisch nach Schlüssel sortiert (ohne Beachtung der
   * Groß-/Kleinschreibung), unabhängig von der Reihenfolge der API-Antwort.
   */
  protected function ensureUniqueCiteKeys(array $entries): array {
    usort($entries, fn (array $a, array $b) => strcmp($a['date_added'], $b['date_added']) ?: strcmp($a['item_key'], $b['item_key']));

    $valid = [];
    $invalid = 0;
    foreach ($entries as $entry) {
      if (!preg_match(self::CITE_KEY_PATTERN, $entry['bibtex'], $match)) {
        $invalid++;
        $this->logger->warning('Zotero BibTeX: Eintrag @key hat kein erkennbares BibTeX-Format und wird übersprungen.', ['@key' => $entry['item_key']]);
        continue;
      }
      $entry['text_key'] = $match[1];
      $entry['original_key'] = $match[1] !== '' ? $match[1] : 'zotero_' . $entry['item_key'];
      $valid[] = $entry;
    }

    // Durchgang 1: Der erste Eintrag je Schlüssel behält ihn und reserviert ihn.
    $reserved = [];
    foreach ($valid as $i => $entry) {
      $lower = strtolower($entry['original_key']);
      if (!isset($reserved[$lower])) {
        $reserved[$lower] = TRUE;
        $valid[$i]['final_key'] = $entry['original_key'];
      }
    }

    // Durchgang 2: Duplikate bekommen das erste freie Suffix.
    foreach ($valid as $i => $entry) {
      if (isset($entry['final_key'])) {
        continue;
      }
      $suffix = 'a';
      $candidate = $entry['original_key'] . $suffix;
      while (isset($reserved[strtolower($candidate)])) {
        $suffix = $this->nextSuffix($suffix);
        $candidate = $entry['original_key'] . $suffix;
      }
      $reserved[strtolower($candidate)] = TRUE;
      $valid[$i]['final_key'] = $candidate;
    }

    // Schlüssel im BibTeX-Text ersetzen, wo er sich geändert hat.
    $renamed = [];
    foreach ($valid as $i => $entry) {
      if ($entry['final_key'] !== $entry['text_key']) {
        $valid[$i]['bibtex'] = $this->replaceCiteKey($entry['bibtex'], $entry['final_key']);
        $renamed[] = [
          'item_key' => $entry['item_key'],
          'from' => $entry['text_key'],
          'to' => $entry['final_key'],
        ];
      }
    }

    usort($valid, fn (array $a, array $b) => strcasecmp($a['final_key'], $b['final_key']) ?: strcmp($a['item_key'], $b['item_key']));

    return ['entries' => $valid, 'renamed' => $renamed, 'invalid' => $invalid];
  }

  /**
   * Zählt ein Suffix hoch: a, b, ... z, aa, ab, ... az, ba, ... zz, aaa.
   */
  protected function nextSuffix(string $suffix): string {
    for ($i = strlen($suffix) - 1; $i >= 0; $i--) {
      if ($suffix[$i] !== 'z') {
        $suffix[$i] = chr(ord($suffix[$i]) + 1);
        return $suffix;
      }
      $suffix[$i] = 'a';
    }
    return 'a' . $suffix;
  }

  /**
   * Ersetzt den Zitierschlüssel im ersten Eintrag eines BibTeX-Textes.
   */
  protected function replaceCiteKey(string $bibtex, string $new_key): string {
    return preg_replace_callback(
      self::CITE_KEY_REPLACE_PATTERN,
      fn (array $m) => $m[1] . $new_key . $m[2],
      $bibtex,
      1
    );
  }

  protected function bibtexResult(string $status, array $values = []): array {
    return ['status' => $status] + $values + [
      'bibtex' => '',
      'version' => 0,
      'count' => 0,
      'skipped' => 0,
      'renamed' => [],
      'message' => '',
    ];
  }

  /**
   * Wartet die angegebene Zeit (eigene Methode, damit Tests sie ersetzen können).
   */
  protected function pause(int $seconds): void {
    if ($seconds > 0) {
      sleep($seconds);
    }
  }
}