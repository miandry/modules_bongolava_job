<?php

namespace Drupal\bongolava_job\Controller;

use Drupal\bongolava_job\Repository\CandidateRepository;
use Drupal\bongolava_job\Repository\RecruiterRepository;
use Drupal\bongolava_job\Repository\UserRepository;
use Drupal\bongolava_job\Service\ApiResponseFactory;
use Drupal\bongolava_job\Service\ApiSerializer;
use Drupal\bongolava_job\Service\AuthService;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Auth endpoints: login, logout, me, register.
 */
final class AuthApiController extends ApiControllerBase {

  public function __construct(
    ApiResponseFactory $api,
    AuthService $auth,
    private readonly UserRepository $users,
    private readonly CandidateRepository $candidates,
    private readonly RecruiterRepository $recruiters,
    private readonly ApiSerializer $serializer,
  ) {
    parent::__construct($api, $auth);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bongolava_job.api_response'),
      $container->get('bongolava_job.auth'),
      $container->get('bongolava_job.user_repository'),
      $container->get('bongolava_job.candidate_repository'),
      $container->get('bongolava_job.recruiter_repository'),
      $container->get('bongolava_job.serializer'),
    );
  }

  public function login(Request $request) {
    $body = $this->jsonBody($request);
    $errors = [];
    if (empty($body['email'])) {
      $errors['email'][] = 'The email field is required.';
    }
    if (empty($body['password'])) {
      $errors['password'][] = 'The password field is required.';
    }
    if ($errors) {
      return $this->api->validationError('The given data was invalid.', $errors);
    }
    $accounts = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail' => $body['email']]);
    $account = $accounts ? reset($accounts) : NULL;
    if (!$account || !$this->auth->verifyPassword($account, $body['password'])) {
      return $this->api->validationError('The given data was invalid.', [
        'email' => ['These credentials do not match our records.'],
      ], 401);
    }
    return $this->loginResponse($account);
  }

  public function logout() {
    $token = $this->auth->extractBearerToken();
    $this->auth->revokeCurrentToken($token);
    return $this->api->message('Logged out successfully.');
  }

  public function me() {
    $user = $this->requireAuth();
    if ($user instanceof \Symfony\Component\HttpFoundation\JsonResponse) {
      return $user;
    }
    $role = $this->auth->getUserRole((int) $user->id());
    $profile = $role === 'recruiter'
      ? $this->recruiters->loadByUser((int) $user->id())
      : $this->candidates->loadByUser((int) $user->id());
    return $this->api->ok([
      'user' => $this->serializer->user($user),
      'profile' => $profile,
      'role' => $role,
    ]);
  }

  public function registerCandidate(Request $request) {
    $body = $this->jsonBody($request);
    $errors = $this->validateRegister($body, ['email', 'password', 'first_name', 'last_name']);
    if ($errors) {
      return $this->api->validationError('The given data was invalid.', $errors);
    }
    if ($this->users->emailExists($body['email'])) {
      return $this->api->validationError('The given data was invalid.', [
        'email' => ['The email has already been taken.'],
      ]);
    }
    if (strlen($body['password']) < 8) {
      return $this->api->validationError('The given data was invalid.', [
        'password' => ['The password must be at least 8 characters.'],
      ]);
    }
    $user = User::create([
      'name' => $body['email'],
      'mail' => $body['email'],
      'pass' => $body['password'],
      'status' => 1,
    ]);
    $user->save();
    $this->users->ensureMeta((int) $user->id(), 'candidate', ['phone' => $body['phone'] ?? NULL]);
    $this->candidates->create((int) $user->id(), $body);
    return $this->loginResponse($user);
  }

  public function registerRecruiter(Request $request) {
    $body = $this->jsonBody($request);
    $errors = $this->validateRegister($body, ['email', 'password', 'organization']);
    if ($errors) {
      return $this->api->validationError('The given data was invalid.', $errors);
    }
    if ($this->users->emailExists($body['email'])) {
      return $this->api->validationError('The given data was invalid.', [
        'email' => ['The email has already been taken.'],
      ]);
    }
    $user = User::create([
      'name' => $body['email'],
      'mail' => $body['email'],
      'pass' => $body['password'],
      'status' => 1,
    ]);
    $user->save();
    $this->users->ensureMeta((int) $user->id(), 'recruiter', ['phone' => $body['phone'] ?? NULL]);
    $this->recruiters->create((int) $user->id(), $body);
    return $this->loginResponse($user);
  }

  private function loginResponse($account) {
    $role = $this->auth->getUserRole((int) $account->id());
    $profile = $role === 'recruiter'
      ? $this->recruiters->loadByUser((int) $account->id())
      : $this->candidates->loadByUser((int) $account->id());
    return $this->api->ok([
      'user' => $this->serializer->user($account),
      'profile' => $profile,
      'token' => $this->auth->issueToken($account),
      'role' => $role,
    ]);
  }

  private function validateRegister(array $body, array $required): array {
    $errors = [];
    foreach ($required as $field) {
      if (empty($body[$field])) {
        $errors[$field][] = "The {$field} field is required.";
      }
    }
    return $errors;
  }

}
