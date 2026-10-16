# Zotero Integration (Drupal 11)

Bindet eine Zotero-Bibliothek (User oder Group) über die offizielle Zotero Web API v3 ein und stellt zur Ausgabe einen Block zur Verfügung. 

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
   - **Anzahl Einträge** gibt die Maximale Anzahl der Abgefragten Einträge an. Zotero kann in einer Anfrage maximal 100 Einträge verarbeiten. 
   - **Cache-Dauer** gibt in Sekunden an wie lange die Antworten zwischengespeichert werden. Nach Ablauf wird eine neue
      Anfrage gestellt. Standardeinstellung: 3600 Sekunden (1 Stunde). 
3. Speicher und optional Verbindung testen: 
   - Zum Testen der VErbindung auf "Speichern und Verbindung testen" geklicken. Das Modul führt eine Anfrage aus und gibt
      den HTTP-Statuscode zurück, sowie eine genauere Beschreibung der Fehlermeldung, wenn eine vorliegt. Ebenfalls wird
      eine unbgefilterte Liste aller der abgefragten Daten zum Debuggen bereitgestellt.


## Block platzieren

1. **Struktur → Block-Layout**
2. Block **„Zotero Bibliografie"** einer Region zuweisen
3. Optional: Eine eigene Überschrift im Block eintragen
4. Optional: Eine Kollektion auswählen, es werden dann nur die Einträge in der Kollektion im Block angezeigt. 

## Hinweise

- Zum Testen & Debuggen der Verbindung wird in der Konfiguration ein extra Button zur Verfügung gestellt, welcher 
  die Verbindung testet und die abgefragten Infos ungefiltert ausgibt. So können Fehler bei der Anzeige leichter gefunden werden und überprüft werden, ob die API auch korrekt abfragt. 
- Bei privaten Bibliotheken unbedingt einen API-Key mit möglichst
  eingeschränkten Rechten (nur Lesezugriff, nur die benötigte Bibliothek)
  verwenden.
- Das Template gibt das von Zotero gelieferte Zitations-HTML ungefiltert
  aus (`|raw`). Das ist für die offizielle Zotero-API unkritisch, sollte
  aber im Hinterkopf behalten werden, falls die Datenquelle mal geändert wird.

## TODOS/Roadmap: 

1. Mehr als 100 Einträge über Schleife realisieren 
2. Ausgabe im Block sortieren 
3. Sortierfilter für Endnutzer im Block bereitstellen. 
