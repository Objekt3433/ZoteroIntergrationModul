# Zotero Integration

Drupal-Modul, das eine Zotero-Bibliothek (persönlich oder Gruppe) über die offizielle Zotero Web API v3 einbindet. Es kann

- die Literaturliste als **Block** anzeigen und
- die Bibliothek als **BibTeX** abrufen, zum Beispiel für den Import durch ein anderes Modul.

## Status

| Funktion | Stand |
| Block mit Literaturliste, Collection pro Block wählbar | fertig |
| Verbindungstest im Backend | fertig |
| Caching mit Versionsprüfung (nur bei Änderungen neu laden) | fertig |
| BibTeX abrufen: sortiert, eindeutige Zitierschlüssel, auch für große Bibliotheken | fertig |
| BibTeX testweise herunterladen (ohne etwas zu speichern) | fertig |
| BibTeX-Datei automatisch schreiben (Drush/Cron) und an ein anderes Modul übergeben | geplant |

**Wichtig:** Der Betriebsmodus „Nur BibTeX-Datei erzeugen“ blendet derzeit den Block aus und speichert Zielverzeichnis und Dateiname. Die Datei wird noch **nicht** automatisch geschrieben. Bis dahin steht der Testdownload zur Verfügung (siehe [BibTeX-Export](#bibtex-export)).

## Voraussetzungen

- Drupal 11. Die `info.yml` erlaubt `^10 || ^11`, entwickelt und getestet wurde aber nur mit Drupal 11, Drupal 10 ist ungetestet.
- Die PHP-Version, die Drupal 11 verlangt (8.3 oder neuer)
- Core-Modul **Block**
- Eine Zotero-Bibliothek, für private Bibliotheken zusätzlich ein API-Key
- Ausgehende HTTPS-Verbindungen vom Server zu `api.zotero.org`
- Nur für den BibTeX-Modus mit `private://`: ein privater Dateipfad in der `settings.php` (siehe [Fehlersuche](#fehlersuche))

## Installation

1. Den Ordner `zotero_integration` nach `web/modules/custom/` kopieren. 
2. Modul aktivieren:
   ```bash
   drush en zotero_integration -y
   ```
   (oder im Backend unter „Erweitern“)
3. Cache leeren:
   ```bash
   drush cr
   ```

Die Standardwerte in `config/install/` werden nur beim **Installieren** des Moduls eingelesen. Läuft das Modul schon und kommen neue Einstellungen hinzu, einmal das Einstellungsformular speichern. Bis dahin verwendet der Code eigene Fallbacks.

## Konfiguration

**Konfiguration → Web-Dienste → Zotero Integration** (`/admin/config/services/zotero-integration`). Zugriff hat, wer die Berechtigung „Administer site configuration“ (`administer site configuration`) besitzt.

### Felder

- **Betriebsmodus**
  - *Literaturliste als Block anzeigen*: der Block ist aktiv (Standard).
  - *Nur BibTeX-Datei erzeugen*: der Block wird nicht mehr angezeigt, auch wenn er platziert ist.
- **BibTeX-Datei** (nur sichtbar im Modus „BibTeX“)
  - *Zielverzeichnis*: muss mit `private://` oder `public://` beginnen und darf kein `..` enthalten. Standard: `private://zotero`. Das Verzeichnis wird beim Speichern angelegt, falls es fehlt.
  - *Dateiname*: nur Buchstaben, Ziffern, `.`, `_` und `-`, muss auf `.bib` enden. Standard: `library.bib`.
- **Bibliothekstyp**: `user` (persönliche Bibliothek) oder `group` (Gruppenbibliothek).
- **Library ID**: die numerische ID.
  - User: die `userID` unter [zotero.org/settings/keys](https://www.zotero.org/settings/keys).
  - Gruppe: die Zahl in der Gruppen-URL (`zotero.org/groups/<ID>/...`).
- **Collection Key** (optional): beschränkt Abfragen auf eine Collection. Der Wert gilt für den Verbindungstest, den BibTeX-Export und für Blöcke, in denen keine eigene Collection gewählt ist.
- **API Key** (optional): nur für private Bibliotheken und Gruppen nötig, erstellbar unter [zotero.org/settings/keys](https://www.zotero.org/settings/keys). Der Key braucht Lesezugriff auf genau diese Bibliothek bzw. Gruppe. Öffentliche Bibliotheken funktionieren ohne Key.
- **CSL-Zitierstil** und **Locale**: bestimmen, wie Zotero die Einträge im Block formatiert (Standard: `din-1505-2` und `de-DE`). Stil-IDs stehen im [Zotero Style Repository](https://www.zotero.org/styles).
- **Maximale Anzahl Einträge**: 1 bis 100 (Standard: 25), gilt für den Block. Angezeigt werden die zuletzt geänderten Einträge zuerst. Mehr als 100 Einträge im Block sind noch nicht möglich (siehe [Roadmap](#roadmap)).
- **Cache-Dauer** (Sekunden, Standard: 3600): so lange wird den gespeicherten Daten vertraut, bevor bei Zotero nach Änderungen gefragt wird (siehe [Caching](#caching)).

### Buttons

- **Konfiguration speichern**: speichert die Einstellungen und leert den Zotero-Cache.
- **Speichern und Verbindung testen**: speichert und führt danach eine echte, ungecachte Abfrage aus. Angezeigt werden:
  - eine Statusmeldung mit der Zahl der Einträge (nur oberste Ebene, ohne Anhänge und Notizen) bzw. bei einem Fehler der HTTP-Status mit einer Erklärung,
  - die Namen der Collections, wenn kein Collection Key gesetzt ist,
  - ein Bereich „Ergebnis des Verbindungstests“ mit den **ungefilterten Rohdaten** von bis zu 100 Einträgen (`data`), zum Debuggen.
- **BibTeX testweise herunterladen**: siehe [BibTeX-Export](#bibtex-export).

## Block platzieren

1. **Struktur → Block-Layout**
2. Den Block **„Zotero Bibliografie“** (Kategorie „Zotero“) einer Region zuweisen.
3. Optional: eine eigene Überschrift eintragen.
4. Optional: eine **Collection** wählen. Dann zeigt dieser Block nur deren Einträge. Das Dropdown wird aus deiner Zotero-Bibliothek geladen, Unter-Collections erscheinen eingerückt. Ohne Auswahl gilt der Collection Key aus den globalen Einstellungen, ist auch der leer, werden alle Einträge angezeigt.

Pro Eintrag zeigt der Block den formatierten Literaturverzeichnis-Eintrag (`bib`). Fehlt er, wird die Kurz-Zitation (`citation`) und zuletzt der Titel verwendet. Hat der Eintrag eine URL, folgt dahinter ein Link (↗). Es werden nur Einträge der obersten Ebene angezeigt, also keine Anhänge oder Notizen.

Im Modus „BibTeX“ ist der Block nicht sichtbar.

## BibTeX-Export

Die Methode `ZoteroApiClient::fetchBibtex()` holt die Bibliothek als BibTeX-Text.

### Ablauf

1. **Schlüsselliste:** Alle Schlüssel der Einträge der obersten Ebene werden in einem Request geholt. Das ist eine Momentaufnahme der Bibliothek.
2. **Pakete:** Die Einträge werden in Paketen zu je 50 Schlüsseln samt BibTeX abgefragt.
3. **Prüfung:** Die Bibliotheksversion muss in allen Antworten gleich sein, und es müssen alle Einträge der Momentaufnahme angekommen sein. Sonst beginnt der Abruf von vorn, maximal 3 Versuche.

Damit hängt das Ergebnis weder von der Sortierung noch von der Seitenaufteilung der Zotero-API ab und ist auch für größere Bibliotheken zuverlässig. Bei 500 Einträgen sind es 11 Requests (1 + 10). Bei Überlastung beachtet das Modul den Zotero-Header `Backoff`.

### Sortierung und Zitierschlüssel

- Die Ausgabe ist **alphabetisch nach Zitierschlüssel sortiert** (ohne Beachtung der Groß- und Kleinschreibung), unabhängig von der Reihenfolge der API-Antwort. Derselbe Bibliotheksstand ergibt immer denselben Text.
- Zitierschlüssel sind **eindeutig**:
  - Der Eintrag mit dem ältesten Hinzufügedatum (`dateAdded`, bei Gleichstand der kleinere Zotero-Schlüssel) behält seinen Schlüssel.
  - Weitere Einträge mit demselben Schlüssel bekommen ein Suffix: `a`, `b`, … `z`, `aa`, `ab`, …
  - Beim Vergleich zählt die Groß- und Kleinschreibung nicht (`Smith2020` und `smith2020` sind ein Duplikat).
  - Ein Suffix nimmt nie einen Schlüssel, den ein anderer Eintrag ursprünglich schon hatte.
  - Leere Schlüssel werden zu `zotero_<Zotero-Schlüssel>`.
- Bestehende Schlüssel bleiben stabil, wenn neue Einträge hinzukommen. Nur ein neuer Eintrag mit *älterem* Hinzufügedatum kann die Vergabe verschieben.
- Umbenennungen werden gezählt, im Ergebnis aufgelistet (`renamed`) und im Log vermerkt.

### Übersprungen werden

Notizen, Anhänge, Einträge ohne BibTeX und Einträge mit nicht erkennbarem BibTeX-Format. Sie werden gezählt (`skipped`), die letzte Gruppe zusätzlich im Log gemeldet.

### Rückgabe

`fetchBibtex(array $overrides = [], ?int $known_version = NULL)` liefert ein Array:

| Schlüssel | Bedeutung |
| --- | --- |
| `status` | `ok`, `not_modified` oder `error` |
| `bibtex` | der fertige BibTeX-Text (nur bei `ok`) |
| `version` | Bibliotheksversion dieses Abrufs |
| `count` | Zahl der Einträge im Text |
| `skipped` | Zahl der übersprungenen Einträge |
| `renamed` | Liste der umbenannten Schlüssel (`item_key`, `from`, `to`) |
| `message` | Fehlermeldung (nur bei `error`) |

Übergibst du als `$known_version` die Version des letzten erfolgreichen Exports und hat sich die Bibliothek seitdem nicht geändert, endet der Aufruf nach einem Request mit `not_modified`.

```php
/** @var \Drupal\zotero_integration\ZoteroApiClient $client */
$client = \Drupal::service('zotero_integration.api_client');
$result = $client->fetchBibtex([], $last_version);

if ($result['status'] === 'ok') {
  // $result['bibtex'] verwenden, danach $result['version'] merken.
}
```

Der Export verwendet keinen Cache.

### Testdownload

Der Button **BibTeX testweise herunterladen** holt die BibTeX und liefert sie als Datei `zotero-test.bib` an den Browser. Es wird nichts auf dem Server gespeichert.

- Der Button verwendet die **gespeicherten** Einstellungen. Speichere also zuerst, wenn du etwas geändert hast.
- Jeder Klick löst echte Abfragen bei Zotero aus.
- Bei großen Bibliotheken kann das PHP-Zeitlimit (`max_execution_time`) erreicht werden. Der spätere automatische Export läuft über Drush bzw. Cron und hat dieses Problem nicht.
- Schlägt der Abruf fehl, erscheint eine Fehlermeldung und es wird keine Datei ausgeliefert.

## Caching

Die Daten für den Block werden zusammen mit der Bibliotheksversion und dem Zeitpunkt der letzten Prüfung dauerhaft zwischengespeichert.

- Innerhalb der **Cache-Dauer** findet kein Request statt.
- Danach fragt das Modul mit dem Header `If-Modified-Since-Version` bei Zotero nach. Antwortet Zotero mit 304, hat sich nichts geändert, die gespeicherten Daten bleiben und es werden keine Daten übertragen. Sonst werden die neuen Daten geladen.
- Schlägt eine Abfrage fehl, zeigt der Block den zuletzt gespeicherten Stand statt einer leeren Liste.
- Das Speichern der Einstellungen leert den Zotero-Cache sofort.
- Änderungen in Zotero erscheinen auf der Seite nach spätestens etwa der doppelten Cache-Dauer, weil auch der Block selbst für diese Zeit zwischengespeichert wird.
- Die Collection-Liste für das Block-Dropdown wird für dieselbe Dauer zwischengespeichert.

## Fehlersuche

**Der Block ist leer.**

1. Auf **Speichern und Verbindung testen** klicken und die Meldung lesen.
2. Liegen die Einträge in einer **Gruppe**? Dann Bibliothekstyp `group` und die Gruppen-ID verwenden, nicht die User-ID.
3. Hat der API-Key Zugriff auf die Gruppe? In den Key-Einstellungen bei Zotero muss die Gruppe (oder „alle Gruppen“) mit Lesezugriff freigegeben sein.
4. Ist ein Collection Key gesetzt, global oder im Block? Eine leere Collection ergibt eine leere Liste. Ausprobieren: im Block „Alle Einträge“ wählen.
5. Sind die Einträge synchronisiert? Die API sieht nur, was online auf zotero.org liegt. Einträge, die nur in der Desktop-App stehen, fehlen.
6. Im Betriebsmodus „BibTeX“ wird der Block bewusst nicht angezeigt.
7. In den Logs nachsehen: **Berichte → Letzte Log-Meldungen** (`/admin/reports/dblog`), Typ `zotero_integration`.
8. Cache leeren: `drush cr`.

**HTTP-Fehler im Verbindungstest**

- 401 oder 403: API-Key fehlt, ist falsch oder hat keinen Lesezugriff auf diese Bibliothek.
- 404: Library ID oder Collection Key stimmt nicht.
- 429: Zotero begrenzt die Zahl der Anfragen, bitte kurz warten.

**Direkt gegen die API prüfen** (ohne Drupal), mit eigenem Key und eigener ID. Gib den Key nirgends öffentlich weiter:

```bash
# Auf welche Bibliotheken und Gruppen hat der Key Zugriff?
curl -s "https://api.zotero.org/keys/DEIN_API_KEY"

# Zahl der Einträge (Header "Total-Results") und ein Beispiel
curl -s -D - -H "Zotero-API-Key: DEIN_API_KEY" \
  "https://api.zotero.org/groups/DEINE_ID/items/top?limit=1&format=json"

# Enthält die Antwort ein BibTeX-Feld?
curl -s -H "Zotero-API-Key: DEIN_API_KEY" \
  "https://api.zotero.org/groups/DEINE_ID/items/top?limit=1&format=json&include=data,bibtex"
```

Für eine persönliche Bibliothek `groups` durch `users` ersetzen.

**Meldung „Das private Dateisystem ist nicht eingerichtet“.** Für `private://` braucht Drupal einen privaten Pfad außerhalb des Webverzeichnisses. In der `settings.php`:

```php
$settings['file_private_path'] = '../private';
```

Der Ordner muss existieren und für den Webserver beschreibbar sein. Alternativ `public://` verwenden, dann ist die Datei aber öffentlich abrufbar.

**Meldung „Die Zotero-Antwort enthält kein Feld bibtex“.** Den dritten `curl`-Aufruf oben ausführen. Steht dort kein `bibtex`-Feld auf oberster Ebene, bitte die Struktur melden, damit der Abruf angepasst werden kann.

## Sicherheit

- Für private Bibliotheken einen API-Key mit möglichst wenigen Rechten verwenden (nur Lesezugriff, nur die benötigte Bibliothek).
- Der API-Key liegt im Klartext in der Konfiguration und damit auch im Konfigurationsexport. Alternativ lässt er sich in der `settings.php` setzen: `$config['zotero_integration.settings']['api_key'] = '…';`
- Die HTML-Ausgabe von Zotero (`bib`, `citation`) wird vor der Anzeige mit `Xss::filterAdmin()` gefiltert, das Template gibt sie ohne `|raw` aus.
- Einstellungsseite, Verbindungstest und Testdownload sind nur mit der Berechtigung „Administer site configuration“ erreichbar.
- Für die BibTeX-Datei ist `private://` empfohlen, `public://` macht sie für jeden abrufbar.

## Tests

Die Unit-Tests laufen ohne installierte Drupal-Seite und ohne Datenbank, die Zotero-API wird dabei durch vorbereitete Antworten ersetzt, es gibt keine Netzwerkzugriffe.

Voraussetzung ist ein Drupal-Projekt mit PHPUnit, zum Beispiel in DDEV:

```bash
ddev composer require --dev drupal/core-dev --with-all-dependencies
```

Ausführen:

```bash
ddev exec vendor/bin/phpunit -c web/core web/modules/custom/zotero_integration/tests/src/Unit --testdox
```

Mit `--filter` lässt sich eine einzelne Klasse starten, zum Beispiel `--filter ZoteroApiClientBibtexTest`. Der Hinweis „HTML output directory … is not a writable directory“ betrifft nur Browser-Tests und ist hier unkritisch.

| Datei | Prüft |
| --- | --- |
| `ZoteroApiClientTest.php` | Items abrufen, Request-Aufbau, Cache und Versionsprüfung, Collections, Verbindungstest, Fehlermeldungen |
| `ZoteroApiClientBibtexTest.php` | BibTeX-Abruf, Sortierung, eindeutige Zitierschlüssel, Wiederholung bei Änderungen, Fehlerfälle |
| `Form/ZoteroSettingsFormTest.php` | Formularfelder, Prüfung von Verzeichnis und Dateiname, Speichern, Testdownload |
| `Plugin/Block/ZoteroBibliographyBlockTest.php` | Sichtbarkeit des Blocks je Betriebsmodus |
| `ConfigFilesTest.php` | Standardwerte und Config-Schema passen zusammen |

Noch nicht abgedeckt sind Kernel- und Browser-Tests, etwa das tatsächliche Speichern in der Datenbank oder das Rendern des Blocks.


## Roadmap

Geplant, in dieser Reihenfolge:

1. **BibTeX-Datei automatisch schreiben:** über eine temporäre Datei und anschließendes Umbenennen, damit nie eine halbe Datei gelesen wird. Die Version des letzten Exports wird in der State-API gespeichert. Ausgelöst per Drush-Befehl und Cron.
2. **Übergabe an das Modul, das aus der BibTeX die Entities erstellt:** als Datei an vereinbartem Ort, als Event oder als Service, je nach Zielmodul.
3. **Download der gespeicherten Datei** über eine eigene Route mit eigener Berechtigung.
4. **Block:** mehr als 100 Einträge (Paging), Sortierung der Ausgabe, Sortierfilter für Besucher.
5. **Kernel-Tests** für Formular speichern, Block-Rendering und Dateischreiben.
6. **Aufräumen:** statische Aufrufe wie `\Drupal::time()` und `\Drupal::config()` durch Dependency Injection ersetzen, das macht den Code leichter testbar.
