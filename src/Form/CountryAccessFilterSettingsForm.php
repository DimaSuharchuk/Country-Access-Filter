<?php

namespace Drupal\country_access_filter\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Locale\CountryManagerInterface;
use Drupal\country_access_filter\AccessMode;
use Drupal\country_access_filter\Controller\FormController;
use Drupal\country_access_filter\DTO\IpInput;
use Drupal\country_access_filter\Service\CountryService;
use Drupal\country_access_filter\Service\storage\IpStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds and validates the country access filter settings form.
 */
class CountryAccessFilterSettingsForm extends ConfigFormBase {

  /**
   * The service that provides the list of countries.
   */
  protected CountryManagerInterface $countries;

  /**
   * The service that evaluates country access rules.
   */
  protected CountryService $countryService;

  /**
   * The storage service for IP access decisions.
   */
  protected IpStorage $ipStorage;

  /**
   * The controller that builds shared IP tables and dialog titles.
   */
  protected FormController $ipController;

  /**
   * Creates the settings form with its required module services.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   *
   * @return static
   *   The initialized settings form.
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);

    $instance->countries = $container->get('country_manager');
    $instance->countryService = $container->get('country_access_filter.country_service');
    $instance->ipStorage = $container->get('country_access_filter.ip_storage');
    $instance->ipController = FormController::create($container);

    return $instance;
  }

  /**
   * {@inheritdoc}
   *
   * @return string[]
   *   The configuration object edited by this form.
   */
  protected function getEditableConfigNames(): array {
    return ['country_access_filter.settings'];
  }

  /**
   * {@inheritdoc}
   *
   * @return string
   *   The unique form ID.
   */
  public function getFormId(): string {
    return 'country_access_filter_settings_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form structure to build.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array
   *   The completed settings form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('country_access_filter.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable functionality'),
      '#default_value' => $config->get('enabled'),
    ];

    $form['ip_search'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Find an IP address'),
      '#description' => $this->t('Find a stored IPv4 or IPv6 address, or look up and add a new one using the saved country rules.'),
      '#attached' => ['library' => ['country_access_filter/ip_search']],
    ];
    $form['ip_search']['row'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['caf-ip-search']],
    ];
    $form['ip_search']['row']['ip_search_address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('IP address'),
      '#title_display' => 'invisible',
      '#attributes' => ['placeholder' => $this->t('IPv4 or IPv6 address')],
      '#size' => 45,
      '#maxlength' => 45,
    ];
    $form['ip_search']['row']['search'] = [
      '#type' => 'submit',
      '#value' => $this->t('Find IP'),
      '#name' => 'ip_search',
      '#submit' => ['::submitIpSearch'],
      '#limit_validation_errors' => [['ip_search_address']],
      '#ajax' => ['callback' => '::ipSearchAjaxCallback'],
    ];
    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';

    $form['countries_wrapper'] = [
      '#type' => 'fieldset',
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['countries_wrapper']['country_access_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Country access mode'),
      '#options' => [
        AccessMode::ALLOW->value => $this->t('Allow selected countries'),
        AccessMode::DENY->value => $this->t('Block selected countries'),
      ],
      '#default_value' => $config->get('country_access_mode') ?? AccessMode::ALLOW->value,
    ];

    $options = $this->countries->getList();
    uasort($options, static fn($a, $b) => strnatcasecmp((string) $a, (string) $b));
    $selected = preg_split('/\s+/', trim((string) $config->get('countries')), -1, PREG_SPLIT_NO_EMPTY);
    $selected_options = array_intersect_key($options, array_flip($selected));
    $other_options = array_diff_key($options, $selected_options);

    $form['countries_wrapper']['countries'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Countries'),
      '#default_value' => array_keys($selected_options),
      '#options' => $selected_options + $other_options,
      '#description' => $this->t('Selected countries follow the access mode above. Saved selections appear first; new selections move to the top after saving.'),
      '#prefix' => '<div class="caf-country-picker">',
      '#suffix' => '</div>',
      '#attached' => ['library' => ['country_access_filter/country_picker']],
    ];

    if ($selected_options && $other_options) {
      $form['countries_wrapper']['countries'][array_key_first($other_options)]['#wrapper_attributes']['class'][] = 'caf-country-picker__other';
    }

    $form['track_404_wrapper'] = [
      '#type' => 'fieldset',
      '#states' => [
        'visible' => [
          ':input[name="enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['track_404_wrapper']['track_404'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable 404 tracking and auto-ban'),
      '#default_value' => $config->get('track_404'),
    ];

    $form['track_404_wrapper']['track_404_threshold'] = [
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

    $form['track_404_wrapper']['track_404_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Time window (in seconds) to accumulate 404s'),
      '#default_value' => $config->get('track_404_window'),
      '#min' => 1,
      '#max' => 356400,
      '#description' => $this->t('Optional. Leave empty to allow unlimited time for reaching threshold. IPs will be banned once the threshold of 404 responses is reached, regardless of how much time has passed.'),
      '#states' => [
        'invisible' => [
          [':input[name="enabled"]' => ['checked' => FALSE]],
          [':input[name="track_404"]' => ['checked' => FALSE]],
        ],
      ],
    ];

    // IPs info.
    [$count_denied, $count_allowed] = $this->ipStorage->getIpAccessCounts();
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
    $countries = $this->ipStorage->getCountryStats();

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

      $country_allowed = $this->countryService->isCountryAllowed($country);

      if ($country_allowed) {
        $rows[$country]['class'][] = 'allowed';
      }

      if (
        $country_allowed && $item->denied
        || !$country_allowed && $item->allowed
      ) {
        $rows[$country]['class'][] = 'mixed';
      }
    }

    $form['info']['countries'] = [
      '#type' => 'details',
      '#title' => $this->t('Countries'),
      '#open' => FALSE,
    ];
    $form['info']['countries']['table_legend'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Countries table legend'),
      '#items' => [
        [
          '#markup' => '<span class="access-legend-box access-denied"></span> ' . $this->t('Country access denied, all IPs denied by default.'),
        ],
        [
          '#markup' => '<span class="access-legend-box access-allowed"></span> ' . $this->t('Country access allowed, all IPs allowed by default.'),
        ],
        [
          '#markup' => '<span class="access-legend-box access-mixed-denied"></span> ' . $this->t('Country access denied, with some IPs allowed manually.'),
        ],
        [
          '#markup' => '<span class="access-legend-box access-mixed-allowed"></span> ' . $this->t('Country access allowed, with some IPs denied manually.'),
        ],
      ],
      '#attributes' => ['class' => ['access-table-legend']],
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
   * Looks up an IP without submitting the country settings form.
   *
   * @param array $form
   *   The settings form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The submitted form state.
   */
  public function submitIpSearch(array &$form, FormStateInterface $form_state): void {
    $input = $form_state->getValue('ip_search_address', '');
    $ip_input = new IpInput(is_string($input) ? trim($input) : '');
    $form_state->set('ip_search_result', NULL);
    $form_state->set('ip_search_error', NULL);

    if (!$ip_input->isValid()) {
      $form_state->set('ip_search_error', $this->t('Enter a valid IPv4 or IPv6 address.'));
    }
    else {
      $this->countryService->hasAccess($ip_input);
      $ip = $this->ipStorage->load($ip_input);
      $form_state->set('ip_search_result', $ip);

      if (!$ip) {
        $form_state->set('ip_search_error', $this->t('The IP address could not be looked up or saved. Please try again.'));
      }
    }

    $form_state->setRebuild();
  }

  /**
   * Opens a one-row IP dialog or reports a failed search.
   *
   * @param array $form
   *   The rebuilt settings form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state containing the search result.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The modal dialog or error message commands.
   */
  public function ipSearchAjaxCallback(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    if ($error = $form_state->get('ip_search_error')) {
      return $response->addCommand(new MessageCommand($error, NULL, ['type' => 'error']));
    }

    if ($ip = $form_state->get('ip_search_result')) {
      $response->addCommand(new OpenModalDialogCommand(
        $this->ipController->countryDetailsTitle($ip->getCountryCode()),
        $this->ipController->buildIpTable([$ip]),
        ['width' => 800],
      ));
    }

    return $response;
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The form structure being validated.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The submitted form state.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (array_filter($form_state->getValue('countries', [])) as $country_code) {
      if (!preg_match('/^[A-Z]{2}$/', $country_code)) {
        $form_state->setErrorByName('countries', $this->t('Invalid country code: %code. Please use ISO 3166-1 alpha-2 codes.', ['%code' => $country_code]));
      }
    }

    if (
      $form_state->getValue('track_404')
      && ((int) $form_state->getValue('track_404_threshold') < 1)
    ) {
      $form_state->setErrorByName('track_404_threshold', $this->t('@name field is required.', ['@name' => $form['track_404_wrapper']['track_404_threshold']['#title']]));
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array $form
   *   The submitted form structure.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The submitted form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('country_access_filter.settings');

    $access_mode = $form_state->getValue('country_access_mode');
    $is_access_allowed = $access_mode == AccessMode::ALLOW->value;

    $selected_countries = array_filter($form_state->getValue('countries', []));

    $config
      ->set('enabled', $form_state->getValue('enabled'))
      ->set('countries', implode(' ', $selected_countries))
      ->set('country_access_mode', $access_mode)
      ->set('track_404', $form_state->getValue('track_404'))
      ->set('track_404_threshold', $form_state->getValue('track_404_threshold'))
      ->set('track_404_window', $form_state->getValue('track_404_window'))
      ->save();

    if (!$selected_countries && $is_access_allowed) {
      $this->messenger()
        ->addWarning($this->t('No country has been selected; the site will be available only to logged-in users with an active session.'));
    }

    parent::submitForm($form, $form_state);
  }

}
