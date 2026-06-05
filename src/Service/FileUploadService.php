<?php

namespace Drupal\bongolava_job\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Handles CV, photo, and logo uploads.
 */
final class FileUploadService {

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ApiSerializer $serializer,
  ) {}

  /**
   * @return array{path: string}|array{error: string}
   */
  public function save(UploadedFile $file, string $subdir, array $allowedExtensions): array {
    $ext = strtolower($file->getClientOriginalExtension());
    if (!in_array($ext, $allowedExtensions, TRUE)) {
      return ['error' => 'Invalid file type.'];
    }
    $scheme = $this->configFactory->get('bongolava_job.settings')->get('api.storage_scheme') ?? 'public://bongolava_job';
    $directory = $scheme . '/' . trim($subdir, '/');
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $filename = uniqid('', TRUE) . '.' . $ext;
    $destination = $directory . '/' . $filename;
    $file->move($this->fileSystem->realpath($directory), $filename);
    $relative = trim($subdir, '/') . '/' . $filename;
    return ['path' => $relative];
  }

  public function deleteRelative(?string $path): void {
    if ($path === NULL || $path === '') {
      return;
    }
    $scheme = $this->configFactory->get('bongolava_job.settings')->get('api.storage_scheme') ?? 'public://bongolava_job';
    $uri = $scheme . '/' . ltrim($path, '/');
    if (file_exists($uri)) {
      $this->fileSystem->delete($uri);
    }
  }

}
