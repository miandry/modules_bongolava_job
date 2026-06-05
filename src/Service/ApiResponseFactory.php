<?php

namespace Drupal\bongolava_job\Service;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * JSON responses matching the Bongolava API contract.
 */
final class ApiResponseFactory {

  private const HEADERS = [
    'Cache-Control' => 'no-store, private',
    'Content-Type' => 'application/json',
  ];

  public function ok(mixed $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, self::HEADERS);
  }

  public function message(string $message, int $status = 200, array $extra = []): JsonResponse {
    return new JsonResponse(array_merge(['message' => $message], $extra), $status, self::HEADERS);
  }

  /**
   * @param array<string, array<int, string>> $errors
   */
  public function validationError(string $message, array $errors, int $status = 422): JsonResponse {
    return new JsonResponse([
      'message' => $message,
      'errors' => $errors,
    ], $status, self::HEADERS);
  }

  public function unauthorized(string $message = 'Unauthenticated.'): JsonResponse {
    return new JsonResponse(['message' => $message], 401, self::HEADERS);
  }

  public function forbidden(string $message = 'Forbidden.'): JsonResponse {
    return new JsonResponse(['message' => $message], 403, self::HEADERS);
  }

  public function notFound(string $message = 'Not found.'): JsonResponse {
    return new JsonResponse(['message' => $message], 404, self::HEADERS);
  }

  /**
   * @param array<int, mixed> $data
   */
  public function paginated(array $data, int $total, int $page, int $perPage): JsonResponse {
    $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
    return $this->ok([
      'data' => $data,
      'current_page' => $page,
      'last_page' => $lastPage,
      'per_page' => $perPage,
      'total' => $total,
    ]);
  }

}
