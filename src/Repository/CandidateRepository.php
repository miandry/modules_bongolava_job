<?php

namespace Drupal\bongolava_job\Repository;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\node\Entity\Node;
use Drupal\bongolava_job\Service\ApiSerializer;

/**
 * Candidate profile repository using profil_candidat nodes.
 */
final class CandidateRepository
{

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ApiSerializer $serializer,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  private function resolveTermId(string $vocabId, string $name): ?int
  {
    if (empty($name)) {
      return NULL;
    }

    $name = trim($name);
    $database = \Drupal::database();

    // Recherche d'égalité stricte insensible à la casse
    $query = $database->select('taxonomy_term_field_data', 't');
    $query->addField('t', 'tid');
    $query->condition('t.vid', $vocabId);
    $query->where('LOWER(t.name) = LOWER(:name)', [':name' => $name]);
    $tid = $query->execute()->fetchField();

    if ($tid) {
      return (int) $tid;
    }

    // Création du terme
    try {
      $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');

      // Normaliser la casse (optionnel)
      $normalizedName = ucfirst(strtolower($name));

      $term = $termStorage->create([
        'vid' => $vocabId,
        'name' => $normalizedName,
      ]);
      $term->save();

      return (int) $term->id();
    } catch (\Exception $e) {
      return NULL;
    }
  }

  private function setNodeFields(Node $node, array $data): void
  {
    $simpleFields = [
      'first_name' => 'field_first_name',
      'last_name' => 'field_last_name',
      'job_target' => 'field_job_target',
      'bio' => 'field_bio',
      'experience_level' => 'field_experience',
      'educations' => 'field_education',
      'certifications' => 'field_certifications',
      'languages' => 'field_languages',
      'skills' => 'field_competences',
      'phone' => 'field_phone',
      'email' => 'field_email_contact',
      'cv_path' => 'field_cv',
      'address' => 'field_address',
      'website' => 'field_website',
    ];
    foreach ($simpleFields as $key => $field) {
      if (isset($data[$key])) {
        $node->set($field, $data[$key]);
      }
    }
    if (isset($data['available'])) {
      $node->set('field_available', (bool) $data['available']);
    }
    if (isset($data['age'])) {
      $node->set('field_age', (int) $data['age']);
    }
    if (isset($data['location'])) {
      $tid = $this->resolveTermId('localisation', $data['location']);
      $node->set('field_localisation', $tid ? ['target_id' => $tid] : NULL);
    }

    // if (isset($data['skills'])) {
    //   // skills may be a comma-separated string or array of term names.
    //   $skillNames = is_array($data['skills']) ? $data['skills'] : explode(',', $data['skills']);
    //   $tids = [];
    //   foreach ($skillNames as $name) {
    //     $name = trim($name);
    //     if ($name !== '') {
    //       $tid = $this->resolveTermId('competences', $name);
    //       if ($tid) {
    //         $tids[] = ['target_id' => $tid];
    //       }
    //     }
    //   }
    //   $node->set('field_competences', $tids ?: NULL);
    // }
  }

  public function create(int $userId, array $data): array
  {
    $firstName = $data['first_name'] ?? '';
    $lastName = $data['last_name'] ?? '';
    $node = Node::create([
      'type' => 'profil_candidat',
      'title' => trim($firstName . ' ' . $lastName) ?: 'Candidat',
      'uid' => $userId,
      'status' => 1,
    ]);
    $this->setNodeFields($node, $data);
    $node->save();
    return $this->normalizeNode($node);
  }

  public function load(int $id): ?array
  {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'profil_candidat') {
      return NULL;
    }
    return $this->normalizeNode($node);
  }

  public function loadByUser(int $userId): ?array
  {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'profil_candidat')
      ->condition('uid', $userId)
      ->range(0, 1)
      ->execute();
    if (empty($nids)) {
      return NULL;
    }
    $node = Node::load(reset($nids));
    return $node ? $this->normalizeNode($node) : NULL;
  }

  public function updateByUser(int $userId, array $data): ?array
  {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'profil_candidat')
      ->condition('uid', $userId)
      ->range(0, 1)
      ->execute();
    if (empty($nids)) {
      return NULL;
    }
    $node = Node::load(reset($nids));
    if (!$node) {
      return NULL;
    }
    if (isset($data['first_name']) || isset($data['last_name'])) {
      $fn = $data['first_name'] ?? ($node->get('field_first_name')->value ?? '');
      $ln = $data['last_name'] ?? ($node->get('field_last_name')->value ?? '');
      $node->set('title', trim($fn . ' ' . $ln));
    }
    $this->setNodeFields($node, $data);
    $node->save();
    return $this->normalizeNode($node);
  }

  public function updatePath(int $userId, string $column, string $path): ?array
  {
    $nids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'profil_candidat')
      ->condition('uid', $userId)
      ->range(0, 1)
      ->execute();
    if (empty($nids)) {
      return NULL;
    }
    $node = Node::load(reset($nids));
    if (!$node) {
      return NULL;
    }
    if ($column === 'photo_path') {
      $node->set('field_photo', $path);
    } elseif ($column === 'cv_path') {
      $node->set('field_cv', $path);
    }
    $node->save();
    return $this->normalizeNode($node);
  }

  public function list(array $filters, int $page, int $perPage): array
  {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'profil_candidat')
      ->condition('status', 1);

    if (!empty($filters['keyword'])) {
      $orGroup = $query->orConditionGroup()
        ->condition('field_first_name', '%' . $filters['keyword'] . '%', 'LIKE')
        ->condition('field_last_name', '%' . $filters['keyword'] . '%', 'LIKE')
        ->condition('field_job_target', '%' . $filters['keyword'] . '%', 'LIKE');
      $query->condition($orGroup);
    }
    if (!empty($filters['location'])) {
      $tid = $this->resolveTermId('localisation', $filters['location']);
      if ($tid) {
        $query->condition('field_localisation', $tid);
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

  public function normalizeNode($node): array
  {
    // Get skills as comma-separated term labels.
    $skills = [];
    foreach ($node->get('field_competences') as $item) {
      if ($item->entity) {
        $skills[] = $item->entity->label();
      }
    }

    $photoPath = $node->get('field_photo')->value ?? NULL;
    $photoUrl = $photoPath ? $this->serializer->storageUrl($photoPath) : NULL;

    return [
      'id' => (int) $node->id(),
      'user_id' => (int) $node->getOwnerId(),
      'first_name' => $node->get('field_first_name')->value ?? '',
      'last_name' => $node->get('field_last_name')->value ?? '',
      'age' => $node->get('field_age')->value !== NULL ? (int) $node->get('field_age')->value : NULL,
      'location' => $node->get('field_localisation')->entity?->label() ?? '',
      'address' => $node->get('field_address')->value ?? NULL,
      'job_target' => $node->get('field_job_target')->value ?? NULL,
      'skills' => $node->get('field_competences')->value ?? NULL,
      'experiences' => $node->get('field_experience')->value ?? NULL,
      'educations' => $node->get('field_education')->value ?? NULL,
      'certifications' => $node->get('field_certifications')->value ?? NULL,
      'languages' => $node->get('field_languages')->value ?? NULL,
      'experience_level' => $node->get('field_experience')->value ?? NULL,
      'cv_path' => $node->get('field_cv')->value ?? NULL,
      'website' => $node->get('field_website')->uri ?? NULL,
      'photo_path' => $photoUrl,
      'phone' => $node->get('field_phone')->value ?? NULL,
      'email' => $node->get('field_email_contact')->value ?? NULL,
      'available' => (bool) ($node->get('field_available')->value ?? FALSE),
      'bio' => $node->get('field_bio')->value ?? NULL,
      'created_at' => $this->serializer->iso((int) $node->getCreatedTime()),
      'updated_at' => $this->serializer->iso((int) $node->getChangedTime()),
    ];
  }
}
