<?php

namespace Drupal\bongolava_job\Controller;

use Drupal\bongolava_job\Repository\JobRepository;
use Drupal\bongolava_job\Service\ApiResponseFactory;
use Drupal\bongolava_job\Service\AuthService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public and recruiter job endpoints.
 */
final class JobApiController extends ApiControllerBase {

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

  public function list(Request $request) {
    $filters = $request->query->all();
    $page = max(1, (int) $request->query->get('page', 1));
    $perPage = $this->jobs->perPage();
    if ($request->query->get('per_page')) {
      $perPage = max(1, (int) $request->query->get('per_page'));
    }
    $result = $this->jobs->list($filters, $page, $perPage, TRUE);
    if ($request->query->has('page') || $request->query->has('per_page')) {
      return $this->api->paginated($result['items'], $result['total'], $page, $perPage);
    }
    return $this->api->ok($result['items']);
  }

  public function get(int $id) {
    $job = $this->jobs->load($id, TRUE);
    return $job ? $this->api->ok($job) : $this->api->notFound();
  }

  public function createJob(Request $request) {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $body = $this->jsonBody($request);
    if (empty($body['title']) || empty($body['description']) || empty($body['location'])) {
      return $this->api->validationError('The given data was invalid.', [
        'title' => empty($body['title']) ? ['The title field is required.'] : [],
      ]);
    }
    $body['contact_email'] = $body['contact_email'] ?? $user->getEmail();
    return $this->api->ok($this->jobs->create((int) $user->id(), $body), 201);
  }

  public function update(int $id, Request $request) {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $job = $this->jobs->update($id, (int) $user->id(), $this->jsonBody($request));
    return $job ? $this->api->ok($job) : $this->api->notFound();
  }

  public function delete(int $id) {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    return $this->jobs->delete($id, (int) $user->id())
      ? $this->api->message('Job deleted successfully.')
      : $this->api->notFound();
  }

  public function myJobs() {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    return $this->api->ok($this->jobs->listByRecruiter((int) $user->id()));
  }

}
