<?php

declare(strict_types=1);

namespace Drupal\psiphos\Enum;

/**
 * Eventi registrati nelle tracciature tecniche.
 *
 * Il §2 dell'allegato tecnico chiede che sia «sempre possibile ricostruire e
 * verificare ex post il corretto svolgimento del procedimento deliberativo,
 * attraverso la disponibilità di evidenze documentali e tracciature
 * tecniche». Il verbale è l'evidenza documentale; questo registro è la
 * tracciatura tecnica, e documenta il procedimento anziché il suo esito.
 *
 * Nessun evento porta con sé il contenuto di un voto. VOTO_DEPOSITATO
 * registra che un avente diritto ha votato, esattamente come già fa il
 * registro dei votanti, e nulla di più: un'annotazione che accostasse
 * l'identità alla scheda vanificherebbe la separazione del §4.3 proprio nel
 * punto in cui la si vuole dimostrare.
 */
enum EventoAudit: string {

  case SEDUTA_CONVOCATA = 'seduta.convocata';
  case SEDUTA_APERTA = 'seduta.aperta';
  case SEDUTA_CHIUSA = 'seduta.chiusa';
  case SEDUTA_VERBALIZZATA = 'seduta.verbalizzata';
  case SEDUTA_ANNULLATA = 'seduta.annullata';

  case ELENCO_MODIFICATO = 'elenco.modificato';

  case AULA_INGRESSO = 'aula.ingresso';
  case AULA_USCITA = 'aula.uscita';
  case AULA_DECADENZA = 'aula.decadenza';
  case AULA_SESSIONE_SOSTITUITA = 'aula.sessione_sostituita';

  case DELIBERA_PREDISPOSTA = 'delibera.predisposta';
  case DELIBERA_APERTA = 'delibera.aperta';
  case DELIBERA_SOSPESA = 'delibera.sospesa';
  case DELIBERA_CHIUSA = 'delibera.chiusa';
  case DELIBERA_ANNULLATA = 'delibera.annullata';

  case VOTO_DEPOSITATO = 'voto.depositato';
  case VOTO_RIFIUTATO = 'voto.rifiutato';

  case VERBALE_BOZZA = 'verbale.bozza';
  case VERBALE_SIGILLATO = 'verbale.sigillato';

  case TRACCIATURE_TRONCATE = 'tracciature.troncate';

  public function etichetta(): string {
    return match ($this) {
      self::SEDUTA_CONVOCATA => (string) t('Seduta convocata'),
      self::SEDUTA_APERTA => (string) t('Seduta dichiarata aperta'),
      self::SEDUTA_CHIUSA => (string) t('Seduta dichiarata chiusa'),
      self::SEDUTA_VERBALIZZATA => (string) t('Seduta verbalizzata'),
      self::SEDUTA_ANNULLATA => (string) t('Seduta annullata'),
      self::ELENCO_MODIFICATO => (string) t("Elenco degli aventi diritto modificato"),
      self::AULA_INGRESSO => (string) t('Ingresso in aula'),
      self::AULA_USCITA => (string) t("Uscita dall'aula"),
      self::AULA_DECADENZA => (string) t('Presenza decaduta per inattività'),
      self::AULA_SESSIONE_SOSTITUITA => (string) t('Sessione sostituita da un altro dispositivo'),
      self::DELIBERA_PREDISPOSTA => (string) t('Delibera predisposta'),
      self::DELIBERA_APERTA => (string) t('Votazione aperta'),
      self::DELIBERA_SOSPESA => (string) t('Votazione sospesa'),
      self::DELIBERA_CHIUSA => (string) t('Votazione chiusa e scrutinata'),
      self::DELIBERA_ANNULLATA => (string) t('Votazione annullata'),
      self::VOTO_DEPOSITATO => (string) t('Scheda depositata'),
      self::VOTO_RIFIUTATO => (string) t('Deposito della scheda rifiutato'),
      self::VERBALE_BOZZA => (string) t('Bozza di verbale aperta'),
      self::VERBALE_SIGILLATO => (string) t('Verbale sigillato'),
      self::TRACCIATURE_TRONCATE => (string) t('Tracciature precedenti rimosse per scadenza dei termini di conservazione'),
    };
  }

  /**
   * Vero se l'evento segnala un'anomalia da guardare.
   */
  public function anomalia(): bool {
    return in_array($this, [
      self::VOTO_RIFIUTATO,
      self::AULA_SESSIONE_SOSTITUITA,
      self::DELIBERA_SOSPESA,
      self::DELIBERA_ANNULLATA,
      self::SEDUTA_ANNULLATA,
    ], TRUE);
  }

}
