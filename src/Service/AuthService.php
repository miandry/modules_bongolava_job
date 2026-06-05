<?php

namespace Drupal\bongolava_job\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\user\UserInterface;

/**
 * Bearer token authentication and login helpers.
 */
final class AuthService {

  public function __construct(
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PasswordInterface $password,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  private function getStore() {
    return $this->keyValueExpirable->get('bongolava_job.tokens');
  }

  public function extractBearerToken(): ?string {
    $request = \Drupal::request();
    $auth = $request->headers->get('Authorization');

    if (!$auth && function_exists('apache_request_headers')) {
      $all = apache_request_headers();
      if (!empty($all['Authorization'])) {
        $auth = $all['Authorization'];
      }
      elseif (!empty($all['authorization'])) {
        $auth = $all['authorization'];
      }
    }

    if (!$auth && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
      $auth = $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!$auth && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
      $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if ($auth && preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
      return trim($m[1]);
    }

    $formToken = $request->request->get('token');
    if (is_string($formToken) && $formToken !== '') {
      return trim($formToken);
    }

    $queryToken = $request->query->get('token');
    return is_string($queryToken) && $queryToken !== '' ? trim($queryToken) : NULL;
  }

  public function authenticateByToken(?string $plain): ?UserInterface {
    if ($plain === NULL || $plain === '') {
      return NULL;
    }
    $hash = hash('sha256', $plain);
    $uid = $this->getStore()->get($hash);
    if ($uid === NULL) {
      return NULL;
    }
    $user = $this->entityTypeManager->getStorage('user')->load((int) $uid);
    return ($user instanceof UserInterface && $user->isActive()) ? $user : NULL;
  }

  public function issueToken(UserInterface $user): string {
    $plain = $user->id() . '|' . bin2hex(random_bytes(32));
    $hash = hash('sha256', $plain);
    $ttl = (int) $this->configFactory->get('bongolava_job.settings')->get('token_ttl_days');
    $ttlSeconds = $ttl > 0 ? $ttl * 86400 : 30 * 86400;
    $this->getStore()->setWithExpire($hash, (int) $user->id(), $ttlSeconds);
    return $plain;
  }

  public function revokeCurrentToken(?string $plain): void {
    if ($plain === NULL || $plain === '') {
      return;
    }
    $this->getStore()->delete(hash('sha256', $plain));
  }

  public function verifyPassword(UserInterface $user, string $password): bool {
    return $this->password->check($password, $user->getPassword());
  }

  public function getUserRole(int $uid): string {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if ($user instanceof UserInterface) {
      if ($user->hasRole('recruiter')) {
        return 'recruiter';
      }
      if ($user->hasRole('administrator')) {
        return 'admin';
      }
    }
    return 'candidate';
  }

  public function isAdmin(UserInterface $user): bool {
    if ((int) $user->id() === 1) {
      return TRUE;
    }
    $role = $this->getUserRole((int) $user->id());
    if ($role === 'admin') {
      return TRUE;
    }
    return in_array('administrator', $user->getRoles(), TRUE);
  }

}
