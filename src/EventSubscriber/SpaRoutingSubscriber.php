<?php

namespace Drupal\bongolava_job\EventSubscriber;

use Drupal\bongolava_job\Controller\SpaController;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\EventSubscriber\HttpExceptionSubscriberBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the Vue SPA shell for client-side routes that have no Drupal entity.
 */
final class SpaRoutingSubscriber extends HttpExceptionSubscriberBase {

  /**
   * First path segment => TRUE when handled by Vue Router.
   */
  private const SPA_PREFIXES = [
    'jobs',
    'profils',
    'evenements',
    'contact',
    'faq',
    'mentions-legales',
    'privacy',
    'cookies',
    'login',
    'register',
    'mon-profil',
  ];

  public function __construct(
    private readonly SpaController $spaController,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  protected function getHandledFormats(): array {
    return ['html'];
  }

  protected static function getPriority(): int {
    return 10;
  }

  public function on404(ExceptionEvent $event): void {
    if (!$event->getThrowable() instanceof NotFoundHttpException) {
      return;
    }

    $request = $event->getRequest();
    if (!$this->isSpaPath($request)) {
      return;
    }

    if ($this->configFactory->get('system.theme')->get('default') !== 'bongolava') {
      return;
    }

    $event->setResponse($this->spaController->shell($request));
  }

  private function isSpaPath(Request $request): bool {
    $path = trim($request->getPathInfo(), '/');
    if ($path === '') {
      return FALSE;
    }

    $segment = explode('/', $path, 2)[0];
    return in_array($segment, self::SPA_PREFIXES, TRUE);
  }

}
