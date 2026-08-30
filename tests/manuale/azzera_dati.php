<?php

/**
 * @file
 * Azzeramento di tutti i dati Psíphos.
 *
 *   drush php:script web/modules/custom/psiphos/tests/manuale/azzera_dati.php
 *
 * Rimuove sedute, punti, delibere, presenze, verbali, urne, tracciature e i
 * documenti prodotti. Senza argomenti non cancella nulla: mostra di quale
 * sito si tratta, che cosa contiene e il comando esatto per confermare.
 *
 * La conferma è il nome del sito, e va digitato. Chi amministra più istituti
 * lancia questo comando dalla stessa sessione SSH cambiando solo cartella, e
 * una cartella sbagliata porterebbe via atti amministrativi veri: il nome del
 * sito è l'unica cosa che distingue una scuola dall'altra prima che il danno
 * sia fatto.
 */

declare(strict_types=1);

$database = \Drupal::database();
$gestoreEntita = \Drupal::entityTypeManager();
$sistemaFile = \Drupal::service('file_system');

$nomeSito = (string) \Drupal::config('system.site')->get('name');
$conferma = trim((string) ($extra[0] ?? ''));

/** Conta le righe di una tabella, zero se la tabella non esiste. */
$conta = static function (string $tabella) use ($database): int {
  return $database->schema()->tableExists($tabella)
    ? (int) $database->select($tabella, 'x')->countQuery()->execute()->fetchField()
    : 0;
};

if ($conferma !== $nomeSito) {
  printf("\nSito:      %s\n", $nomeSito);
  printf("Indirizzo: %s\n\n", \Drupal::request()->getSchemeAndHttpHost());

  $totale = 0;
  foreach ([
    'sedute' => 'psiphos_seduta',
    'delibere' => 'psiphos_delibera',
    'verbali' => 'psiphos_verbale',
    'schede nelle urne' => 'psiphos_urna',
    'tracciature' => 'psiphos_audit',
  ] as $etichetta => $tabella) {
    $quante = $conta($tabella);
    $totale += $quante;
    printf("  %-22s %d\n", $etichetta, $quante);
  }

  $sigillati = $database->schema()->tableExists('psiphos_verbale')
    ? (int) $database->select('psiphos_verbale', 'v')->condition('stato', 'sigillato')
      ->countQuery()->execute()->fetchField()
    : 0;

  if ($sigillati > 0) {
    printf(
      "\n  ATTENZIONE: %d verbali risultano sigillati. Sono atti amministrativi.\n"
      . "  Se questa non è un'installazione di prova, non proseguire.\n",
      $sigillati
    );
  }

  if ($totale === 0) {
    echo "\nNon c'è nulla da rimuovere.\n\n";
    return;
  }

  if ($conferma === '') {
    printf(
      "\nPer cancellare tutto, ripetere il comando indicando il nome del sito:\n\n"
      . "  drush php:script web/modules/custom/psiphos/tests/manuale/azzera_dati.php -- '%s'\n\n",
      $nomeSito
    );
  }
  else {
    printf("\nNessuna cancellazione: «%s» non è il nome di questo sito.\n\n", $conferma);
  }

  return;
}


// I documenti per primi: le entità che li riferiscono stanno per sparire, e
// dopo non ci sarebbe più modo di risalire ai file da rimuovere.
$rimossi = 0;
foreach (['psiphos_verbale' => 'documento', 'psiphos_delibera' => 'documento'] as $tipo => $campo) {
  foreach ($gestoreEntita->getStorage($tipo)->loadMultiple() as $entita) {
    $file = $entita->get($campo)->entity;
    if ($file !== NULL) {
      $file->delete();
      $rimossi++;
    }
  }
}

foreach (['private://psiphos/verbali', 'private://psiphos/delibere'] as $cartella) {
  $percorso = $sistemaFile->realpath($cartella);
  if ($percorso === FALSE || !is_dir($percorso)) {
    continue;
  }
  foreach ($sistemaFile->scanDirectory($cartella, '/\.pdf$/') as $uri => $informazioni) {
    $sistemaFile->delete($uri);
    $rimossi++;
  }
}

// Le entità si cancellano dall'API, anche quelle sigillate: il sigillo vieta
// la scrittura, non la cancellazione. Svuotare le tabelle a mano lascerebbe le
// entità nella cache, da dove continuerebbero a caricarsi puntando a sedute
// che non esistono più.
$entita = ['psiphos_verbale', 'psiphos_delibera', 'psiphos_punto_odg', 'psiphos_presenza', 'psiphos_seduta'];
$cancellate = [];

foreach ($entita as $tipo) {
  $archivio = $gestoreEntita->getStorage($tipo);
  $tutte = $archivio->loadMultiple();
  if ($tutte !== []) {
    $cancellate[$tipo] = count($tutte);
    $archivio->delete($tutte);
  }
}

// Le tabelle a schema semplice non hanno entità dietro: si svuotano.
$tabelle = [
  'psiphos_urna', 'psiphos_attestazione', 'psiphos_voto_palese',
  'psiphos_ammesso_al_voto', 'psiphos_audit',
];

$schema = $database->schema();
$svuotate = [];

foreach ($tabelle as $tabella) {
  foreach ([$tabella, $tabella . '__opzioni', $tabella . '__opzioni_prevalenti', $tabella . '__premesse'] as $effettiva) {
    if ($schema->tableExists($effettiva)) {
      $svuotate[$effettiva] = $conta($effettiva);
      $database->truncate($effettiva)->execute();
    }
  }
}

foreach ($gestoreEntita->getDefinitions() as $identificativo => $definizione) {
  if (str_starts_with($identificativo, 'psiphos_')) {
    $gestoreEntita->getStorage($identificativo)->resetCache();
  }
}

printf("documenti rimossi: %d\n", $rimossi);
foreach ($cancellate as $tipo => $quante) {
  printf("  %-32s %d entità\n", $tipo, $quante);
}
foreach ($svuotate as $tabella => $righe) {
  if ($righe > 0) {
    printf("  %-32s %d righe\n", $tabella, $righe);
  }
}
echo "azzeramento completato\n";
