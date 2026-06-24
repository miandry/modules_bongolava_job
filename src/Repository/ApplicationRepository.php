<?php

namespace Drupal\bongolava_job\Repository;

use Drupal\bongolava_job\Service\ApiSerializer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;

/**
 * Application repository using candidature nodes.
 */
final class ApplicationRepository {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ApiSerializer $serializer,
  ) {}

  /**
   * @param array<string, mixed> $filters
   */
  private function applyRecruiterListFilters($query, array $filters): void {
    if (!empty($filters['job_id'])) {
      $query->condition('field_offre_ref', (int) $filters['job_id']);
    }

    if (!empty($filters['status'])) {
      $query->condition('field_status_candidature', (string) $filters['status']);
    }

    $keyword = trim((string) ($filters['keyword'] ?? $filters['search'] ?? ''));
    if ($keyword !== '') {
      $or = $query->orConditionGroup()
        ->condition('field_candidat_name', '%' . $keyword . '%', 'LIKE')
        ->condition('field_candidat_email', '%' . $keyword . '%', 'LIKE')
        ->condition('field_candidat_phone', '%' . $keyword . '%', 'LIKE')
        ->condition('field_cover_letter', '%' . $keyword . '%', 'LIKE');
      $query->condition($or);
    }
  }

  public function create(int $jobId, int $candidateId, array $data): array {
    $jobNode = Node::load($jobId);
    $jobTitle = $jobNode ? $jobNode->label() : 'Job #' . $jobId;
    $node = Node::create([
      'type' => 'candidature',
      'title' => 'Candidature - ' . $jobTitle,
      'uid' => $candidateId,
      'status' => 0,
    ]);
    $node->set('field_offre_ref', ['target_id' => $jobId]);
    if (!empty($data['profil_candidat_id'])) {
      $node->set('field_profil_candidat', ['target_id' => (int) $data['profil_candidat_id']]);
    }
    $node->set('field_candidat_name', $data['name'] ?? $data['candidat_name'] ?? '');
    $node->set('field_candidat_email', $data['email'] ?? $data['candidat_email'] ?? '');
    $node->set('field_candidat_phone', $data['phone'] ?? $data['candidat_phone'] ?? NULL);
    $node->set('field_cover_letter', $data['cover_letter'] ?? NULL);
    $node->set('field_cv_file', $data['cv_file'] ?? $data['cv_path'] ?? NULL);
    $node->set('field_status_candidature', 'pending');
    $node->save();
    return $this->normalizeNode($node);
  }

  public function load(int $id): ?array {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'candidature') {
      return NULL;
    }
    return $this->normalizeNode($node);
  }

  public function listForCandidate(int $candidateId): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'candidature')
      ->condition('uid', $candidateId)
      ->sort('created', 'DESC')
      ->execute();

    $items = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $item = $this->normalizeNode($node);
      // Attach job details.
      $jobNid = $node->get('field_offre_ref')->target_id ?? NULL;
      if ($jobNid) {
        $jobNode = Node::load($jobNid);
        if ($jobNode) {
          $item['job_title'] = $jobNode->label();
          $item['job_location'] = $jobNode->get('field_localisation')->entity?->label() ?? '';
        }
      }
      $items[] = $item;
    }
    return $items;
  }

  /**
   * @param array<string, mixed> $filters
   */
  public function listForRecruiter(int $recruiterId, array $filters = [], int $page = 1, int $perPage = 20): array {
    $storage = $this->entityTypeManager->getStorage('node');
    // Get job IDs for this recruiter.
    $jobNids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'offre_emploi')
      ->condition('uid', $recruiterId)
      ->execute();

    if (empty($jobNids)) {
      return ['items' => [], 'total' => 0];
    }

    $jobTitle = trim((string) ($filters['job_title'] ?? ''));
    if ($jobTitle !== '') {
      $matchedJobIds = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'offre_emploi')
        ->condition('nid', array_values($jobNids), 'IN')
        ->condition('title', '%' . $jobTitle . '%', 'LIKE')
        ->execute();

      if (empty($matchedJobIds)) {
        return ['items' => [], 'total' => 0];
      }
      $jobNids = $matchedJobIds;
    }

    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'candidature')
      ->condition('field_offre_ref', array_values($jobNids), 'IN')
      ->sort('created', 'DESC');

    $this->applyRecruiterListFilters($query, $filters);

    $countQuery = clone $query;
    $total = (int) $countQuery->count()->execute();

    $query->range(($page - 1) * $perPage, $perPage);
    $nids = $query->execute();

    $items = [];
    $loaded = $storage->loadMultiple($nids);

    // Preload job titles/locations to avoid N+1.
    $jobIds = [];
    foreach ($loaded as $node) {
      $jobNid = $node->get('field_offre_ref')->target_id ?? NULL;
      if ($jobNid) $jobIds[(int) $jobNid] = (int) $jobNid;
    }
    $jobs = $jobIds ? $storage->loadMultiple(array_values($jobIds)) : [];

    foreach ($loaded as $node) {
      $item = $this->normalizeNode($node);
      $jobNid = $node->get('field_offre_ref')->target_id ?? NULL;
      if ($jobNid && isset($jobs[$jobNid])) {
        $jobNode = $jobs[$jobNid];
        $item['job_title'] = $jobNode->label();
        $item['job_location'] = $jobNode->get('field_localisation')->entity?->label() ?? '';
      }
      $items[] = $item;
    }
    return ['items' => $items, 'total' => $total];
  }

  public function updateByRecruiter(int $id, int $recruiterId, array $data): ?array {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'candidature') {
      return NULL;
    }
    // Verify job belongs to recruiter.
    $jobNid = $node->get('field_offre_ref')->target_id ?? NULL;
    if ($jobNid) {
      $jobNode = Node::load($jobNid);
      if (!$jobNode || (int) $jobNode->getOwnerId() !== $recruiterId) {
        return NULL;
      }
    }
    if (isset($data['status'])) {
      $node->set('field_status_candidature', $data['status']);
    }
    $node->save();
    return $this->normalizeNode($node);
  }

  public function normalizeNode($node): array {
    return [
      'id' => (int) $node->id(),
      'job_id' => (int) ($node->get('field_offre_ref')->target_id ?? 0),
      'profil_candidat_id' => (int) ($node->get('field_profil_candidat')->target_id ?? 0),
      'candidate_id' => (int) $node->getOwnerId(),
      'name' => $node->get('field_candidat_name')->value ?? '',
      'email' => $node->get('field_candidat_email')->value ?? '',
      'phone' => $node->get('field_candidat_phone')->value ?? NULL,
      'cover_letter' => $node->get('field_cover_letter')->value ?? NULL,
      'cv_file' => $node->get('field_cv_file')->value ?? NULL,
      'status' => $node->get('field_status_candidature')->value ?? 'pending',
      'created_at' => $this->serializer->iso((int) $node->getCreatedTime()),
    ];
  }

}
