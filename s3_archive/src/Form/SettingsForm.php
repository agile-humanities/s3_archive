<?php

declare(strict_types=1);

namespace Drupal\s3_archive\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure S3 Archive settings for this site.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 's3_archive_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['s3_archive.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['s3_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base URL for S3 bucket'),
      '#default_value' => $this->config('s3_archive.settings')->get('s3_url'),
    ];
    return parent::buildForm($form, $form_state);
  }


  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $url = rtrim($form_state->getValue('s3_url'), '/');
    $this->config('s3_archive.settings')
      ->set('s3_url', $url)
      ->save();
    parent::submitForm($form, $form_state);
  }

}
