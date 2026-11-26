<?php

namespace Drupal\country_access_filter\Service;

use Drupal\Core\State\StateInterface;

/**
 * Stores instance-specific settings outside configuration synchronization.
 */
class Settings {

  public const KEY = 'country_access_filter.settings';

  public const DEFAULTS = [
    'enabled' => TRUE,
    'countries' => 'UA',
    'country_access_mode' => 'allow',
    'track_404' => FALSE,
    'track_404_threshold' => 5,
    'track_404_window' => 3600,
  ];

  public function __construct(protected StateInterface $state) {}

  /**
   * Reads a setting from this instance.
   */
  public function get(string $key): mixed {
    $values = $this->state->get(self::KEY, self::DEFAULTS);

    return $values[$key] ?? self::DEFAULTS[$key] ?? NULL;
  }

  /**
   * Updates one setting, preserving the other instance settings.
   */
  public function set(string $key, mixed $value): static {
    $values = $this->state->get(self::KEY, self::DEFAULTS);
    $values[$key] = $value;
    $this->state->set(self::KEY, $values);

    return $this;
  }

}
