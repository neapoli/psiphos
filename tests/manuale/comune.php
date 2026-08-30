<?php

/**
 * @file
 * Strumenti condivisi dagli script di verifica.
 *
 * Il punto delicato è la pulizia. Una verifica ripetibile deve poter
 * rimuovere ciò che ha creato, ma rimuovere *tutto* è un'altra cosa: gli
 * script girano sull'ambiente di sviluppo, dove convivono con dati di lavoro
 * veri, e su un'installazione di esercizio la differenza fra le due cose è
 * la differenza fra uno strumento e un incidente.
 *
 * Perciò le sedute di prova portano un marcatore nel titolo e la pulizia
 * agisce solo su quelle e su ciò che vi dipende. Nulla di non marcato viene
 * mai toccato.
 */

declare(strict_types=1);

/**
 * Creazione e rimozione dei dati di prova.
 */
final class ProvaPsiphos {

  /**
   * Prefisso che marca una seduta come dato di prova.
   */
  public const MARCATORE = '[verifica] ';

  /**
   * Prefisso delle utenze di prova.
   */
  public const PREFISSO_UTENTI = 'psiphos_prova_';

  /**
   * Titolo marcato per una seduta di prova.
   */
  public static function titolo(string $oggetto): string {
    return self::MARCATORE . $oggetto;
  }

  /**
   * Rimuove esclusivamente i dati di prova.
   */
  public static function ripulisci(): void {
    $gestoreEntita = \Drupal::entityTypeManager();
    $database = \Drupal::database();

    $sedute = array_map('intval', $gestoreEntita->getStorage('psiphos_seduta')->getQuery()
      ->accessCheck(FALSE)
      ->condition('titolo', self::MARCATORE, 'STARTS_WITH')
      ->execute());

    if ($sedute !== []) {
      $delibere = array_map('intval', $gestoreEntita->getStorage('psiphos_delibera')->getQuery()
        ->accessCheck(FALSE)
        ->condition('seduta', $sedute, 'IN')
        ->execute());

      if ($delibere !== []) {
        foreach (['psiphos_urna', 'psiphos_attestazione', 'psiphos_voto_palese', 'psiphos_ammesso_al_voto'] as $tabella) {
          $database->delete($tabella)->condition('delibera', $delibere, 'IN')->execute();
        }
      }

      $database->delete('psiphos_audit')->condition('seduta', $sedute, 'IN')->execute();

      foreach (['psiphos_verbale', 'psiphos_delibera', 'psiphos_punto_odg', 'psiphos_presenza'] as $tipo) {
        $archivio = $gestoreEntita->getStorage($tipo);
        $identificativi = $archivio->getQuery()
          ->accessCheck(FALSE)
          ->condition('seduta', $sedute, 'IN')
          ->execute();

        foreach ($archivio->loadMultiple($identificativi) as $entita) {
          // Verbali e delibere portano il proprio documento sigillato: va
          // eliminato a parte, altrimenti resta sul disco a ogni esecuzione
          // della verifica e la cartella dei verbali si riempie in silenzio.
          if ($entita->hasField('documento')) {
            $documento = $entita->get('documento')->entity;
            if ($documento !== NULL) {
              $documento->delete();
            }
          }

          // Sempre dall'API, anche per le entità sigillate: il sigillo vieta
          // la scrittura, non la cancellazione, e delete() non passa da
          // preSave(). Cancellare la riga a mano lasciava invece l'entità
          // nella cache, e da lì continuava a caricarsi puntando a una seduta
          // che non c'era più.
          $entita->delete();
        }
      }

      // La macchina a stati protegge le sedute non convocate: per i soli dati
      // di prova lo stato viene riportato con una scrittura diretta.
      $database->update('psiphos_seduta')
        ->fields(['stato' => 'convocata'])
        ->condition('id', $sedute, 'IN')
        ->execute();

      $archivioSedute = $gestoreEntita->getStorage('psiphos_seduta');
      $archivioSedute->resetCache($sedute);
      $archivioSedute->delete($archivioSedute->loadMultiple($sedute));
    }

    $archivioUtenti = $gestoreEntita->getStorage('user');
    $archivioUtenti->delete($archivioUtenti->loadMultiple(
      $archivioUtenti->getQuery()
        ->accessCheck(FALSE)
        ->condition('name', self::PREFISSO_UTENTI, 'STARTS_WITH')
        ->execute()
    ));

    // I ruoli di prova sono più d'uno: una suite che ne lasciasse uno indietro
    // farebbe fallire la successiva sulla creazione, non su un'asserzione, e
    // il messaggio non direbbe che cosa è andato storto davvero.
    foreach (['psiphos_prova_docente', 'psiphos_prova_lettore', 'psiphos_prova_coordinatore'] as $identificativo) {
      if ($ruolo = \Drupal\user\Entity\Role::load($identificativo)) {
        $ruolo->delete();
      }
    }
  }

  /**
   * Ripristina l'utenza di partenza, qualunque sia la profondità raggiunta.
   *
   * AccountSwitcher::switchBack() solleva un'eccezione quando la pila è
   * vuota anziché restituire un valore falso: un ciclo che ne attenda la
   * fine termina con un errore proprio in coda alla verifica, quando i
   * risultati sono già stampati e la pulizia non è ancora avvenuta.
   */
  public static function ripristinaUtenza(): void {
    $commutatore = \Drupal::service('account_switcher');

    try {
      while (TRUE) {
        $commutatore->switchBack();
      }
    }
    catch (\RuntimeException) {
      // Pila esaurita: è la condizione di uscita attesa.
    }
  }

  /**
   * Impedisce l'esecuzione dove esistono atti amministrativi veri.
   *
   * Le verifiche creano ruoli, sigillano verbali e — «verifica_conformita» in
   * particolare — modificano la configurazione del modulo, ripristinandola
   * alla fine: se si interrompono a metà, quella configurazione resta
   * alterata. Sull'ambiente di sviluppo è un inconveniente; su un sito che
   * conserva verbali sigillati è un incidente.
   *
   * Il criterio non è il nome del sito né una variabile d'ambiente, che si
   * copiano insieme al resto quando si clona un'installazione: è la presenza
   * di verbali sigillati che non provengano dalle prove. Un verbale sigillato
   * è un atto amministrativo, e dove ce n'è uno non si eseguono verifiche.
   *
   * Chi sa quel che fa può forzare, digitando il nome del sito come argomento
   * — lo stesso presidio di «azzera_dati.php», per la stessa ragione: chi
   * amministra più istituti lancia questi comandi dalla stessa sessione
   * cambiando solo cartella, e il nome del sito è l'unica cosa che distingue
   * una scuola dall'altra prima che il danno sia fatto.
   *
   * @param array<int, string> $argomenti
   *   Gli argomenti della riga di comando, cioè «$extra».
   */
  public static function esigiAmbienteDiProva(array $argomenti = []): void {
    $atti = self::verbaliSigillatiNonDiProva();

    if ($atti === 0) {
      self::avvisoDatiEsistenti();
      return;
    }

    $nomeSito = (string) \Drupal::config('system.site')->get('name');
    if (trim((string) ($argomenti[0] ?? '')) === $nomeSito) {
      printf(
        "  ATTENZIONE: forzata l'esecuzione su un'installazione con %d verbali sigillati.\n",
        $atti
      );
      return;
    }

    $comando = basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'verifica.php'));

    printf("\nVerifica non eseguita.\n\n");
    printf("Sito:      %s\n", $nomeSito);
    printf("Indirizzo: %s\n\n", \Drupal::request()->getSchemeAndHttpHost());
    printf(
      "Questa installazione conserva %d verbali sigillati che non provengono dalle prove.\n",
      $atti
    );
    printf("Sono atti amministrativi: le verifiche creano ruoli, sigillano verbali e\n");
    printf("modificano la configurazione del modulo, e non vanno eseguite qui.\n\n");
    printf("Se è davvero ciò che si intende fare, ripetere il comando aggiungendo il\n");
    printf("nome del sito:\n\n");
    printf("  drush php:script <percorso dello script> -- '%s'\n\n", $nomeSito);

    exit(1);
  }

  /**
   * Quanti verbali sigillati appartengono a sedute non di prova.
   */
  private static function verbaliSigillatiNonDiProva(): int {
    $gestoreEntita = \Drupal::entityTypeManager();

    $sigillati = $gestoreEntita->getStorage('psiphos_verbale')->getQuery()
      ->accessCheck(FALSE)
      ->exists('impronta_contenuto')
      ->execute();

    if ($sigillati === []) {
      return 0;
    }

    $atti = 0;
    foreach ($gestoreEntita->getStorage('psiphos_verbale')->loadMultiple($sigillati) as $verbale) {
      $seduta = $gestoreEntita->getStorage('psiphos_seduta')
        ->load((int) ($verbale->get('seduta')->target_id ?? 0));
      // Un verbale la cui seduta non esiste più non prova nulla sull'ambiente:
      // non si conta, per non impedire le verifiche su residui di prove.
      if ($seduta !== NULL && !str_starts_with((string) $seduta->label(), self::MARCATORE)) {
        $atti++;
      }
    }

    return $atti;
  }

  /**
   * Riferisce quanti dati non di prova sono presenti.
   *
   * Non è un impedimento ma un'informazione: serve a chi lancia la verifica
   * per accorgersi se sta operando su un'installazione con dati di lavoro.
   */
  public static function avvisoDatiEsistenti(): void {
    $altre = (int) \Drupal::entityTypeManager()->getStorage('psiphos_seduta')->getQuery()
      ->accessCheck(FALSE)
      // NOT STARTS_WITH non esiste fra gli operatori delle query di entità:
      // la negazione si esprime con NOT LIKE e il carattere jolly esplicito.
      ->condition('titolo', self::MARCATORE . '%', 'NOT LIKE')
      ->count()
      ->execute();

    if ($altre > 0) {
      printf(
        "  nota: sull'installazione sono presenti %d sedute non di prova, che questa verifica non tocca\n",
        $altre
      );
    }
  }

}
