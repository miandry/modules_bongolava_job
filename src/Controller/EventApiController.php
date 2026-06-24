<?php

namespace Drupal\bongolava_job\Controller;

use Drupal\bongolava_job\Repository\EventRepository;
use Drupal\bongolava_job\Service\ApiResponseFactory;
use Drupal\bongolava_job\Service\AuthService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Events API.
 */
final class EventApiController extends ApiControllerBase
{

  public function __construct(
    ApiResponseFactory $api,
    AuthService $auth,
    private readonly EventRepository $events,
  ) {
    parent::__construct($api, $auth);
  }

  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('bongolava_job.api_response'),
      $container->get('bongolava_job.auth'),
      $container->get('bongolava_job.event_repository'),
    );
  }

  public function list(Request $request)
  {
    $filters = $request->query->all();
    $page = max(1, (int) $request->query->get('page', 1));
    $perPage = max(1, (int) $request->query->get('per_page', 9));
    $result = $this->events->list($filters, $page, $perPage);
    return $this->api->paginated($result['items'], $result['total'], $page, $perPage);
  }

  public function MyEventslist(Request $request)
  {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $filters = $request->query->all();
    $page = max(1, (int) $request->query->get('page', 1));
    $perPage = max(1, (int) $request->query->get('per_page', 9));
    $result = $this->events->list($filters, $page, $perPage, (int) $user->id());
    return $this->api->paginated($result['items'], $result['total'], $page, $perPage);
  }

  public function get(int $id)
  {
    $event = $this->events->load($id, TRUE);
    return $event ? $this->api->ok($event) : $this->api->notFound();
  }

  public function register(int $id, Request $request)
  {
    $body = $this->jsonBody($request);
    $errors = [];
    foreach (['name', 'email', 'phone'] as $field) {
      if (empty($body[$field])) {
        $errors[$field][] = "The {$field} field is required.";
      }
    }
    if ($errors) {
      return $this->api->validationError('The given data was invalid.', $errors);
    }
    $reg = $this->events->register($id, $body);
    if (!$reg) {
      return $this->api->validationError('Registration failed.', ['event' => ['Event full or not found.']]);
    }
    return $this->api->ok($reg, 201);
  }

  public function createEvent(Request $request)
  {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $body = $this->jsonBody($request);
    $errors = $this->validateEventBody($body);
    if ($errors) {
      return $this->api->validationError('The given data was invalid.', $errors);
    }
    return $this->api->ok($this->events->create((int) $user->id(), $body), 201);
  }

  public function update(int $id, Request $request)
  {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $isAdmin = $this->auth->isAdmin($user);
    $event = $this->events->update($id, (int) $user->id(), $this->jsonBody($request), $isAdmin);
    return $event ? $this->api->ok($event) : $this->api->notFound();
  }

  public function delete(int $id)
  {
    $user = $this->requireAuth('recruiter');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $isAdmin = $this->auth->isAdmin($user);
    return $this->events->delete($id, (int) $user->id(), $isAdmin)
      ? $this->api->message('Event deleted successfully.')
      : $this->api->notFound();
  }

  /**
   * @return array<string, array<int, string>>
   */
  private function validateEventBody(array $body): array {
    $errors = [];
    if (empty($body['title']) || strlen(trim((string) $body['title'])) < 3) {
      $errors['title'][] = 'The title field is required (min 3 characters).';
    }
    if (empty($body['description']) || strlen(trim((string) $body['description'])) < 10) {
      $errors['description'][] = 'The description field is required (min 10 characters).';
    }
    if (empty($body['date']) && empty($body['date_debut'])) {
      $errors['date'][] = 'The date field is required.';
    }
    if (empty($body['type'])) {
      $errors['type'][] = 'The event type field is required.';
    }
    if (empty($body['location'])) {
      $errors['location'][] = 'The location field is required.';
    }
    return $errors;
  }

}
