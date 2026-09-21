# Zotero Integration (Drupal 11)

Bindet eine Zotero-Bibliothek (User oder Group) über die offizielle Zotero Web API v3 ein.

## Installation

1. Modulordner `zotero_integration` nach `web/modules/custom/` kopieren.
2. Modul aktivieren:
   ```
   drush en zotero_integration -y
   ```
   (oder über Erweiterungen im Backend)

## Konfiguration

1. Gehe zu **Konfiguration → Web-Dienste → Zotero Integration**
   (`/admin/config/services/zotero-integration`)
2. Trage ein:
   - **Bibliothekstyp**: `user` (persönliche Bibliothek) oder `group` (Gruppenbibliothek)
   - **Library ID**: numerische ID – findest du bei User-Bibliotheken unter
     `zotero.org/settings/keys` (userID) bzw. bei Gruppen in der Gruppen-URL
     (`zotero.org/groups/<GROUP_ID>/...`)
   - **Collection Key** (optional): wenn nur eine Unter-Collection angezeigt werden soll
   - **API Key** (optional): nur nötig für **private** Bibliotheken/Gruppen.
     Erstellbar unter zotero.org/settings/keys mit Lese-Zugriff auf die
     entsprechende Bibliothek. Öffentliche Bibliotheken benötigen keinen Key.
   - **Anzahl Einträge** und **Cache-Dauer** nach Bedarf anpassen

## Block platzieren

1. **Struktur → Block-Layout**
2. Block **„Zotero Bibliografie"** einer Region zuweisen
3. Optional eine eigene Überschrift im Block eintragen

## Hinweise

- Die Zotero-API liefert vorformatierte Zitationen (`citation`-Feld) im
  eingestellten Standardstil. Wer einen anderen Zitierstil (APA, Chicago, …)
  möchte, kann das im `ZoteroApiClient` über den Query-Parameter `style`
  ergänzen (siehe Zotero-API-Doku: „Quick Copy"/CSL-Styles).
- Antworten werden über die Drupal Cache API zwischengespeichert
  (Standard: 1 Stunde), um Rate-Limits der Zotero-API zu schonen.
- Bei privaten Bibliotheken unbedingt einen API-Key mit möglichst
  eingeschränkten Rechten (nur Lesezugriff, nur die benötigte Bibliothek)
  verwenden.
- Das Template gibt das von Zotero gelieferte Zitations-HTML ungefiltert
  aus (`|raw`). Das ist für die offizielle Zotero-API unkritisch, sollte
  aber im Hinterkopf behalten werden, falls die Datenquelle mal geändert wird.

## Erweiterungsideen

- Eigene Formularfelder pro Block-Instanz (z. B. andere Collection je Block)
- Paging/„Mehr laden"-Button
- Volltextsuche über `ZoteroApiClient` mit `q`-Parameter
- Export-Buttons (RIS/BibTeX) über den Zotero-Endpoint `/items?format=bibtex`
