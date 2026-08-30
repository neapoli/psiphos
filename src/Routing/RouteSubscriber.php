<?php

declare(strict_types=1);

namespace Drupal\psiphos\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/**
 * Assegna il tema di amministrazione a tutte le pagine del modulo.
 *
 * Nessuna pagina di Psíphos è destinata al pubblico: convocazioni, aula,
 * verbali e tracciature sono atti interni, visibili solo a chi ha un ruolo
 * nella seduta. Il tema del sito serve alla comunicazione istituzionale
 * verso l'esterno e qui non ha nulla da fare.
 *
 * Le rotte sotto /admin sono già trattate come amministrative da Drupal; la
 * marcatura serve a quelle sotto /psiphos, comprese le pagine canoniche che
 * le entità generano da sé e che non sono dichiarate in psiphos.routing.yml.
 *
 * L'aula è compresa. Vi si esprime il voto, e la conformità del tema di
 * amministrazione adottato all'accessibilità resta perciò una condizione da
 * verificare: la dichiarazione di accessibilità che l'istituzione pubblica
 * ogni anno comprende espressamente le intranet.
 */
final class RouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection->all() as $nome => $rotta) {
      if (!str_starts_with($nome, 'psiphos.') && !str_starts_with($nome, 'entity.psiphos_')) {
        continue;
      }

      $rotta->setOption('_admin_route', TRUE);
    }
  }

}
