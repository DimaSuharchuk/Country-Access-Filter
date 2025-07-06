<?php

namespace Drupal\country_access_filter\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CountryAccessFilterSettingsForm extends ConfigFormBase {

  protected ?Connection $db;

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container): static {
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
    $countries_raw = $config->get('countries');
    $countries_allowed = explode(' ', $countries_raw);
    $countries_allowed = array_combine($countries_allowed, $countries_allowed);

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable functionality'),
      '#default_value' => $config->get('enabled'),
    ];

    $form['countries'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Allowed countries'),
      '#description' => $this->t('Enter country codes (ISO 3166-1 alpha-2) separated by spaces.'),
      '#default_value' => $countries_raw,
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['track_404'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable 404 tracking and auto-ban'),
      '#default_value' => $config->get('track_404'),
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['track_404_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Number of 404 responses to trigger ban'),
      '#default_value' => $config->get('track_404_threshold'),
      '#min' => 1,
      '#max' => 255,
      '#states' => [
        'invisible' => [
          [':input[name="enabled"]' => ['checked' => FALSE]],
          [':input[name="track_404"]' => ['checked' => FALSE]],
        ],
      ],
    ];

    $form['track_404_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Time window (in hours) to accumulate 404s'),
      '#default_value' => $config->get('track_404_window'),
      '#min' => 1,
      '#max' => 99,
      '#description' => $this->t('Optional. Leave empty to allow unlimited time for reaching threshold. IPs will be banned once the threshold of 404 responses is reached, regardless of how much time has passed.'),
      '#states' => [
        'invisible' => [
          [':input[name="enabled"]' => ['checked' => FALSE]],
          [':input[name="track_404"]' => ['checked' => FALSE]],
        ],
      ],
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
    $query->addExpression('COUNT(country_code)', 'count');
    $query->addExpression('SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END)', 'allowed');
    $query->addExpression('SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END)', 'denied');
    $countries = $query->execute()->fetchAll();

    $header = [
      'country' => $this->t('Country'),
      'count' => $this->t('IPs count'),
      'actions' => $this->t('Actions'),
    ];
    $rows = [];

    foreach ($countries as $item) {
      $country = $item->country_code;

      $rows[$country] = [
        'data' => [
          'country' => $country,
          'count' => $this->t('@count (allowed @allowed, denied @denied)', [
            '@count' => $item->count,
            '@allowed' => $item->allowed,
            '@denied' => $item->denied,
          ]),
          'actions' => Link::createFromRoute($this->t('Details'), 'country_access_filter.form.country.details', ['country' => $country], [
            'attributes' => [
              'class' => [
                'use-ajax',
                'caf-action',
              ],
              'data-dialog-type' => 'modal',
              'data-dialog-options' => '{"width":800}',
            ],
          ]),
        ],
        'data-country' => $country,
      ];

      if (array_key_exists($country, $countries_allowed)) {
        $rows[$country]['class'][] = 'allowed';
      }
    }

    $form['info']['countries'] = [
      '#type' => 'details',
      '#title' => $this->t('Countries'),
      '#open' => TRUE,
    ];
    $form['info']['countries']['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#attributes' => [
        'id' => 'country-access-table',
      ],
      '#attached' => [
        'library' => [
          'country_access_filter/style',
        ],
      ],
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

    if (
      $form_state->getValue('track_404')
      && ((int) $form_state->getValue('track_404_threshold') < 1)
    ) {
      $form_state->setErrorByName('track_404_threshold', $this->t('@name field is required.', ['@name' => $form['track_404_threshold']['#title']]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('country_access_filter.settings');

    $old_countries_raw = $config->get('countries');
    $new_countries_raw = trim($form_state->getValue('countries'));
    $old_countries = explode(' ', $old_countries_raw);
    $new_countries = explode(' ', $new_countries_raw);

    $added_countries = array_diff($new_countries, $old_countries);
    $removed_countries = array_diff($old_countries, $new_countries);

    $config
      ->set('enabled', $form_state->getValue('enabled'))
      ->set('countries', $new_countries_raw)
      ->set('track_404', $form_state->getValue('track_404'))
      ->set('track_404_threshold', $form_state->getValue('track_404_threshold'))
      ->set('track_404_window', $form_state->getValue('track_404_window'))
      ->save();

    if ($added_countries) {
      $this->db->update('country_access_filter_ips')
        ->fields(['status' => 1])
        ->condition('country_code', $added_countries, 'IN')
        ->execute();
    }

    if ($removed_countries) {
      $this->db->update('country_access_filter_ips')
        ->fields(['status' => 0])
        ->condition('country_code', $removed_countries, 'IN')
        ->execute();
    }

    parent::submitForm($form, $form_state);
  }

}
