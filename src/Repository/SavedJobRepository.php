<?php

namespace Drupal\bongolava_job\Repository;

use Drupal\bongolava_job\Service\ApiSerializer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\user\UserDataInterface;

/**
 * Saved jobs repository using user.data service.
 */
final class SavedJobRepository {

  private const KEY = 'saved_jobs';

  public function __construct(
    private readonly UserDataInterface $userData,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function listForUser(int $userId): array {
    $jobIds = $this->userData->get('bongolava_job', $userId, self::KEY) ?? [];
    $items = [];
    foreach ($jobIds as $jobId) {
      $node = Node::load($jobId);
      if ($node && $node->bundle() === 'offre_emploi') {
        $items[] = $this->normalizeJob($node, $jobId);
      }
    }
    return $items;
  }

  public function save(int $userId, int $jobId): ?array {
    $jobIds = $this->userData->get('bongolava_job', $userId, self::KEY) ?? [];
    if (!in_array($jobId, $jobIds, TRUE)) {
      $jobIds[] = $jobId;
      $this->userData->set('bongolava_job', $userId, self::KEY, $jobIds);
    }
    $node = Node::load($jobId);
    if (!$node || $node->bundle() !== 'offre_emploi') {
      return NULL;
    }
    return $this->normalizeJob($node, $jobId);
  }

  public function delete(int $userId, int $savedJobId): bool {
    $jobIds = $this->userData->get('bongolava_job', $userId, self::KEY) ?? [];
    $key = array_search($savedJobId, $jobIds, TRUE);
    if ($key === FALSE) {
      return FALSE;
    }
    unset($jobIds[$key]);
    $this->userData->set('bongolava_job', $userId, self::KEY, array_values($jobIds));
    return TRUE;
  }

  private function normalizeJob(Node $node, int $jobId): array {
    $status = $node->get('field_status_offre')->value;
    if (empty($status)) {
      $status = $node->isPublished() ? 'active' : 'pending';
    }
    return [
      'id' => $jobId,
      'title' => $node->label(),
      'company' => $node->get('field_company')->value ?? '',
      'location' => $node->get('field_localisation')->entity?->label() ?? '',
      'contract_type' => $node->get('field_type_contrat')->entity?->label() ?? '',
      'sector' => $node->get('field_secteur')->entity?->label() ?? '',
      'salary' => $node->get('field_salary')->value ?? NULL,
      'status' => $status,
      'recruiter_id' => (int) $node->getOwnerId(),
    ];
  }

}
