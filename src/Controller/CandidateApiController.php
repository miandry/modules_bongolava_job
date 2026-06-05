<?php

namespace Drupal\bongolava_job\Controller;

use Drupal\bongolava_job\Repository\CandidateRepository;
use Drupal\bongolava_job\Service\ApiResponseFactory;
use Drupal\bongolava_job\Service\AuthService;
use Drupal\bongolava_job\Service\FileUploadService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Candidate profile and upload endpoints.
 */
final class CandidateApiController extends ApiControllerBase {

  public function __construct(
    ApiResponseFactory $api,
    AuthService $auth,
    private readonly CandidateRepository $candidates,
    private readonly FileUploadService $uploads,
    private readonly ConfigFactoryInterface $jobConfig,
  ) {
    parent::__construct($api, $auth);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bongolava_job.api_response'),
      $container->get('bongolava_job.auth'),
      $container->get('bongolava_job.candidate_repository'),
      $container->get('bongolava_job.file_upload'),
      $container->get('config.factory'),
    );
  }

  public function list(Request $request) {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $page = max(1, (int) $request->query->get('page', 1));
    $perPage = max(1, (int) $this->jobConfig->get('bongolava_job.settings')->get('candidates_per_page'));
    $result = $this->candidates->list($request->query->all(), $page, $perPage);
    if ($request->query->has('page')) {
      return $this->api->paginated($result['items'], $result['total'], $page, $perPage);
    }
    return $this->api->ok($result['items']);
  }

  public function get(int $id) {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $profile = $this->candidates->load($id);
    return $profile ? $this->api->ok($profile) : $this->api->notFound();
  }

  public function myProfile() {
    $user = $this->requireAuth('candidate');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $profile = $this->candidates->loadByUser((int) $user->id());
    return $profile ? $this->api->ok($profile) : $this->api->notFound();
  }

  public function updateMyProfile(Request $request) {
    $user = $this->requireAuth('candidate');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $profile = $this->candidates->updateByUser((int) $user->id(), $this->jsonBody($request));
    return $profile ? $this->api->ok($profile) : $this->api->notFound();
  }

  public function uploadPhoto(Request $request) {
    $user = $this->requireAuth('candidate');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $file = $request->files->get('photo');
    if (!$file) {
      return $this->api->validationError('The given data was invalid.', ['photo' => ['Photo is required.']]);
    }
    $saved = $this->uploads->save($file, 'photos', ['jpg', 'jpeg', 'png', 'webp']);
    if (isset($saved['error'])) {
      return $this->api->validationError('The given data was invalid.', ['photo' => [$saved['error']]]);
    }
    $this->candidates->updatePath((int) $user->id(), 'photo_path', $saved['path']);
    return $this->api->ok(['photo_path' => $saved['path']]);
  }

  public function uploadCv(Request $request) {
    $user = $this->requireAuth('candidate');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $file = $request->files->get('cv');
    if (!$file) {
      return $this->api->validationError('The given data was invalid.', ['cv' => ['CV is required.']]);
    }
    $saved = $this->uploads->save($file, 'cvs', ['pdf']);
    if (isset($saved['error'])) {
      return $this->api->validationError('The given data was invalid.', ['cv' => [$saved['error']]]);
    }
    $this->candidates->updatePath((int) $user->id(), 'cv_path', $saved['path']);
    return $this->api->ok(['cv_path' => $saved['path']]);
  }

  public function downloadCv() {
    $user = $this->requireAuth('candidate');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $profile = $this->candidates->loadByUser((int) $user->id());
    if (!$profile || empty($profile['cv_path'])) {
      return $this->api->notFound('CV not found.');
    }
    $scheme = $this->jobConfig->get('bongolava_job.settings')->get('api.storage_scheme') ?? 'public://bongolava_job';
    $uri = $scheme . '/' . ltrim($profile['cv_path'], '/');
    if (!file_exists($uri)) {
      return $this->api->notFound();
    }
    $response = new BinaryFileResponse($uri);
    $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($profile['cv_path']));
    return $response;
  }

  public function deleteCv() {
    $user = $this->requireAuth('candidate');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $profile = $this->candidates->loadByUser((int) $user->id());
    if ($profile && !empty($profile['cv_path'])) {
      $this->uploads->deleteRelative($profile['cv_path']);
      $this->candidates->updatePath((int) $user->id(), 'cv_path', '');
    }
    return $this->api->message('CV deleted successfully.');
  }

}
