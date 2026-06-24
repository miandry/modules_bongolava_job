<?php

namespace Drupal\bongolava_job\Repository;

use Drupal\bongolava_job\Service\ApiSerializer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\HttpFoundation\Request;

/**
 * Event repository using evenement + inscription_evenement nodes.
 */
final class EventRepository {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ApiSerializer $serializer,
  ) {}

  private function resolveTermId(string $vocabId, string $name): ?int {
    if (empty($name)) {
      return NULL;
    }
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')
      ->loadByProperties(['vid' => $vocabId, 'name' => trim($name)]);
    if ($terms) {
      return (int) reset($terms)->id();
    }
    return NULL;
  }

  private function resolveOrCreateTermId(string $vocabId, string $name): ?int {
    $name = trim($name);
    if ($name === '') {
      return NULL;
    }
    $existing = $this->resolveTermId($vocabId, $name);
    if ($existing) {
      return $existing;
    }
    $term = Term::create(['vid' => $vocabId, 'name' => $name]);
    $term->save();
    return (int) $term->id();
  }

  private function countRegistrations(int $eventId): int {
    return (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'inscription_evenement')
      ->condition('field_evenement_ref', $eventId)
      ->count()
      ->execute();
  }

  /**
   * @param array<string, mixed> $filters
   */
  private function applyListFilters($query, array $filters, ?int $userId = NULL): void {
    if ($userId !== NULL) {
      $query->condition('uid', $userId);
    }

    if (!empty($filters['status'])) {
      $query->condition('field_status', $filters['status']);
    }
    elseif ($userId === NULL) {
      $query->condition('field_status', 'published');
    }

    $keyword = trim((string) ($filters['keyword'] ?? $filters['search'] ?? ''));
    if ($keyword !== '') {
      $or = $query->orConditionGroup()
        ->condition('title', '%' . $keyword . '%', 'LIKE')
        ->condition('field_description', '%' . $keyword . '%', 'LIKE');
      $query->condition($or);
    }

    if (!empty($filters['type'])) {
      $tid = $this->resolveTermId('type_evenement', (string) $filters['type']);
      if ($tid) {
        $query->condition('field_type_evenement', $tid);
      }
    }

    if (!empty($filters['location'])) {
      $tid = $this->resolveTermId('localisation', (string) $filters['location']);
      if ($tid) {
        $query->condition('field_localisation', $tid);
      }
    }
  }

  /**
   * @param array<string, mixed> $filters
   */
  public function list(array $filters, int $page, int $perPage, ?int $userId = NULL): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'evenement')
      ->condition('status', 1);

    $this->applyListFilters($query, $filters, $userId);

    $countQuery = clone $query;
    $total = (int) $countQuery->count()->execute();

    $query->sort('field_date_debut', 'ASC');
    $query->range(($page - 1) * $perPage, $perPage);
    $nids = $query->execute();

    $items = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $items[] = $this->normalizeNode($node);
    }

    return ['items' => $items, 'total' => $total];
  }

  public function listAll(Request $request): array {
    $filters = $request->query->all();
    $page = max(1, (int) $request->query->get('page', 1));
    $perPage = max(1, (int) $request->query->get('per_page', 9));
    return $this->list($filters, $page, $perPage);
  }

  public function myEvent(Request $request, int $userId): array {
    $filters = $request->query->all();
    $page = max(1, (int) $request->query->get('page', 1));
    $perPage = max(1, (int) $request->query->get('per_page', 9));
    return $this->list($filters, $page, $perPage, $userId);
  }

  public function load(int $id, bool $withRegistrations = FALSE): ?array {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'evenement') {
      return NULL;
    }
    $data = $this->normalizeNode($node);
    if ($withRegistrations) {
      $regNids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'inscription_evenement')
        ->condition('field_evenement_ref', $id)
        ->sort('created', 'ASC')
        ->execute();
      $regs = [];
      foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($regNids) as $rNode) {
        $regs[] = $this->normalizeRegistration($rNode);
      }
      $data['registrations'] = $regs;
    }
    return $data;
  }

  public function create(int $userId, array $data): array {
    $node = Node::create([
      'type' => 'evenement',
      'title' => $data['title'] ?? 'Evenement',
      'uid' => $userId,
      'status' => 1,
    ]);
    $this->setNodeFields($node, $data);
    $node->save();
    return $this->normalizeNode($node);
  }

  public function update(int $id, int $userId, array $data, bool $isAdmin = FALSE): ?array {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'evenement') {
      return NULL;
    }
    if (!$isAdmin && (int) $node->getOwnerId() !== $userId) {
      return NULL;
    }
    if (isset($data['title'])) {
      $node->set('title', $data['title']);
    }
    $this->setNodeFields($node, $data);
    $node->save();
    return $this->normalizeNode($node);
  }

  public function delete(int $id, int $userId, bool $isAdmin = FALSE): bool {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'evenement') {
      return FALSE;
    }
    if (!$isAdmin && (int) $node->getOwnerId() !== $userId) {
      return FALSE;
    }
    $regNids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'inscription_evenement')
      ->condition('field_evenement_ref', $id)
      ->execute();
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($regNids) as $rNode) {
      $rNode->delete();
    }
    $node->delete();
    return TRUE;
  }

  public function register(int $eventId, array $data): ?array {
    $eventNode = Node::load($eventId);
    if (!$eventNode || $eventNode->bundle() !== 'evenement') {
      return NULL;
    }
    $capacity = (int) ($eventNode->get('field_capacity')->value ?? 0);
    if ($capacity > 0) {
      $registered = $this->countRegistrations($eventId);
      if ($registered >= $capacity) {
        return NULL;
      }
    }
    $regNode = Node::create([
      'type' => 'inscription_evenement',
      'title' => 'Inscription - ' . $eventNode->label(),
      'status' => 0,
    ]);
    $regNode->set('field_evenement_ref', ['target_id' => $eventId]);
    $regNode->set('field_participant_name', $data['name'] ?? $data['participant_name'] ?? '');
    $regNode->set('field_participant_email', $data['email'] ?? $data['participant_email'] ?? '');
    $regNode->set('field_participant_phone', $data['phone'] ?? $data['participant_phone'] ?? NULL);
    $regNode->set('field_registered_at', date('Y-m-d\TH:i:s', \Drupal::time()->getRequestTime()));
    $regNode->save();
    return $this->normalizeRegistration($regNode);
  }

  private function setNodeFields(Node $node, array $data): void {
    if (isset($data['description'])) {
      $node->set('field_description', $data['description']);
    }
    if (isset($data['horaires'])) {
      $node->set('field_horaires', $data['horaires']);
    }
    if (isset($data['address'])) {
      $node->set('field_address', $data['address']);
    }
    if (isset($data['capacity'])) {
      $node->set('field_capacity', (int) $data['capacity']);
    }
    if (isset($data['organizer'])) {
      $node->set('field_organizer', $data['organizer']);
    }
    if (isset($data['contact_email'])) {
      $node->set('field_contact_email', $data['contact_email']);
    }
    if (isset($data['contact_phone'])) {
      $node->set('field_contact_phone', $data['contact_phone']);
    }
    if (isset($data['date']) || isset($data['date_debut'])) {
      $node->set('field_date_debut', $data['date'] ?? $data['date_debut']);
    }
    if (isset($data['type'])) {
      $tid = $this->resolveTermId('type_evenement', $data['type']);
      $node->set('field_type_evenement', $tid ? ['target_id' => $tid] : NULL);
    }
    if (isset($data['location'])) {
      $tid = $this->resolveOrCreateTermId('localisation', $data['location']);
      $node->set('field_localisation', $tid ? ['target_id' => $tid] : NULL);
    }
    if (!$node->id()) {
      $node->set('field_status', 'pending');
    }
  }

  public function normalizeNode($node): array {
    return [
      'id' => (int) $node->id(),
      'title' => $node->label(),
      'type' => $node->get('field_type_evenement')->entity?->label() ?? '',
      'date' => $node->get('field_date_debut')->value ?? NULL,
      'horaires' => $node->get('field_horaires')->value ?? NULL,
      'location' => $node->get('field_localisation')->entity?->label() ?? '',
      'address' => $node->get('field_address')->value ?? NULL,
      'description' => $node->get('field_description')->value ?? '',
      'capacity' => $node->get('field_capacity')->value !== NULL ? (int) $node->get('field_capacity')->value : NULL,
      'registered' => $this->countRegistrations((int) $node->id()),
      'organizer' => $node->get('field_organizer')->value ?? '',
      'contact_email' => $node->get('field_contact_email')->value ?? NULL,
      'status' => $node->get('field_status')->value ?? NULL,
      'contact_phone' => $node->get('field_contact_phone')->value ?? NULL,
      'owner_id' => (int) $node->getOwnerId(),
      'created_at' => $this->serializer->iso((int) $node->getCreatedTime()),
    ];
  }

  public function normalizeRegistration($node): array {
    return [
      'id' => (int) $node->id(),
      'event_id' => (int) ($node->get('field_evenement_ref')->target_id ?? 0),
      'name' => $node->get('field_participant_name')->value ?? '',
      'email' => $node->get('field_participant_email')->value ?? '',
      'phone' => $node->get('field_participant_phone')->value ?? NULL,
      'registered_at' => $node->get('field_registered_at')->value ?? NULL,
      'created_at' => $this->serializer->iso((int) $node->getCreatedTime()),
    ];
  }

}
