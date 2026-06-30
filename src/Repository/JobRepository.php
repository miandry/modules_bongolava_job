<?php

namespace Drupal\bongolava_job\Repository;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\bongolava_job\Service\ApiSerializer;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Job offer repository using offre_emploi nodes.
 */
final class JobRepository {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ApiSerializer $serializer,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  private function resolveTermId(string $vocabId, string $name): ?int {
    if (empty($name)) {
      return NULL;
    }
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => $vocabId, 'name' => $name]);
    if ($terms) {
      return (int) reset($terms)->id();
    }
    return NULL;
  }

  private function setNodeFields(Node $node, array $data): void {
    if (isset($data['company'])) {
      $node->set('field_company', $data['company']);
    }
    if (isset($data['salary'])) {
      $node->set('field_salary', $data['salary']);
    }
    if (isset($data['description'])) {
      $node->set('field_description_event', $data['description']);
    }
    if (isset($data['is_remote'])) {
      $node->set('field_remote', (bool) $data['is_remote']);
    }
    if (isset($data['is_urgent'])) {
      $node->set('field_urgent', (bool) $data['is_urgent']);
    }
    if (isset($data['contact_email'])) {
      $node->set('field_contact_email', $data['contact_email']);
    }
    if (isset($data['contact_phone'])) {
      $node->set('field_contact_phone', $data['contact_phone']);
    }
    if (isset($data['requirements'])) {
      $node->set('field_requirements', $data['requirements']);
    }
    if (isset($data['responsibilities'])) {
      $node->set('field_responsibilities', $data['responsibilities']);
    }
    if (isset($data['expires_at'])) {
      $node->set('field_expires_at', $data['expires_at']);
    }
    if (isset($data['contract_type'])) {
      $tid = $this->resolveTermId('type_contrat', $data['contract_type']);
      $node->set('field_type_contrat', $tid ? ['target_id' => $tid] : NULL);
    }
    if (isset($data['sector'])) {
      $tid = $this->resolveTermId('secteur', $data['sector']);
      $node->set('field_secteur', $tid ? ['target_id' => $tid] : NULL);
    }
    if (isset($data['location'])) {
      $tid = $this->resolveTermId('localisation', $data['location']);
      $node->set('field_localisation', $tid ? ['target_id' => $tid] : NULL);
    }
    if (isset($data['status'])) {
      $node->set('field_status_offre', $data['status']);
    }
  }

  private function attachImage(Node $node, UploadedFile $image, int $ownerId, string $alt = ''): void {
    // Store file under public://bongolava_job/job-images and create a Media (image).
    $config = $this->configFactory->get('bongolava_job.settings');
    $scheme = $config->get('api.storage_scheme') ?? 'public://bongolava_job';
    $directory = rtrim($scheme, '/') . '/job-images';

    /** @var \Drupal\Core\File\FileSystemInterface $fs */
    $fs = \Drupal::service('file_system');
    $fs->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $ext = strtolower((string) $image->getClientOriginalExtension());
    $filename = uniqid('job_', TRUE) . ($ext ? '.' . $ext : '');
    $realDir = $fs->realpath($directory);
    $image->move($realDir, $filename);

    $uri = $directory . '/' . $filename;
    $file = File::create([
      'uri' => $uri,
      'uid' => $ownerId,
      'status' => 1,
    ]);
    $file->setPermanent();
    $file->save();

    $media = Media::create([
      'bundle' => 'image',
      'name' => $node->label(),
      'uid' => $ownerId,
      'status' => 1,
      'field_media_image' => [
        'target_id' => (int) $file->id(),
        'alt' => $alt ?: $node->label(),
      ],
    ]);
    $media->save();

    $node->set('field_image_offre', ['target_id' => (int) $media->id()]);
  }

  public function create(
    int $recruiterId,
    array $data,
    ?UploadedFile $image = NULL,
    string $userType = 'recruiter',
    string $offerStatus = 'pending',
  ): array {
    $node = Node::create([
      'type' => 'offre_emploi',
      'title' => $data['title'] ?? 'Untitled',
      'uid' => $recruiterId,
      'status' => 1,
    ]);
    $this->setNodeFields($node, $data);
    $node->set('field_status_offre', $offerStatus);
    $node->set('field_user_type', $userType);
    if ($image) {
      $this->attachImage($node, $image, $recruiterId, (string) ($data['title'] ?? ''));
    }
    $node->save();
    return $this->normalizeNode($node);
  }

  public function load(int $id, bool $publicOnly = FALSE): ?array {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'offre_emploi') {
      return NULL;
    }
    if ($publicOnly && !$node->isPublished()) {
      return NULL;
    }
    return $this->normalizeNode($node);
  }

  public function update(int $id, int $recruiterId, array $data): ?array {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'offre_emploi') {
      return NULL;
    }
    if ((int) $node->getOwnerId() !== $recruiterId) {
      return NULL;
    }
    if (isset($data['title'])) {
      $node->set('title', $data['title']);
    }
    $this->setNodeFields($node, $data);
    $node->save();
    return $this->normalizeNode($node);
  }

  public function delete(int $id, int $recruiterId): bool {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'offre_emploi') {
      return FALSE;
    }
    if ((int) $node->getOwnerId() !== $recruiterId) {
      return FALSE;
    }
    $node->delete();
    return TRUE;
  }

  public function list(array $filters, int $page, int $perPage, bool $activeOnly = TRUE): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'offre_emploi')
      ->condition('status', 1);

    if ($activeOnly) {
      // Include published nodes whose field_status_offre is active, published,
      // OR empty (nodes created outside the API workflow, e.g. via AI builder).
      $orGroup = $query->orConditionGroup()
        ->condition('field_status_offre', ['active', 'published'], 'IN')
        ->notExists('field_status_offre');
      $query->condition($orGroup);
    }
    if (!empty($filters['keyword'])) {
      $query->condition('title', '%' . $filters['keyword'] . '%', 'LIKE');
    }
    if (!empty($filters['location'])) {
      $tid = $this->resolveTermId('localisation', $filters['location']);
      if ($tid) {
        $query->condition('field_localisation', $tid);
      }
    }
    if (!empty($filters['sector'])) {
      $tid = $this->resolveTermId('secteur', $filters['sector']);
      if ($tid) {
        $query->condition('field_secteur', $tid);
      }
    }
    if (!empty($filters['contract_type'])) {
      $tid = $this->resolveTermId('type_contrat', $filters['contract_type']);
      if ($tid) {
        $query->condition('field_type_contrat', $tid);
      }
    }

    $countQuery = clone $query;
    $total = (int) $countQuery->count()->execute();

    $query->sort('created', 'DESC');
    $query->range(($page - 1) * $perPage, $perPage);
    $nids = $query->execute();

    $items = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $items[] = $this->normalizeNode($node);
    }

    return ['items' => $items, 'total' => $total];
  }

  public function listByRecruiter(int $recruiterId): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'offre_emploi')
      ->condition('uid', $recruiterId)
      ->sort('created', 'DESC')
      ->execute();

    $items = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $items[] = $this->normalizeNode($node);
    }
    return $items;
  }

  public function listPending(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'offre_emploi')
      ->condition('field_status_offre', 'pending')
      ->sort('created', 'ASC')
      ->execute();

    $items = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $items[] = $this->normalizeNode($node);
    }
    return $items;
  }

  public function approve(int $id, int $adminId): ?array {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'offre_emploi') {
      return NULL;
    }
    $node->set('field_status_offre', 'published');
    $node->save();
    return $this->normalizeNode($node);
  }

  public function reject(int $id, int $adminId, ?string $reason = NULL): ?array {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'offre_emploi') {
      return NULL;
    }
    $node->set('field_status_offre', 'rejected');
    $node->save();
    return $this->normalizeNode($node);
  }

  public function forceExpire(): int {
    $now = date('Y-m-d\TH:i:s', \Drupal::time()->getRequestTime());
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'offre_emploi')
      ->condition('field_status_offre', ['active', 'published'], 'IN')
      ->condition('field_expires_at', $now, '<')
      ->execute();

    $count = 0;
    foreach ($storage->loadMultiple($nids) as $node) {
      if ($node instanceof Node) {
        $node->set('field_status_offre', 'expired');
        $node->save();
        $count++;
      }
    }
    return $count;
  }

  public function perPage(): int {
    return max(1, (int) ($this->configFactory->get('bongolava_job.settings')->get('jobs_per_page') ?? 10));
  }

  public function normalizeNode($node): array {
    $termLabel = function (string $fieldName) use ($node): string {
      return $node->get($fieldName)->entity?->label() ?? '';
    };

    $status = $node->get('field_status_offre')->value;
    if (empty($status)) {
      $status = $node->isPublished() ? 'active' : 'pending';
    }

    $imageUrl = NULL;
    try {
      $mediaId = (int) ($node->get('field_image_offre')->target_id ?? 0);
      if ($mediaId > 0) {
        $media = $this->entityTypeManager->getStorage('media')->load($mediaId);
        if ($media instanceof \Drupal\media\MediaInterface) {
          $fid = (int) ($media->get('field_media_image')->target_id ?? 0);
          if ($fid > 0) {
            $file = $this->entityTypeManager->getStorage('file')->load($fid);
            if ($file instanceof \Drupal\file\FileInterface) {
              /** @var \Drupal\Core\File\FileUrlGeneratorInterface $gen */
              $gen = \Drupal::service('file_url_generator');
              $imageUrl = $gen->generateAbsoluteString($file->getFileUri());
            }
          }
        }
      }
    }
    catch (\Throwable) {
      $imageUrl = NULL;
    }

    return [
      'id' => (int) $node->id(),
      'title' => $node->label(),
      'company' => $node->get('field_company')->value ?? '',
      'location' => $termLabel('field_localisation'),
      'contract_type' => $termLabel('field_type_contrat'),
      'sector' => $termLabel('field_secteur'),
      'salary' => $node->get('field_salary')->value ?? NULL,
      'description' => $node->get('field_description_event')->value ?? '',
      'is_urgent' => (bool) ($node->get('field_urgent')->value ?? FALSE),
      'is_remote' => (bool) ($node->get('field_remote')->value ?? FALSE),
      'urgent' => (bool) ($node->get('field_urgent')->value ?? FALSE),
      'remote' => (bool) ($node->get('field_remote')->value ?? FALSE),
      'contact_email' => $node->get('field_contact_email')->value ?? '',
      'contact_phone' => $node->get('field_contact_phone')->value ?? NULL,
      'requirements' => $node->get('field_requirements')->value ?? NULL,
      'responsibilities' => $node->get('field_responsibilities')->value ?? NULL,
      'status' => $status,
      'recruiter_id' => (int) $node->getOwnerId(),
      'created_at' => $this->serializer->iso((int) $node->getCreatedTime()),
      'expires_at' => $node->get('field_expires_at')->value ?? NULL,
      'views_count' => (int) ($node->get('field_views_count')->value ?? 0),
      'user_type' => $node->get('field_user_type')->value,
      'image_url' => $imageUrl,
    ];
  }

}
