<?php

declare(strict_types=1);

namespace Drupal\fresco_recipe\EventSubscriber;

use Drupal\Core\EventSubscriber\HttpExceptionSubscriberBase;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

/**
 * Sends signed-out visitors to the login form instead of "Access denied".
 *
 * A bookmarked page such as /generator should ask an anonymous visitor to log
 * in and then return them to that page. Signed-in users who lack permission
 * still get the normal 403 page.
 */
final class AnonymousAccessDeniedSubscriber extends HttpExceptionSubscriberBase {

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly RedirectDestinationInterface $redirectDestination,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @return array<int, string>
   *   Request formats this subscriber handles.
   */
  protected function getHandledFormats(): array {
    return ['html'];
  }

  /**
   * Redirects anonymous visitors to the login form with a return destination.
   */
  public function on403(ExceptionEvent $event): void {
    if ($this->currentUser->isAuthenticated()) {
      return;
    }

    $login = Url::fromRoute('user.login', [], [
      'query' => $this->redirectDestination->getAsArray(),
      'absolute' => TRUE,
    ]);
    $event->setResponse(new RedirectResponse($login->toString()));
  }

}
