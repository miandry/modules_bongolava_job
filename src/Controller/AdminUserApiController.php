<?php

namespace Drupal\bongolava_job\Controller;

use Drupal\bongolava_job\Repository\UserRepository;
use Drupal\bongolava_job\Service\ApiResponseFactory;
use Drupal\bongolava_job\Service\AuthService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin user management.
 */
final class AdminUserApiController extends ApiControllerBase {

  public function __construct(
    ApiResponseFactory $api,
    AuthService $auth,
    private readonly UserRepository $users,
  ) {
    parent::__construct($api, $auth);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bongolava_job.api_response'),
      $container->get('bongolava_job.auth'),
      $container->get('bongolava_job.user_repository'),
    );
  }

  public function list() {
    $user = $this->requireAuth('admin');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    return $this->api->ok($this->users->listAll());
  }

  public function updateStatus(int $id, Request $request) {
    $user = $this->requireAuth('admin');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $body = $this->jsonBody($request);
    $allowed = ['active', 'blocked', 'pending'];
    if (empty($body['status']) || !in_array($body['status'], $allowed, TRUE)) {
      return $this->api->validationError('The given data was invalid.', [
        'status' => ['Invalid status value.'],
      ]);
    }
    $updated = $this->users->updateStatus($id, $body['status']);
    return $updated ? $this->api->ok($updated) : $this->api->notFound();
  }

}
