<?php

/**
 * @file
 * Perché un estratto di delibera non si scarica.
 *
 *   drush php:script web/modules/custom/psiphos/tests/manuale/diagnosi_estratti.php
 *
 * Non modifica nulla. Riferisce, per ogni delibera conclusa, se l'atto è
 * sigillato, se il documento esiste come entità, se il file è sul disco ed è
 * leggibile: sono quattro condizioni distinte che da fuori si presentano
 * tutte come una pagina non trovata.
 */

declare(strict_types=1);

$gestoreEntita = \Drupal::entityTypeManager();
$sistemaFile = \Drupal::service('file_system');

echo "Rotte\n";
$fornitore = \Drupal::service('router.route_provider');
foreach (['psiphos.delibera.documento', 'psiphos.delibera.esporta', 'psiphos.delibera.atto'] as $nome) {
  try {
    printf("  %-28s %s\n", $nome, $fornitore->getRouteByName($nome)->getPath());
  }
  catch (\Throwable) {
    printf("  %-28s ASSENTE — svuotare la cache: drush cr\n", $nome);
  }
}

echo "\nCartella dei file riservati\n";
foreach (['private://psiphos/verbali', 'private://psiphos/delibere'] as $cartella) {
  $percorso = $sistemaFile->realpath($cartella);
  printf(
    "  %-28s %s\n",
    $cartella,
    $percorso === FALSE
      ? 'percorso non risolvibile — il file system riservato non è configurato'
      : sprintf('%s (%s)', $percorso, is_dir($percorso) ? (is_readable($percorso) ? 'leggibile' : 'NON LEGGIBILE') : 'assente')
  );
}

$archivio = $gestoreEntita->getStorage('psiphos_delibera');
$identificativi = $archivio->getQuery()->accessCheck(FALSE)->condition('stato', 'chiusa')->sort('id')->execute();

echo "\nDelibere concluse: ", count($identificativi), "\n";

foreach ($archivio->loadMultiple($identificativi) as $delibera) {
  $riferimento = $delibera->get('documento')->target_id;
  $file = $delibera->get('documento')->entity;
  $percorso = $file === NULL ? FALSE : $sistemaFile->realpath($file->getFileUri());

  $stato = match (TRUE) {
    !$delibera->attoSigillato() => 'atto non sigillato — sigillare il verbale della seduta',
    $riferimento === NULL => 'sigillato ma senza documento — anomalia, risigillare non è possibile',
    $file === NULL => sprintf('il file %s non esiste più — ripristinare dalla copia di sicurezza', $riferimento),
    $percorso === FALSE || !is_readable($percorso) => sprintf('file non leggibile in %s — verificare i permessi', $file->getFileUri()),
    default => sprintf('scaricabile (%d byte, %s)', (int) filesize($percorso), (string) $delibera->get('formato')->value),
  };

  printf(
    "  delibera %-5s n. %-6s %s\n",
    $delibera->id(),
    trim((string) $delibera->get('numero_delibera')->value) ?: '—',
    $stato
  );
}

echo "\nSe l'elenco è vuoto ma nel sito le delibere esistono, la seduta non è ancora chiusa.\n";
