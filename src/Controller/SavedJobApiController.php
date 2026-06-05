<?php

namespace Drupal\bongolava_job\Controller;

use Drupal\bongolava_job\Repository\SavedJobRepository;
use Drupal\bongolava_job\Service\ApiResponseFactory;
use Drupal\bongolava_job\Service\AuthService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Saved jobs for candidates.
 */
final class SavedJobApiController extends ApiControllerBase {

  public function __construct(
    ApiResponseFactory $api,
    AuthService $auth,
    private readonly SavedJobRepository $savedJobs,
  ) {
    parent::__construct($api, $auth);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bongolava_job.api_response'),
      $container->get('bongolava_job.auth'),
      $container->get('bongolava_job.saved_job_repository'),
    );
  }

  public function list() {
    $user = $this->requireAuth('candidate');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    return $this->api->ok($this->savedJobs->listForUser((int) $user->id()));
  }

  public function save(int $jobId) {
    $user = $this->requireAuth('candidate');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $item = $this->savedJobs->save((int) $user->id(), $jobId);
    return $item ? $this->api->ok($item, 201) : $this->api->notFound();
  }

  public function delete(int $savedJobId) {
    $user = $this->requireAuth('candidate');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    return $this->savedJobs->delete((int) $user->id(), $savedJobId)
      ? $this->api->message('Saved job removed.')
      : $this->api->notFound();
  }

}
