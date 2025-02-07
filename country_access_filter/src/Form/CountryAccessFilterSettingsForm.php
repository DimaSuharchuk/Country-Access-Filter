<?php

namespace Drupal\country_access_filter\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CountryAccessFilterSettingsForm extends ConfigFormBase {

  protected Connection $db;

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);

    $instance->db = $container->get('database');

    return $instance;
  }

  protected function getEditableConfigNames(): array {
    return ['country_access_filter.settings'];
  }

  public function getFormId(): string {
    return 'country_access_filter_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('country_access_filter.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable functionality'),
      '#default_value' => $config->get('enabled'),
    ];

    $form['countries'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Allowed countries'),
      '#description' => $this->t('Enter country codes (ISO 3166-1 alpha-2) separated by spaces.'),
      '#default_value' => $config->get('countries'),
      '#required' => TRUE,
    ];

    $form['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Debug mode'),
      '#default_value' => $config->get('debug_mode'),
    ];

    // IPs info.
    $query = $this->db
      ->select('country_access_filter_ips', 'i')
      ->fields('i', ['status'])
      ->groupBy('status');
    $query->addExpression('COUNT(status)');
    $counts = $query->execute()->fetchAllKeyed();

    $count_allowed = $counts[1] ?? 0;
    $count_denied = $counts[0] ?? 0;
    $count_all = $count_allowed + $count_denied;

    $form['info'] = [
      '#type' => 'details',
      '#title' => $this->t('Info'),
      '#open' => TRUE,
    ];
    $form['info']['ips_all'] = [
      '#type' => 'item',
      '#title' => $this->t('IPs count all'),
      '#markup' => $count_all,
    ];
    $form['info']['ips_allowed'] = [
      '#type' => 'item',
      '#title' => $this->t('IPs count allowed'),
      '#markup' => $count_allowed,
    ];
    $form['info']['ips_denied'] = [
      '#type' => 'item',
      '#title' => $this->t('IPs count denied'),
      '#markup' => $count_denied,
    ];

    // Countries.
    $query = $this->db
      ->select('country_access_filter_ips', 'i')
      ->fields('i', ['country_code'])
      ->groupBy('country_code');
    $query->addExpression('COUNT(country_code)');
    $countries = $query->execute()->fetchAllKeyed();

    $header = [
      'country' => $this->t('Country'),
      'count' => $this->t('IPs count'),
    ];
    $rows = [];

    foreach ($countries as $country => $count) {
      $rows[] = [
        'country' => $country,
        'count' => $count,
      ];
    }

    $form['info']['countries'] = [
      '#type' => 'details',
      '#title' => $this->t('Countries'),
    ];
    $form['info']['countries']['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $countries = explode(' ', trim($form_state->getValue('countries')));

    foreach ($countries as $country_code) {
      if (!preg_match('/^[A-Z]{2}$/', $country_code)) {
        $form_state->setErrorByName('countries', $this->t('Invalid country code: %code. Please use ISO 3166-1 alpha-2 codes.', ['%code' => $country_code]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('country_access_filter.settings')
      ->set('enabled', $form_state->getValue('enabled'))
      ->set('countries', trim($form_state->getValue('countries')))
      ->set('debug_mode', $form_state->getValue('debug_mode'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
