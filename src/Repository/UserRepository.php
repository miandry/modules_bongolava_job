<?php

namespace Drupal\bongolava_job\Repository;

use Drupal\bongolava_job\Service\ApiSerializer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;

/**
 * User repository using Drupal user entity and roles.
 */
final class UserRepository {

  public function __construct(
    private readonly UserDataInterface $userData,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ApiSerializer $serializer,
  ) {}

  public function ensureMeta(int $uid, string $role, array $fields = []): void {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      return;
    }
    if ($role === 'recruiter') {
      if (!$user->hasRole('recruiter')) {
        $user->addRole('recruiter');
      }
    }
    elseif ($role === 'candidate') {
      if (!$user->hasRole('candidate')) {
        $user->addRole('candidate');
      }
    }
    if (!empty($fields['phone'])) {
      $this->userData->set('bongolava_job', $uid, 'phone', $fields['phone']);
    }
    $user->save();
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

  public function updateStatus(int $uid, string $status): ?array {
    $user = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$user instanceof UserInterface) {
      return NULL;
    }
    if ($status === '0' || $status === 0) {
      $user->block();
    }
    else {
      $user->activate();
    }
    $user->save();
    return $this->serializer->user($user);
  }

  public function listAll(): array {
    $storage = $this->entityTypeManager->getStorage('user');
    // Load users with candidate or recruiter roles.
    $uids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('uid', 0, '>')
      ->condition('roles', ['candidate', 'recruiter'], 'IN')
      ->sort('created', 'DESC')
      ->execute();

    $items = [];
    foreach ($storage->loadMultiple($uids) as $user) {
      if ($user instanceof UserInterface) {
        $items[] = $this->serializer->user($user);
      }
    }
    return $items;
  }

  public function emailExists(string $email): bool {
    $users = $this->entityTypeManager->getStorage('user')
      ->loadByProperties(['mail' => $email]);
    return !empty($users);
  }

}
