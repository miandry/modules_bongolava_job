<?php

namespace Drupal\bongolava_job\Repository;

use Drupal\bongolava_job\Service\ApiSerializer;
use Drupal\user\UserDataInterface;

/**
 * Recruiter profile repository using user.data service.
 */
final class RecruiterRepository {

  private const KEY = 'bongolava_job_recruiter';

  public function __construct(
    private readonly UserDataInterface $userData,
    private readonly ApiSerializer $serializer,
  ) {}

  public function create(int $userId, array $data): array {
    $existing = $this->userData->get('bongolava_job', $userId, self::KEY) ?? [];
    $merged = array_merge($existing, $data);
    if (empty($merged['created_at'])) {
      $merged['created_at'] = \Drupal::time()->getRequestTime();
    }
    $merged['updated_at'] = \Drupal::time()->getRequestTime();
    $this->userData->set('bongolava_job', $userId, self::KEY, $merged);
    return $this->loadByUser($userId);
  }

  public function load(int $id): ?array {
    return $this->loadByUser($id);
  }

  public function loadByUser(int $userId): ?array {
    $data = $this->userData->get('bongolava_job', $userId, self::KEY) ?? [];
    return [
      'user_id' => $userId,
      'organization' => $data['organization'] ?? '',
      'sector' => $data['sector'] ?? NULL,
      'logo_path' => $data['logo_path'] ?? NULL,
      'phone' => $data['phone'] ?? NULL,
      'address' => $data['address'] ?? NULL,
      'website' => $data['website'] ?? NULL,
      'created_at' => $this->serializer->iso((int) ($data['created_at'] ?? 0)) ,
      'updated_at' => $this->serializer->iso((int) ($data['updated_at'] ?? 0)),
    ];
  }

  public function updateByUser(int $userId, array $data): ?array {
    $existing = $this->userData->get('bongolava_job', $userId, self::KEY) ?? [];
    $merged = array_merge($existing, $data);
    $merged['updated_at'] = \Drupal::time()->getRequestTime();
    if (empty($merged['created_at'])) {
      $merged['created_at'] = \Drupal::time()->getRequestTime();
    }
    $this->userData->set('bongolava_job', $userId, self::KEY, $merged);
    return $this->loadByUser($userId);
  }

  public function updateLogo(int $userId, string $path): ?array {
    $existing = $this->userData->get('bongolava_job', $userId, self::KEY) ?? [];
    $existing['logo_path'] = $path;
    $existing['updated_at'] = \Drupal::time()->getRequestTime();
    if (empty($existing['created_at'])) {
      $existing['created_at'] = \Drupal::time()->getRequestTime();
    }
    $this->userData->set('bongolava_job', $userId, self::KEY, $existing);
    return $this->loadByUser($userId);
  }

}
