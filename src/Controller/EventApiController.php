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
final class EventApiController extends ApiControllerBase {

  public function __construct(
    ApiResponseFactory $api,
    AuthService $auth,
    private readonly EventRepository $events,
  ) {
    parent::__construct($api, $auth);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bongolava_job.api_response'),
      $container->get('bongolava_job.auth'),
      $container->get('bongolava_job.event_repository'),
    );
  }

  public function list() {
    return $this->api->ok($this->events->listAll());
  }

  public function get(int $id) {
    $event = $this->events->load($id, TRUE);
    return $event ? $this->api->ok($event) : $this->api->notFound();
  }

  public function register(int $id, Request $request) {
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

  public function createEvent(Request $request) {
    $user = $this->requireAuth('admin');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    return $this->api->ok($this->events->create((int) $user->id(), $this->jsonBody($request)), 201);
  }

  public function update(int $id, Request $request) {
    $user = $this->requireAuth('admin');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $event = $this->events->update($id, $this->jsonBody($request));
    return $event ? $this->api->ok($event) : $this->api->notFound();
  }

  public function delete(int $id) {
    $user = $this->requireAuth('admin');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    return $this->events->delete($id)
      ? $this->api->message('Event deleted successfully.')
      : $this->api->notFound();
  }

}
