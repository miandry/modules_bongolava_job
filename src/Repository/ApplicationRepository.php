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

  public function listForRecruiter(int $recruiterId): array {
    $storage = $this->entityTypeManager->getStorage('node');
    // Get job IDs for this recruiter.
    $jobNids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'offre_emploi')
      ->condition('uid', $recruiterId)
      ->execute();

    if (empty($jobNids)) {
      return [];
    }

    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'candidature')
      ->condition('field_offre_ref', array_values($jobNids), 'IN')
      ->sort('created', 'DESC')
      ->execute();

    $items = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $items[] = $this->normalizeNode($node);
    }
    return $items;
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
