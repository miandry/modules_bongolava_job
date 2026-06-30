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
final class JobApiController extends ApiControllerBase
{

  public function __construct(
    ApiResponseFactory $api,
    AuthService $auth,
    private readonly JobRepository $jobs,
  ) {
    parent::__construct($api, $auth);
  }

  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('bongolava_job.api_response'),
      $container->get('bongolava_job.auth'),
      $container->get('bongolava_job.job_repository'),
    );
  }

  public function list(Request $request)
  {
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

  public function get(int $id)
  {
    $job = $this->jobs->load($id, TRUE);
    return $job ? $this->api->ok($job) : $this->api->notFound();
  }

  public function createJob(Request $request)
  {
    $user = $this->requireAuth(['recruiter', 'partenaire']);
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $body = $this->jsonBody($request);
    if (empty($body)) {
      // Support multipart/form-data for image upload.
      $body = $request->request->all();
    }

    // Validate required fields
    $errors = [];
    if (empty($body['title'])) {
      $errors['title'] = ['The title field is required.'];
    } elseif (strlen(trim($body['title'])) < 3) {
      $errors['title'] = ['The title must be at least 3 characters.'];
    }

    if (empty($body['description'])) {
      $errors['description'] = ['The description field is required.'];
    } elseif (strlen(trim($body['description'])) < 10) {
      $errors['description'] = ['The description must be at least 10 characters.'];
    }

    if (empty($body['location'])) {
      $errors['location'] = ['The location field is required.'];
    }

    // Validate email if provided
    if (!empty($body['contact_email']) && !filter_var($body['contact_email'], FILTER_VALIDATE_EMAIL)) {
      $errors['contact_email'] = ['The contact email must be a valid email address.'];
    }

    if (!empty($errors)) {
      return $this->api->validationError('The given data was invalid.', $errors);
    }

    // Set contact email to user email if not provided
    $body['contact_email'] = $body['contact_email'] ?? $user->getEmail();

    $role = $this->auth->getUserRole((int) $user->id());
    $userType = $role === 'partenaire' ? 'partenaire' : 'recruiter';
    $status = $role === 'partenaire' ? 'published' : 'pending';

    $image = $request->files->get('image');
    if ($image) {
      // Validate image size (<= 5MB) and extension.
      $maxBytes = 5 * 1024 * 1024;
      if ($image->getSize() !== NULL && (int) $image->getSize() > $maxBytes) {
        $errors['image'] = ['Image must be smaller than 5MB.'];
      }
      $ext = strtolower((string) $image->getClientOriginalExtension());
      $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
      if ($ext === '' || !in_array($ext, $allowedExt, TRUE)) {
        $errors['image'] = array_merge($errors['image'] ?? [], ['Invalid image format. Allowed: jpg, jpeg, png, webp, gif.']);
      }
    }
    if (!empty($errors)) {
      return $this->api->validationError('The given data was invalid.', $errors);
    }

    return $this->api->ok($this->jobs->create((int) $user->id(), $body, $image, $userType, $status), 201);
  }

  public function update(int $id, Request $request)
  {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $body = $this->jsonBody($request);

    // Validate title if provided
    $errors = [];
    if (isset($body['title']) && strlen(trim($body['title'])) < 3) {
      $errors['title'] = ['The title must be at least 3 characters.'];
    }

    // Validate description if provided
    if (isset($body['description']) && strlen(trim($body['description'])) < 10) {
      $errors['description'] = ['The description must be at least 10 characters.'];
    }

    // Validate email if provided
    if (!empty($body['contact_email']) && !filter_var($body['contact_email'], FILTER_VALIDATE_EMAIL)) {
      $errors['contact_email'] = ['The contact email must be a valid email address.'];
    }

    // Validate status if provided
    if (isset($body['status'])) {
      $allowedStatuses = ['pending', 'published', 'rejected', 'expired'];
      if (!in_array($body['status'], $allowedStatuses)) {
        $errors['status'] = ['Status invalide.'];
      }
    }

    // Validate expires_at if provided
    if (isset($body['expires_at'])) {
      $date = \DateTime::createFromFormat('Y-m-d', $body['expires_at']);
      if (!$date || $date->format('Y-m-d') !== $body['expires_at']) {
        $errors['expires_at'] = ['The expiration date must be a valid date in YYYY-MM-DD format.'];
      } else {
        $today = new \DateTime();
        $today->setTime(0, 0, 0);
        if ($date < $today) {
          $errors['expires_at'] = ['The expiration date must be in the future.'];
        }
      }
    }

    if (!empty($errors)) {
      return $this->api->validationError('The given data was invalid.', $errors);
    }

    $job = $this->jobs->update($id, (int) $user->id(), $body);
    return $job ? $this->api->ok($job) : $this->api->notFound();
  }

  public function delete(int $id)
  {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    return $this->jobs->delete($id, (int) $user->id())
      ? $this->api->message('Job deleted successfully.')
      : $this->api->notFound();
  }

  public function myJobs(Request $request)
  {
    $user = $this->requireAuth(['recruiter', 'partenaire']);
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }

    // Get all recruiter jobs
    $allJobs = $this->jobs->listByRecruiter((int) $user->id());

    // Apply filters client-side (keyword, sector, contract_type, location)
    $filters = $request->query->all();
    $filtered = $allJobs;

    if (!empty($filters['keyword'])) {
      $keyword = strtolower($filters['keyword']);
      $filtered = array_filter($filtered, function ($job) use ($keyword) {
        return stripos($job['title'], $keyword) !== FALSE ||
          stripos($job['company'] ?? '', $keyword) !== FALSE;
      });
    }

    if (!empty($filters['sector'])) {
      $filtered = array_filter($filtered, function ($job) use ($filters) {
        return $job['sector'] === $filters['sector'];
      });
    }

    if (!empty($filters['contract_type'])) {
      $filtered = array_filter($filtered, function ($job) use ($filters) {
        return $job['contract_type'] === $filters['contract_type'];
      });
    }

    if (!empty($filters['location'])) {
      $filtered = array_filter($filtered, function ($job) use ($filters) {
        return $job['location'] === $filters['location'];
      });
    }

    if (!empty($filters['offer_status'])) {
      $filtered = array_filter($filtered, function ($job) use ($filters) {
        return $job['status'] === $filters['offer_status'];
      });
    }

    return $this->api->ok(array_values($filtered));
  }
}
