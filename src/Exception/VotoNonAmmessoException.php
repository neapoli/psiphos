<?php

declare(strict_types=1);

namespace Drupal\psiphos\Exception;

/**
 * Sollevata quando una scheda non può essere accettata nell'urna.
 *
 * Il messaggio è pensato per essere mostrato al votante e non deve mai
 * rivelare il contenuto di schede altrui né lo stato dello scrutinio in
 * corso: dire «hai già votato» è lecito, dire come sta andando la votazione
 * mentre l'urna è aperta non lo è.
 */
final class VotoNonAmmessoException extends \RuntimeException {

  public static function urnaChiusa(): self {
    return new self('La votazione non è aperta: non è possibile depositare la scheda.');
  }

  public static function nonAventeDiritto(): self {
    return new self('Non risulti fra gli aventi diritto al voto per questa seduta.');
  }

  public static function nonPresente(): self {
    return new self('Per votare occorre risultare presenti in aula. Rientra nella seduta e riprova.');
  }

  public static function sopraggiunto(): self {
    return new self('La votazione era già aperta quando sei entrato in aula: potrai votare dai punti successivi.');
  }

  public static function giaVotato(): self {
    return new self('Hai già espresso il tuo voto su questa delibera.');
  }

  public static function schedaVuota(): self {
    return new self('La scheda è vuota: indica una preferenza oppure scegli la scheda bianca.');
  }

  public static function voceNonValida(string $voce): self {
    return new self(sprintf('La voce "%s" non compare sulla scheda posta ai voti.', $voce));
  }

  public static function troppePreferenze(int $espresse, int $massime): self {
    return new self(sprintf(
      'Hai indicato %d preferenze, il massimo consentito su questa scheda è %d.',
      $espresse,
      $massime
    ));
  }

  public static function schedaBiancaNonEsclusiva(): self {
    return new self('La scheda bianca non può essere combinata con altre preferenze.');
  }

}
