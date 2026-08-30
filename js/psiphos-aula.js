/**
 * @file
 * Aggiornamento dell'aula virtuale durante la seduta.
 *
 * La pagina interroga periodicamente lo stato della seduta e si ridisegna
 * solo quando cambia qualcosa di sostanziale. I contatori si aggiornano in
 * pagina senza ricostruire nulla: rifare la scheda di voto mentre qualcuno
 * la sta compilando gli farebbe perdere la scelta appena spuntata.
 *
 * L'interrogazione periodica è anche il segnale di presenza: dichiara che
 * l'aula è aperta su questo dispositivo. Prosegue anche a scheda nascosta,
 * perché durante una seduta chi partecipa guarda la videoconferenza e non
 * l'aula, e smettere di farsi vivi in quel momento lo farebbe decadere dal
 * computo del quorum nel bel mezzo della discussione. A scheda nascosta il
 * ritmo si dirada: serve a restare presenti, non a seguire l'aggiornamento.
 */

((Drupal, drupalSettings, once) => {
  'use strict';

  // A scheda nascosta si continua a segnalare la presenza, ma con meno
  // frequenza: nessuno sta guardando l'aggiornamento.
  const RALLENTAMENTO_IN_SECONDO_PIANO = 4;

  class Aula {
    constructor(contenitore, impostazioni) {
      this.contenitore = contenitore;
      this.impostazioni = impostazioni;
      this.firma = null;
      this.timer = null;
      this.inCorso = false;
    }

    avvia() {
      // Tornando in primo piano si interroga subito: chi rientra sull'aula
      // deve vedere lo stato aggiornato, non attendere il ciclo successivo.
      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
          this.interroga();
        }
      });

      this.programma();
    }

    programma() {
      const attesa = document.visibilityState === 'visible'
        ? this.impostazioni.intervallo
        : this.impostazioni.intervallo * RALLENTAMENTO_IN_SECONDO_PIANO;

      window.clearTimeout(this.timer);
      this.timer = window.setTimeout(() => this.interroga(), attesa);
    }

    async interroga() {
      if (this.inCorso) {
        this.programma();
        return;
      }

      this.inCorso = true;

      try {
        const risposta = await fetch(this.impostazioni.stato, {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        });

        if (risposta.ok) {
          this.applica(await risposta.json());
        }
      } catch (errore) {
        // Un'interruzione di rete non deve fermare l'aggiornamento: al
        // tentativo successivo la seduta riprende a essere seguita.
      } finally {
        this.inCorso = false;
        this.programma();
      }
    }

    applica(stato) {
      this.aggiornaContatori(stato);

      if (this.firma === null) {
        this.firma = stato.firma;
        return;
      }

      // A scheda nascosta non si ridisegna: la ricostruzione servirebbe a
      // nessuno e al rientro avviene comunque, perché la firma è cambiata.
      if (this.firma !== stato.firma && document.visibilityState === 'visible') {
        this.firma = stato.firma;
        this.ridisegna();
      }
    }

    aggiornaContatori(stato) {
      // Tutte le occorrenze, non la prima: lo stesso dato compare
      // nell'intestazione e nel banco di presidenza, e aggiornarne una sola
      // lascia in pagina due numeri che si contraddicono.
      const scrivi = (selettore, valore) => {
        this.contenitore.querySelectorAll(selettore).forEach((elemento) => {
          if (elemento.textContent !== String(valore)) {
            elemento.textContent = String(valore);
          }
        });
      };

      scrivi('[data-psiphos="presenti"]', stato.presenti);
      scrivi('[data-psiphos="aventi-diritto"]', stato.aventiDiritto);
      scrivi('[data-psiphos="votanti"]', stato.votanti);
      scrivi('[data-psiphos="presenti-al-voto"]', stato.presentiAlVoto);
      scrivi('[data-psiphos="mancanti"]', stato.mancanti);
      scrivi('[data-psiphos="quorum"]', stato.quorumEtichetta);

      // Il quorum non è solo un testo: la sua mancanza è segnalata anche
      // dallo stile, che va tolto quando viene raggiunto.
      this.contenitore.querySelectorAll('[data-psiphos="quorum"]').forEach((elemento) => {
        elemento.classList.toggle('psiphos-aula__quorum--mancante', stato.quorumInDifetto === true);
      });
    }

    ridisegna() {
      Drupal.ajax({ url: this.impostazioni.contenuto, progress: false }).execute();
    }
  }

  Drupal.behaviors.psiphosAula = {
    attach(context) {
      const impostazioni = drupalSettings.psiphos;
      if (!impostazioni) {
        return;
      }

      once('psiphos-aula', '#psiphos-aula', context).forEach((contenitore) => {
        new Aula(contenitore, impostazioni).avvia();
      });

      // La conferma prima del deposito: il voto non è più modificabile una
      // volta nell'urna, e va detto prima.
      once('psiphos-conferma', '[data-psiphos-conferma]', context).forEach((pulsante) => {
        pulsante.addEventListener('click', (evento) => {
          if (!window.confirm(pulsante.dataset.psiphosConferma)) {
            evento.preventDefault();
          }
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
