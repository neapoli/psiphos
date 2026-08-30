<?php

declare(strict_types=1);

namespace Drupal\psiphos\Enum;

/**
 * Regola di calcolo della maggioranza richiesta per l'approvazione.
 *
 * La regola incorpora anche la base di calcolo, perché tenere separati
 * «tipo di maggioranza» e «denominatore» produce combinazioni prive di
 * significato e apre la porta a delibere approvate con un criterio diverso
 * da quello indicato in convocazione. Il §4.1 chiede che l'esito sia
 * «determinato in modo corretto e verificabile»: la regola va perciò fissata
 * prima dell'apertura dell'urna e non è più modificabile dopo.
 */
enum RegolaMaggioranza: string {

  case MAGGIORANZA_RELATIVA = 'maggioranza_relativa';
  case MAGGIORANZA_VOTANTI = 'maggioranza_votanti';
  case MAGGIORANZA_PRESENTI = 'maggioranza_presenti';
  case MAGGIORANZA_AVENTI_DIRITTO = 'maggioranza_aventi_diritto';
  case DUE_TERZI_PRESENTI = 'due_terzi_presenti';
  case DUE_TERZI_AVENTI_DIRITTO = 'due_terzi_aventi_diritto';

  public function etichetta(): string {
    return match ($this) {
      self::MAGGIORANZA_RELATIVA => (string) t('Maggioranza relativa: sono proclamate le opzioni più votate'),
      self::MAGGIORANZA_VOTANTI => (string) t('Maggioranza semplice dei votanti'),
      self::MAGGIORANZA_PRESENTI => (string) t('Maggioranza assoluta dei presenti'),
      self::MAGGIORANZA_AVENTI_DIRITTO => (string) t('Maggioranza assoluta degli aventi diritto'),
      self::DUE_TERZI_PRESENTI => (string) t('Maggioranza qualificata dei due terzi dei presenti'),
      self::DUE_TERZI_AVENTI_DIRITTO => (string) t('Maggioranza qualificata dei due terzi degli aventi diritto'),
    };
  }

  public function descrizione(): string {
    return match ($this) {
      self::MAGGIORANZA_RELATIVA => (string) t('Nessuna soglia da superare: sono proclamate le opzioni più votate, fino al numero di posti da assegnare. È la regola abituale per designazioni ed elezioni; un pari merito sull\'ultimo posto richiede un ballottaggio. Utilizzabile solo sulle schede a scelta.'),
      self::MAGGIORANZA_VOTANTI => (string) t('Approvata se i voti favorevoli superano i contrari. Gli astenuti non sono computati.'),
      self::MAGGIORANZA_PRESENTI => (string) t('Approvata se i favorevoli sono almeno la metà più uno dei presenti. Gli astenuti pesano come contrari.'),
      self::MAGGIORANZA_AVENTI_DIRITTO => (string) t('Approvata se i favorevoli sono almeno la metà più uno degli aventi diritto, presenti o assenti.'),
      self::DUE_TERZI_PRESENTI => (string) t('Approvata se i favorevoli sono almeno i due terzi dei presenti, arrotondati per eccesso.'),
      self::DUE_TERZI_AVENTI_DIRITTO => (string) t('Approvata se i favorevoli sono almeno i due terzi degli aventi diritto, arrotondati per eccesso.'),
    };
  }

  /**
   * Numero minimo di voti favorevoli necessari all'approvazione.
   *
   * Restituisce NULL per le regole comparative, che non si esprimono con una
   * soglia fissa ma con un confronto fra favorevoli e contrari.
   */
  public function sogliaFavorevoli(int $presenti, int $aventiDiritto): ?int {
    return match ($this) {
      self::MAGGIORANZA_RELATIVA, self::MAGGIORANZA_VOTANTI => NULL,
      self::MAGGIORANZA_PRESENTI => intdiv($presenti, 2) + 1,
      self::MAGGIORANZA_AVENTI_DIRITTO => intdiv($aventiDiritto, 2) + 1,
      self::DUE_TERZI_PRESENTI => (int) ceil($presenti * 2 / 3),
      self::DUE_TERZI_AVENTI_DIRITTO => (int) ceil($aventiDiritto * 2 / 3),
    };
  }

  /**
   * Vero se la delibera risulta approvata con i valori di scrutinio indicati.
   */
  public function approvata(int $favorevoli, int $contrari, int $presenti, int $aventiDiritto): bool {
    // La maggioranza relativa non ha soglia: ammette alla proclamazione
    // chiunque abbia ricevuto almeno un voto, e la selezione avviene poi
    // per graduatoria sui posti disponibili.
    if ($this === self::MAGGIORANZA_RELATIVA) {
      return $favorevoli > 0;
    }

    $soglia = $this->sogliaFavorevoli($presenti, $aventiDiritto);
    return $soglia === NULL ? $favorevoli > $contrari : $favorevoli >= $soglia;
  }

  /**
   * Spiega in concreto che cosa serviva per l'approvazione.
   *
   * L'etichetta della regola dice quale criterio si applica, non quale
   * numero occorreva raggiungere. A esito proclamato è il numero che serve:
   * senza, chi presiede annuncia un risultato che non è in grado di
   * motivare davanti al collegio.
   */
  public function spiegazione(int $presenti, int $aventiDiritto, ?int $votantiConPreferenza = NULL): string {
    $soglia = $this->sogliaFavorevoli($presenti, $aventiDiritto);

    if ($this === self::MAGGIORANZA_RELATIVA) {
      return (string) t('Sono proclamate le opzioni più votate, senza soglia da superare. Un pari merito sull\'ultimo posto disponibile non si scioglie contando e richiede un ballottaggio.');
    }

    // Su una scheda a scelta il confronto avviene per votanti e non per
    // preferenze: sono contrari a un'opzione i votanti che non le hanno dato
    // la propria. Dirlo in astratto non basta a chi deve proclamare l'esito,
    // perché la soglia che ne discende non è ricavabile a occhio.
    if ($soglia === NULL && $votantiConPreferenza !== NULL) {
      return (string) t('Hanno espresso una preferenza @votanti votanti: ogni opzione doveva raccoglierne almeno @soglia, cioè più di quante ne restavano alle altre. Le schede bianche non concorrono.', [
        '@votanti' => $votantiConPreferenza,
        '@soglia' => intdiv($votantiConPreferenza, 2) + 1,
      ]);
    }

    if ($soglia === NULL) {
      return (string) t('Occorrevano più voti favorevoli che contrari.');
    }

    return (string) t('Occorrevano almeno @soglia voti favorevoli su @base.', [
      '@soglia' => $soglia,
      '@base' => match ($this) {
        self::MAGGIORANZA_PRESENTI, self::DUE_TERZI_PRESENTI => t('@numero presenti', ['@numero' => $presenti]),
        default => t('@numero aventi diritto', ['@numero' => $aventiDiritto]),
      },
    ]);
  }

  /**
   * Vero se la regola è utilizzabile solo su una scheda a scelta.
   *
   * Su una scheda di approvazione la maggioranza relativa non significa
   * nulla: non c'è una graduatoria da cui attingere, e applicarla vorrebbe
   * dire approvare qualunque proposta con un solo voto favorevole.
   */
  public function richiedeSchedaAScelta(): bool {
    return $this === self::MAGGIORANZA_RELATIVA;
  }

  /**
   * @return array<string, string>
   */
  public static function opzioni(): array {
    $opzioni = [];
    foreach (self::cases() as $caso) {
      $opzioni[$caso->value] = $caso->etichetta();
    }
    return $opzioni;
  }

}
