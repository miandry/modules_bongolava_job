<?php

namespace Drupal\bongolava_job\Controller;

use Drupal\bongolava_job\Repository\ApplicationRepository;
use Drupal\bongolava_job\Repository\CandidateRepository;
use Drupal\bongolava_job\Repository\JobRepository;
use Drupal\bongolava_job\Service\ApiResponseFactory;
use Drupal\bongolava_job\Service\AuthService;
use Drupal\bongolava_job\Service\FileUploadService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Job application endpoints.
 */
final class ApplicationApiController extends ApiControllerBase {

  public function __construct(
    ApiResponseFactory $api,
    AuthService $auth,
    private readonly ApplicationRepository $applications,
    private readonly JobRepository $jobs,
    private readonly CandidateRepository $candidates,
    private readonly FileUploadService $uploads,
  ) {
    parent::__construct($api, $auth);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bongolava_job.api_response'),
      $container->get('bongolava_job.auth'),
      $container->get('bongolava_job.application_repository'),
      $container->get('bongolava_job.job_repository'),
      $container->get('bongolava_job.candidate_repository'),
      $container->get('bongolava_job.file_upload'),
    );
  }

  public function candidateApplications() {
    $user = $this->requireAuth('candidate');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    return $this->api->ok($this->applications->listForCandidate((int) $user->id()));
  }

  public function recruiterApplications(Request $request) {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $filters = $request->query->all();
    $page = max(1, (int) $request->query->get('page', 1));
    $perPage = max(1, (int) $request->query->get('per_page', 20));
    $result = $this->applications->listForRecruiter((int) $user->id(), $filters, $page, $perPage);
    if ($request->query->has('page') || $request->query->has('per_page')) {
      return $this->api->paginated($result['items'], $result['total'], $page, $perPage);
    }
    return $this->api->ok($result['items']);
  }

  public function updateRecruiterApplication(int $id, Request $request) {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $app = $this->applications->updateByRecruiter($id, (int) $user->id(), $this->jsonBody($request));
    return $app ? $this->api->ok($app) : $this->api->notFound();
  }

  public function apply(int $job_id, Request $request) {
    $user = $this->requireAuth('candidate');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $job = $this->jobs->load($job_id, TRUE);
    if (!$job) {
      return $this->api->notFound('Job not found.');
    }
    if (($job['user_type'] ?? NULL) === 'partenaire') {
      return $this->api->forbidden('Applications are disabled for partner job offers.');
    }

    // Candidate must have a completed profile node before applying.
    $profile = $this->candidates->loadByUser((int) $user->id());
    if (!$profile || empty($profile['id'])) {
      return $this->api->validationError('The given data was invalid.', [
        'profil' => ['Veuillez compléter votre profil candidat avant de postuler.'],
      ]);
    }

    $body = $request->request->all();
    $cvPath = NULL;
    $file = $request->files->get('cv');
    if ($file) {
      $saved = $this->uploads->save($file, 'application-cvs', ['pdf']);
      if (isset($saved['error'])) {
        return $this->api->validationError('The given data was invalid.', ['cv' => [$saved['error']]]);
      }
      $cvPath = $saved['path'];
    }
    $data = [
      'name' => $body['name'] ?? $user->getDisplayName(),
      'email' => $body['email'] ?? $user->getEmail(),
      'phone' => $body['phone'] ?? '',
      'cover_letter' => $body['cover_letter'] ?? NULL,
      'cv_path' => $cvPath,
    ];

    // Link candidature to the candidate profile node (profil_candidat) when available.
    $data['profil_candidat_id'] = (int) $profile['id'];

    $created = $this->applications->create($job_id, (int) $user->id(), $data);
    $list = $this->applications->listForCandidate((int) $user->id());
    $latest = $list[0] ?? $created;
    return $this->api->ok($latest, 201);
  }

}
