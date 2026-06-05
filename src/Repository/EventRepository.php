<?php

namespace Drupal\bongolava_job\Repository;

use Drupal\bongolava_job\Service\ApiSerializer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;

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
      ->loadByProperties(['vid' => $vocabId, 'name' => $name]);
    if ($terms) {
      return (int) reset($terms)->id();
    }
    return NULL;
  }

  private function countRegistrations(int $eventId): int {
    return (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'inscription_evenement')
      ->condition('field_evenement_ref', $eventId)
      ->count()
      ->execute();
  }

  public function listAll(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'evenement')
      ->condition('status', 1)
      ->sort('field_date_debut', 'ASC')
      ->execute();

    $items = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $items[] = $this->normalizeNode($node);
    }
    return $items;
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

  public function create(int $adminId, array $data): array {
    $node = Node::create([
      'type' => 'evenement',
      'title' => $data['title'] ?? 'Evenement',
      'uid' => $adminId,
      'status' => 1,
    ]);
    $this->setNodeFields($node, $data);
    $node->save();
    return $this->normalizeNode($node);
  }

  public function update(int $id, array $data): ?array {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'evenement') {
      return NULL;
    }
    if (isset($data['title'])) {
      $node->set('title', $data['title']);
    }
    $this->setNodeFields($node, $data);
    $node->save();
    return $this->normalizeNode($node);
  }

  public function delete(int $id): bool {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'evenement') {
      return FALSE;
    }
    // Delete associated registrations.
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
    if (isset($data['long_description'])) {
      $node->set('field_long_description', $data['long_description']);
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
    if (isset($data['end_date']) || isset($data['date_fin'])) {
      $node->set('field_date_fin', $data['end_date'] ?? $data['date_fin']);
    }
    if (isset($data['type'])) {
      $tid = $this->resolveTermId('type_evenement', $data['type']);
      $node->set('field_type_evenement', $tid ? ['target_id' => $tid] : NULL);
    }
    if (isset($data['location'])) {
      $tid = $this->resolveTermId('localisation', $data['location']);
      $node->set('field_localisation', $tid ? ['target_id' => $tid] : NULL);
    }
  }

  public function normalizeNode($node): array {
    return [
      'id' => (int) $node->id(),
      'title' => $node->label(),
      'type' => $node->get('field_type_evenement')->entity?->label() ?? '',
      'date' => $node->get('field_date_debut')->value ?? NULL,
      'end_date' => $node->get('field_date_fin')->value ?? NULL,
      'location' => $node->get('field_localisation')->entity?->label() ?? '',
      'address' => $node->get('field_address')->value ?? NULL,
      'description' => $node->get('field_description')->value ?? '',
      'long_description' => $node->get('field_long_description')->value ?? NULL,
      'capacity' => $node->get('field_capacity')->value !== NULL ? (int) $node->get('field_capacity')->value : NULL,
      'registered' => $this->countRegistrations((int) $node->id()),
      'organizer' => $node->get('field_organizer')->value ?? '',
      'contact_email' => $node->get('field_contact_email')->value ?? NULL,
      'contact_phone' => $node->get('field_contact_phone')->value ?? NULL,
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
