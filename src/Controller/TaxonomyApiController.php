<?php

namespace Drupal\bongolava_job\Controller;

use Drupal\bongolava_job\Repository\TaxonomyRepository;
use Drupal\bongolava_job\Service\ApiResponseFactory;
use Drupal\bongolava_job\Service\AuthService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public API endpoints for taxonomies (vocabularies and terms).
 * These endpoints are cacheable and allow frontend to load taxonomy data dynamically.
 */
final class TaxonomyApiController extends ApiControllerBase {

  public function __construct(
    ApiResponseFactory $api,
    AuthService $auth,
    private readonly TaxonomyRepository $taxonomy,
  ) {
    parent::__construct($api, $auth);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bongolava_job.api_response'),
      $container->get('bongolava_job.auth'),
      $container->get('bongolava_job.taxonomy_repository'),
    );
  }

  /**
   * Get all available vocabulary types that can be queried.
   * 
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function vocabularies() {
    $available = $this->taxonomy->getAvailableVocabularies();
    return $this->api->ok(array_keys($available));
  }

  /**
   * Get all terms for a specific vocabulary.
   *
   * @param string $vocabulary Vocabulary key (e.g., 'sector', 'contract_type')
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function getVocabulary(string $vocabulary) {
    // Validate that the vocabulary exists
    if (!$this->taxonomy->resolveVocabulary($vocabulary)) {
      return $this->api->notFound();
    }

    $terms = $this->taxonomy->loadTerms($vocabulary);
    return $this->api->ok($terms);
  }

  /**
   * Get a single term from a vocabulary by name.
   *
   * @param string $vocabulary Vocabulary key
   * @param string $termName Term name to search for
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function getTerm(string $vocabulary, string $termName) {
    if (!$this->taxonomy->resolveVocabulary($vocabulary)) {
      return $this->api->notFound();
    }

    $term = $this->taxonomy->loadTermByName($vocabulary, $termName);
    return $term ? $this->api->ok($term) : $this->api->notFound();
  }

  /**
   * Get multiple vocabularies at once (batch request).
   * Accepts query parameters: ?vocabularies=sector,contract_type,location
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   */
  public function getMultiple(Request $request) {
    $vocabulariesParam = $request->query->get('vocabularies', '');
    if (!$vocabulariesParam) {
      return $this->api->validationError('The vocabularies parameter is required.', [
        'vocabularies' => ['Please provide a comma-separated list of vocabulary keys.'],
      ]);
    }

    // Parse the vocabularies parameter
    $requested = array_map('trim', explode(',', $vocabulariesParam));
    $requested = array_filter($requested);

    if (empty($requested)) {
      return $this->api->validationError('The vocabularies parameter is invalid.', [
        'vocabularies' => ['Provide at least one vocabulary key.'],
      ]);
    }

    // Load all requested vocabularies
    $result = [];
    foreach ($requested as $vocab) {
      if ($this->taxonomy->resolveVocabulary($vocab)) {
        $terms = $this->taxonomy->loadTerms($vocab);
        if ($terms) {
          $result[$vocab] = $terms;
        }
      }
    }

    return $this->api->ok($result);
  }

}
