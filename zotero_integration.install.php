<?php

/**
 * @file
 * Installations- und Update-Funktionen für Zotero Integration.
 */

use Drupal\node\Entity\NodeType;

/**
 * Erstellt den Inhaltstyp für importierte Zotero-Publikationen.
 */
function zotero_integration_update_10001() {
  if (!NodeType::load('publication')) {
    NodeType::create([
      'type' => 'publication',
      'name' => 'Publikation',
      'description' => 'Aus Zotero importierte Publikationen.',
      'langcode' => 'de',
      'new_revision' => FALSE,
      'preview_mode' => 1,
      'display_submitted' => FALSE,
    ])->save();
  }
}