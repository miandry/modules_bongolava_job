<?php

namespace Drupal\bongolava_job\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\SchemaException;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Creates Bongolava Jobs database tables from hook_schema().
 */
final class SchemaInstaller {

  use StringTranslationTrait;

  public function __construct(
    private readonly Connection $database,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * @return array{created: string[], skipped: string[], errors: string[]}
   */
  public function installTables(): array {
    $result = ['created' => [], 'skipped' => [], 'errors' => []];
    $this->moduleHandler->loadInclude('bongolava_job', 'install');
    $definitions = bongolava_job_schema();
    $schema = $this->database->schema();

    foreach ($definitions as $table => $definition) {
      try {
        if ($schema->tableExists($table)) {
          $result['skipped'][] = $table;
          continue;
        }
        $schema->createTable($table, $definition);
        $result['created'][] = $table;
      }
      catch (SchemaException $e) {
        $result['errors'][] = $table . ': ' . $e->getMessage();
      }
    }

    return $result;
  }

  /**
   * @return array<string, bool>
   */
  public function tableStatus(): array {
    $this->moduleHandler->loadInclude('bongolava_job', 'install');
    $definitions = bongolava_job_schema();
    $schema = $this->database->schema();
    $status = [];
    foreach (array_keys($definitions) as $table) {
      $status[$table] = $schema->tableExists($table);
    }
    return $status;
  }

  /**
   * Ensures upload directory and grants API permission to authenticated role.
   */
  public function runPostInstallTasks(string $storageScheme = 'public://bongolava_job'): array {
    $messages = [];
    $this->fileSystem->prepareDirectory(
      $storageScheme,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    );
    $messages[] = (string) $this->t('Storage directory checked: @path', ['@path' => $storageScheme]);

    user_role_grant_permissions('authenticated', ['access bongolava job api']);
    $messages[] = (string) $this->t('Permission "access bongolava job api" granted to authenticated users.');

    if (\Drupal::entityTypeManager()->getStorage('user_role')->load('administrator')) {
      user_role_grant_permissions('administrator', ['administer bongolava job']);
      $messages[] = (string) $this->t('Permission "administer bongolava job" granted to administrators.');
    }

    return $messages;
  }

}
