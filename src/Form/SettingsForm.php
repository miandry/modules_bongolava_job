<?php

namespace Drupal\bongolava_job\Form;

use Drupal\bongolava_job\Service\SchemaInstaller;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bongolava Jobs settings and manual schema install.
 */
class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    protected SchemaInstaller $schemaInstaller,
  ) {
    $this->configFactory = $config_factory;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('bongolava_job.schema_installer'),
    );
  }

  protected function getEditableConfigNames(): array {
    return ['bongolava_job.settings'];
  }

  public function getFormId(): string {
    return 'bongolava_job_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $cfg = $this->config('bongolava_job.settings');

    $form['api'] = [
      '#type' => 'details',
      '#title' => $this->t('API'),
      '#open' => TRUE,
    ];
    $form['api']['api_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API name'),
      '#default_value' => $cfg->get('api.name') ?? 'Bongolava Jobs API',
    ];
    $form['api']['api_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API version'),
      '#default_value' => $cfg->get('api.version') ?? '1.0',
      '#size' => 10,
    ];
    $form['api']['base_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base path'),
      '#default_value' => $cfg->get('api.base_path') ?? '/bongolava_job',
      '#description' => $this->t('URL prefix for REST routes (e.g. /bongolava_job/login).'),
      '#required' => TRUE,
    ];
    $form['api']['storage_scheme'] = [
      '#type' => 'textfield',
      '#title' => $this->t('File storage scheme'),
      '#default_value' => $cfg->get('api.storage_scheme') ?? 'public://bongolava_job',
    ];

    $form['pagination'] = [
      '#type' => 'details',
      '#title' => $this->t('Pagination'),
      '#open' => TRUE,
    ];
    $form['pagination']['token_ttl_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Bearer token TTL (days)'),
      '#default_value' => $cfg->get('token_ttl_days') ?? 30,
      '#min' => 0,
    ];
    $form['pagination']['cookie_secure_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Auth cookie Secure flag'),
      '#default_value' => $cfg->get('cookie_secure_mode') ?? 'auto',
      '#options' => [
        'auto' => $this->t('Automatic (HTTPS only)'),
        'insecure' => $this->t('Disabled (HTTP development)'),
        'secure' => $this->t('Always enabled (production HTTPS)'),
      ],
      '#description' => $this->t('Controls the Secure attribute on the bongolava_auth cookie. Use "Disabled" for local HTTP development (e.g. http://bongolava.local).'),
    ];
    $form['pagination']['jobs_per_page'] = [
      '#type' => 'number',
      '#title' => $this->t('Jobs per page'),
      '#default_value' => $cfg->get('jobs_per_page') ?? 15,
      '#min' => 1,
    ];
    $form['pagination']['candidates_per_page'] = [
      '#type' => 'number',
      '#title' => $this->t('Candidates per page'),
      '#default_value' => $cfg->get('candidates_per_page') ?? 15,
      '#min' => 1,
    ];

    $form['database'] = [
      '#type' => 'details',
      '#title' => $this->t('Database install'),
      '#open' => TRUE,
      '#description' => $this->t('Run <code>bongolava_job.install</code> schema manually (creates missing tables only; existing tables are not altered).'),
    ];

    $status = $this->schemaInstaller->tableStatus();
    $rows = [];
    foreach ($status as $table => $exists) {
      $rows[] = [
        $table,
        $exists
          ? $this->t('Exists')
          : $this->t('Missing'),
      ];
    }
    $form['database']['status'] = [
      '#type' => 'table',
      '#header' => [$this->t('Table'), $this->t('Status')],
      '#rows' => $rows,
      '#empty' => $this->t('No tables defined.'),
    ];

    $form['database']['install_schema'] = [
      '#type' => 'submit',
      '#value' => $this->t('Install database tables'),
      '#submit' => ['::submitInstallSchema'],
      '#limit_validation_errors' => [],
    ];

    $form['database']['post_install'] = [
      '#type' => 'submit',
      '#value' => $this->t('Run post-install tasks'),
      '#submit' => ['::submitPostInstall'],
      '#limit_validation_errors' => [],
      '#description' => $this->t('Creates upload directory and grants API permission to authenticated users.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $editable = $this->configFactory->getEditable('bongolava_job.settings');
    $editable
      ->set('api.name', trim($form_state->getValue('api_name')))
      ->set('api.version', trim($form_state->getValue('api_version')))
      ->set('api.base_path', trim($form_state->getValue('base_path')))
      ->set('api.storage_scheme', trim($form_state->getValue('storage_scheme')))
      ->set('token_ttl_days', (int) $form_state->getValue('token_ttl_days'))
      ->set('cookie_secure_mode', $form_state->getValue('cookie_secure_mode'))
      ->set('jobs_per_page', (int) $form_state->getValue('jobs_per_page'))
      ->set('candidates_per_page', (int) $form_state->getValue('candidates_per_page'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  public function submitInstallSchema(array &$form, FormStateInterface $form_state): void {
    $result = $this->schemaInstaller->installTables();

    if ($result['created']) {
      $this->messenger()->addStatus($this->t('Created tables: @tables', [
        '@tables' => implode(', ', $result['created']),
      ]));
    }
    if ($result['skipped']) {
      $this->messenger()->addWarning($this->t('Already exist (skipped): @tables', [
        '@tables' => implode(', ', $result['skipped']),
      ]));
    }
    foreach ($result['errors'] as $error) {
      $this->messenger()->addError($error);
    }
    if (!$result['created'] && !$result['errors'] && $result['skipped']) {
      $this->messenger()->addStatus($this->t('All tables are already installed.'));
    }

    $form_state->setRebuild();
  }

  public function submitPostInstall(array &$form, FormStateInterface $form_state): void {
    $scheme = $this->config('bongolava_job.settings')->get('api.storage_scheme') ?? 'public://bongolava_job';
    foreach ($this->schemaInstaller->runPostInstallTasks($scheme) as $message) {
      $this->messenger()->addStatus($message);
    }
    $form_state->setRebuild();
  }

}
