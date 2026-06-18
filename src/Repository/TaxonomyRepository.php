<?php

namespace Drupal\bongolava_job\Repository;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Repository for loading taxonomy terms from vocabularies.
 */
final class TaxonomyRepository {

  /**
   * Mapping of frontend vocabulary keys to Drupal vocabulary machine names.
   * This allows the API to serve consistent terminology across platforms.
   */
  private const VOCABULARY_MAP = [
    'sector' => 'secteur',
    'contract_type' => 'type_contrat',
    'location' => 'localisation',
    'event_type' => 'type_evenement',
    'skill' => 'competence',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Get all available vocabularies that can be queried.
   *
   * @return array<string, string> Mapping of frontend keys to vocabulary machine names
   */
  public function getAvailableVocabularies(): array {
    return self::VOCABULARY_MAP;
  }

  /**
   * Load all terms from a vocabulary.
   *
   * @param string $vocabularyKey Frontend vocabulary key (e.g., 'sector', 'contract_type')
   * @return array<int, array<string, string>> Terms with value and label
   */
  public function loadTerms(string $vocabularyKey): array {
    $vocabMachineName = $this->resolveVocabulary($vocabularyKey);
    if (!$vocabMachineName) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vocabMachineName)
      ->sort('weight')
      ->sort('name');

    $tids = $query->execute();
    $terms = $storage->loadMultiple($tids);

    $result = [];
    foreach ($terms as $term) {
      $result[] = [
        'value' => $term->getName(),
        'label' => $term->label(),
        'id' => (int) $term->id(),
      ];
    }

    return $result;
  }

  /**
   * Load a single term by vocabulary key and term name.
   *
   * @param string $vocabularyKey Frontend vocabulary key
   * @param string $termName Term name to search for
   * @return array<string, mixed>|null Term data or null if not found
   */
  public function loadTermByName(string $vocabularyKey, string $termName): ?array {
    $vocabMachineName = $this->resolveVocabulary($vocabularyKey);
    if (!$vocabMachineName) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadByProperties([
      'vid' => $vocabMachineName,
      'name' => $termName,
    ]);

    if (!$terms) {
      return NULL;
    }

    $term = reset($terms);
    return [
      'id' => (int) $term->id(),
      'value' => $term->getName(),
      'label' => $term->label(),
    ];
  }

  /**
   * Resolve a frontend vocabulary key to the Drupal vocabulary machine name.
   *
   * @param string $key Frontend key (e.g., 'sector')
   * @return string|null Vocabulary machine name or null if not found
   */
  public function resolveVocabulary(string $key): ?string {
    return self::VOCABULARY_MAP[$key] ?? NULL;
  }

  /**
   * Load multiple vocabularies at once.
   *
   * @param string[] $vocabularyKeys Frontend vocabulary keys to load
   * @return array<string, array<int, array<string, string>>> All terms grouped by vocabulary
   */
  public function loadMultipleVocabularies(array $vocabularyKeys): array {
    $result = [];

    foreach ($vocabularyKeys as $key) {
      $terms = $this->loadTerms($key);
      if ($terms) {
        $result[$key] = $terms;
      }
    }

    return $result;
  }

}
