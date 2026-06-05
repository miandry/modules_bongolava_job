<?php

namespace Drupal\bongolava_job\Controller;

use Drupal\bongolava_job\Repository\ContactRepository;
use Drupal\bongolava_job\Service\ApiResponseFactory;
use Drupal\bongolava_job\Service\AuthService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contact form and admin messages.
 */
final class ContactApiController extends ApiControllerBase {

  public function __construct(
    ApiResponseFactory $api,
    AuthService $auth,
    private readonly ContactRepository $contacts,
  ) {
    parent::__construct($api, $auth);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bongolava_job.api_response'),
      $container->get('bongolava_job.auth'),
      $container->get('bongolava_job.contact_repository'),
    );
  }

  public function submit(Request $request) {
    $body = $this->jsonBody($request);
    $errors = [];
    foreach (['name', 'email', 'message'] as $field) {
      if (empty($body[$field])) {
        $errors[$field][] = "The {$field} field is required.";
      }
    }
    if ($errors) {
      return $this->api->validationError('The given data was invalid.', $errors);
    }
    $data = $this->contacts->create($body);
    return $this->api->ok([
      'message' => 'Message sent successfully.',
      'data' => $data,
    ], 201);
  }

  public function list() {
    $user = $this->requireAuth('admin');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    return $this->api->ok($this->contacts->listAll());
  }

  public function markRead(int $id) {
    $user = $this->requireAuth('admin');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $msg = $this->contacts->markRead($id);
    return $msg ? $this->api->ok($msg) : $this->api->notFound();
  }

  public function delete(int $id) {
    $user = $this->requireAuth('admin');
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    return $this->contacts->delete($id)
      ? $this->api->message('Message deleted successfully.')
      : $this->api->notFound();
  }

}
