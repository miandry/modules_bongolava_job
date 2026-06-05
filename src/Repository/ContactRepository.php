<?php

namespace Drupal\bongolava_job\Repository;

use Drupal\bongolava_job\Service\ApiSerializer;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;

/**
 * Contact message repository using message_contact nodes.
 */
final class ContactRepository {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ApiSerializer $serializer,
  ) {}

  public function create(array $data): array {
    $subject = $data['subject'] ?? 'Message';
    $node = Node::create([
      'type' => 'message_contact',
      'title' => $subject,
      'status' => 0,
    ]);
    $node->set('field_sender_name', $data['name'] ?? $data['sender_name'] ?? '');
    $node->set('field_sender_email', $data['email'] ?? $data['sender_email'] ?? '');
    $node->set('field_subject', $subject);
    $node->set('field_message', $data['message'] ?? '');
    $node->set('field_is_read', FALSE);
    $node->save();
    return $this->normalizeNode($node);
  }

  public function load(int $id): ?array {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'message_contact') {
      return NULL;
    }
    return $this->normalizeNode($node);
  }

  public function listAll(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'message_contact')
      ->sort('created', 'DESC')
      ->execute();

    $items = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $items[] = $this->normalizeNode($node);
    }
    return $items;
  }

  public function markRead(int $id): ?array {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'message_contact') {
      return NULL;
    }
    $node->set('field_is_read', TRUE);
    $node->save();
    return $this->normalizeNode($node);
  }

  public function delete(int $id): bool {
    $node = Node::load($id);
    if (!$node || $node->bundle() !== 'message_contact') {
      return FALSE;
    }
    $node->delete();
    return TRUE;
  }

  public function normalizeNode($node): array {
    return [
      'id' => (int) $node->id(),
      'name' => $node->get('field_sender_name')->value ?? '',
      'email' => $node->get('field_sender_email')->value ?? '',
      'subject' => $node->get('field_subject')->value ?? '',
      'message' => $node->get('field_message')->value ?? '',
      'is_read' => (bool) ($node->get('field_is_read')->value ?? FALSE),
      'created_at' => $this->serializer->iso((int) $node->getCreatedTime()),
    ];
  }

}
