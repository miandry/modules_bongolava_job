<?php

namespace Drupal\bongolava_job\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;

/**
 * Serializes user entities and provides utility methods for repositories.
 */
final class ApiSerializer {

  public function __construct(
    private readonly UserDataInterface $userData,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function iso(?int $timestamp): ?string {
    if ($timestamp === NULL || $timestamp <= 0) {
      return NULL;
    }
    return gmdate('Y-m-d\TH:i:s.000000\Z', $timestamp);
  }

  public function storageUrl(?string $path): ?string {
    if ($path === NULL || $path === '') {
      return NULL;
    }
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
      return $path;
    }
    $scheme = $this->configFactory->get('bongolava_job.settings')->get('api.storage_scheme') ?? 'public://bongolava_job';
    $uri = $scheme . '/' . ltrim($path, '/');
    return $this->fileUrlGenerator->generateAbsoluteString($uri);
  }

  public function user(UserInterface $account, array $extra = []): array {
    $uid = (int) $account->id();
    $phone = $this->userData->get('bongolava_job', $uid, 'phone');
    $role = 'candidate';
    if ($account->hasRole('administrator')) {
      $role = 'admin';
    }
    elseif ($account->hasRole('partenaire')) {
      $role = 'partenaire';
    }
    elseif ($account->hasRole('recruiter')) {
      $role = 'recruiter';
    }
    $status = $account->isActive() ? 'active' : 'blocked';

    return array_merge([
      'id' => $uid,
      'name' => $account->getDisplayName(),
      'email' => $account->getEmail(),
      'phone' => $phone ?: NULL,
      'role' => $role,
      'status' => $status,
      'email_verified_at' => NULL,
      'created_at' => $this->iso((int) $account->getCreatedTime()),
      'updated_at' => $this->iso((int) $account->getChangedTime()),
    ], $extra);
  }

  public function loadUserMeta(int $uid): ?object {
    $phone = $this->userData->get('bongolava_job', $uid, 'phone');
    $user = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      return NULL;
    }
    $role = 'candidate';
    if ($user->hasRole('administrator')) {
      $role = 'admin';
    }
    elseif ($user->hasRole('partenaire')) {
      $role = 'partenaire';
    }
    elseif ($user->hasRole('recruiter')) {
      $role = 'recruiter';
    }
    return (object) [
      'uid' => $uid,
      'role' => $role,
      'status' => $user->isActive() ? 'active' : 'blocked',
      'phone' => $phone ?: NULL,
      'email_verified_at' => NULL,
      'created' => $user->getCreatedTime(),
      'updated' => $user->getChangedTime(),
    ];
  }

}
