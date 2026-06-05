<?php

namespace Drupal\bongolava_job\Controller;

use Drupal\bongolava_job\Repository\JobRepository;
use Drupal\bongolava_job\Service\ApiResponseFactory;
use Drupal\bongolava_job\Service\AuthService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin job moderation endpoints.
 */
final class AdminJobApiController extends ApiControllerBase {

  public function __construct(
    ApiResponseFactory $api,
    AuthService $auth,
    private readonly JobRepository $jobs,
  ) {
    parent::__construct($api, $auth);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bongolava_job.api_response'),
      $container->get('bongolava_job.auth'),
      $container->get('bongolava_job.job_repository'),
    );
  }

  public function pending() {
    $user = $this->requireAuth('admin');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    return $this->api->ok($this->jobs->listPending());
  }

  public function approve(int $id) {
    $user = $this->requireAuth('admin');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $job = $this->jobs->approve($id, (int) $user->id());
    return $job ? $this->api->ok($job) : $this->api->notFound();
  }

  public function reject(int $id, Request $request) {
    $user = $this->requireAuth('admin');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $body = $this->jsonBody($request);
    $job = $this->jobs->reject($id, (int) $user->id(), $body['rejection_reason'] ?? NULL);
    return $job ? $this->api->ok($job) : $this->api->notFound();
  }

  public function forceExpire() {
    $user = $this->requireAuth('admin');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $count = $this->jobs->forceExpire();
    return $this->api->message('Expired jobs updated.', 200, ['expired_count' => $count]);
  }

}
