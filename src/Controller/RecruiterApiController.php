<?php

namespace Drupal\bongolava_job\Controller;

use Drupal\bongolava_job\Repository\RecruiterRepository;
use Drupal\bongolava_job\Service\ApiResponseFactory;
use Drupal\bongolava_job\Service\AuthService;
use Drupal\bongolava_job\Service\FileUploadService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Recruiter profile endpoints.
 */
final class RecruiterApiController extends ApiControllerBase {

  public function __construct(
    ApiResponseFactory $api,
    AuthService $auth,
    private readonly RecruiterRepository $recruiters,
    private readonly FileUploadService $uploads,
  ) {
    parent::__construct($api, $auth);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bongolava_job.api_response'),
      $container->get('bongolava_job.auth'),
      $container->get('bongolava_job.recruiter_repository'),
      $container->get('bongolava_job.file_upload'),
    );
  }

  public function myProfile() {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $profile = $this->recruiters->loadByUser((int) $user->id());
    return $profile ? $this->api->ok($profile) : $this->api->notFound();
  }

  public function updateMyProfile(Request $request) {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $profile = $this->recruiters->updateByUser((int) $user->id(), $this->jsonBody($request));
    return $profile ? $this->api->ok($profile) : $this->api->notFound();
  }

  public function uploadLogo(Request $request) {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $file = $request->files->get('logo');
    if (!$file) {
      return $this->api->validationError('The given data was invalid.', ['logo' => ['Logo is required.']]);
    }
    $saved = $this->uploads->save($file, 'logos', ['jpg', 'jpeg', 'png', 'webp', 'svg']);
    if (isset($saved['error'])) {
      return $this->api->validationError('The given data was invalid.', ['logo' => [$saved['error']]]);
    }
    $this->recruiters->updateLogo((int) $user->id(), $saved['path']);
    return $this->api->ok(['logo_path' => $saved['path']]);
  }

}
