<?php

declare(strict_types=1);

namespace Drupal\shortlink_manager\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure shortlink expiration settings.
 */
final class ShortlinkExpirationSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'shortlink_manager_expiration_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['shortlink_manager.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('shortlink_manager.settings');

    $form['expiration'] = [
      '#type' => 'details',
      '#title' => $this->t('Expiration settings'),
      '#open' => TRUE,
    ];

    $form['expiration']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable expiration cron processing'),
      '#description' => $this->t('When enabled, expired shortlinks will be automatically disabled during cron runs.'),
      '#default_value' => $config->get('expiration.enabled') ?? FALSE,
    ];

    $form['expiration']['default_expiration_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Default expiration method'),
      '#description' => $this->t('Select the default expiration method for new shortlinks.'),
      '#options' => [
        'none' => $this->t('None'),
        'time' => $this->t('Expire after a number of days'),
        'max_clicks' => $this->t('Expire after maximum clicks reached'),
        'inactive' => $this->t('Expire after days of inactivity'),
      ],
      '#default_value' => $config->get('expiration.default_expiration_type') ?? 'none',
    ];

    $form['expiration']['default_expire_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Default expiration days'),
      '#description' => $this->t('Number of days after which new shortlinks expire.'),
      '#default_value' => $config->get('expiration.default_expire_days') ?? 0,
      '#min' => 1,
      '#states' => [
        'visible' => [
          ':input[name="default_expiration_type"]' => ['value' => 'time'],
        ],
        'required' => [
          ':input[name="default_expiration_type"]' => ['value' => 'time'],
        ],
      ],
    ];

    $form['expiration']['default_max_clicks'] = [
      '#type' => 'number',
      '#title' => $this->t('Default maximum clicks'),
      '#description' => $this->t('Maximum number of clicks allowed before the shortlink expires.'),
      '#default_value' => $config->get('expiration.default_max_clicks') ?? 0,
      '#min' => 1,
      '#states' => [
        'visible' => [
          ':input[name="default_expiration_type"]' => ['value' => 'max_clicks'],
        ],
        'required' => [
          ':input[name="default_expiration_type"]' => ['value' => 'max_clicks'],
        ],
      ],
    ];

    $form['expiration']['default_inactive_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Default inactive days'),
      '#description' => $this->t('Number of days of inactivity (no clicks) after which the shortlink expires.'),
      '#default_value' => $config->get('expiration.default_inactive_days') ?? 0,
      '#min' => 1,
      '#states' => [
        'visible' => [
          ':input[name="default_expiration_type"]' => ['value' => 'inactive'],
        ],
        'required' => [
          ':input[name="default_expiration_type"]' => ['value' => 'inactive'],
        ],
      ],
    ];

    $form['click_log'] = [
      '#type' => 'details',
      '#title' => $this->t('Click log retention'),
      '#open' => TRUE,
    ];

    $form['click_log']['click_log_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Click log retention (days)'),
      '#description' => $this->t('Number of days to keep individual click log records. Set to 0 to keep all records indefinitely.'),
      '#default_value' => $config->get('expiration.click_log_retention_days') ?? 90,
      '#min' => 0,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $expiration_type = $form_state->getValue('default_expiration_type');

    // Only save the value for the selected expiration type; zero out the rest.
    $expire_days = $expiration_type === 'time' ? (int) $form_state->getValue('default_expire_days') : 0;
    $max_clicks = $expiration_type === 'max_clicks' ? (int) $form_state->getValue('default_max_clicks') : 0;
    $inactive_days = $expiration_type === 'inactive' ? (int) $form_state->getValue('default_inactive_days') : 0;

    $this->config('shortlink_manager.settings')
      ->set('expiration.enabled', (bool) $form_state->getValue('enabled'))
      ->set('expiration.default_expiration_type', $expiration_type)
      ->set('expiration.default_expire_days', $expire_days)
      ->set('expiration.default_max_clicks', $max_clicks)
      ->set('expiration.default_inactive_days', $inactive_days)
      ->set('expiration.click_log_retention_days', (int) $form_state->getValue('click_log_retention_days'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
