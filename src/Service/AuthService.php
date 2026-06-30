<?php

namespace Drupal\bongolava_job\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Session token authentication via httpOnly cookie (or Bearer header for API clients).
 */
final class AuthService {

  public const COOKIE_NAME = 'bongolava_auth';

  public function __construct(
    private readonly KeyValueExpirableFactoryInterface $keyValueExpirable,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PasswordInterface $password,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  private function getStore() {
    return $this->keyValueExpirable->get('bongolava_job.tokens');
  }

  public function extractAuthToken(): ?string {
    $request = \Drupal::request();
    $cookie = $request->cookies->get(self::COOKIE_NAME);
    if (is_string($cookie) && $cookie !== '') {
      return trim($cookie);
    }
    return $this->extractBearerFromHeader($request);
  }

  /**
   * @deprecated Use extractAuthToken() instead.
   */
  public function extractBearerToken(): ?string {
    return $this->extractAuthToken();
  }

  private function extractBearerFromHeader(Request $request): ?string {
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

    return NULL;
  }

  public function getTokenTtlSeconds(): int {
    $ttl = (int) $this->configFactory->get('bongolava_job.settings')->get('token_ttl_days');
    return $ttl > 0 ? $ttl * 86400 : 30 * 86400;
  }

  public function createAuthCookie(string $plain): Cookie {
    $request = \Drupal::request();
    return Cookie::create(
      self::COOKIE_NAME,
      $plain,
      time() + $this->getTokenTtlSeconds(),
      $this->cookiePath($request),
      NULL,
      $this->isCookieSecure($request),
      TRUE,
      FALSE,
      Cookie::SAMESITE_STRICT,
    );
  }

  public function clearAuthCookie(): Cookie {
    $request = \Drupal::request();
    return Cookie::create(
      self::COOKIE_NAME,
      '',
      1,
      $this->cookiePath($request),
      NULL,
      $this->isCookieSecure($request),
      TRUE,
      FALSE,
      Cookie::SAMESITE_STRICT,
    );
  }

  private function isCookieSecure(Request $request): bool {
    $mode = $this->configFactory->get('bongolava_job.settings')->get('cookie_secure_mode') ?? 'auto';
    if ($mode === 'secure') {
      return TRUE;
    }
    if ($mode === 'insecure') {
      return FALSE;
    }
    return $request->isSecure();
  }

  private function cookiePath(Request $request): string {
    $base = $request->getBasePath();
    return $base === '' ? '/' : $base;
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
    $ttlSeconds = $this->getTokenTtlSeconds();
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
      if ($user->hasRole('partenaire')) {
        return 'partenaire';
      }
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
