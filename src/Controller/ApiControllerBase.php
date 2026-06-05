<?php

namespace Drupal\bongolava_job\Controller;

use Drupal\bongolava_job\Service\ApiResponseFactory;
use Drupal\bongolava_job\Service\AuthService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared JSON API helpers.
 */
abstract class ApiControllerBase extends ControllerBase {

  protected ApiResponseFactory $api;
  protected AuthService $auth;

  public function __construct(ApiResponseFactory $api, AuthService $auth) {
    $this->api = $api;
    $this->auth = $auth;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bongolava_job.api_response'),
      $container->get('bongolava_job.auth'),
    );
  }

  protected function jsonBody(Request $request): array {
    $content = $request->getContent();
    if ($content === '') {
      return [];
    }
    $decoded = json_decode($content, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  protected function bearerUser(): ?UserInterface {
    return $this->auth->authenticateByToken($this->auth->extractBearerToken());
  }

  protected function requireAuth(?string $role = NULL): UserInterface|\Symfony\Component\HttpFoundation\JsonResponse {
    $user = $this->bearerUser();
    if (!$user) {
      return $this->api->unauthorized();
    }
    if ($role === 'admin' && !$this->auth->isAdmin($user)) {
      return $this->api->forbidden();
    }
    if ($role !== NULL && $role !== 'admin') {
      $userRole = $this->auth->getUserRole((int) $user->id());
      if ($userRole !== $role && !$this->auth->isAdmin($user)) {
        return $this->api->forbidden();
      }
    }
    return $user;
  }

}
