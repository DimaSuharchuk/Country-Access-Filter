<?php

namespace Drupal\country_access_filter\DTO;

/**
 * Defines the shared representation of a normalized IP address.
 */
interface IpInterface {

  /**
   * Gets the normalized IP address used to identify this object.
   *
   * @return string
   *   The normalized IP address.
   */
  public function getId(): string;

  /**
   * A human-readable representation of the IP address.
   *
   * @return string
   *   The address in readable form.
   */
  public function toReadable(): string;

  /**
   * Gets the packed IP address value for database storage.
   *
   * @return string
   *   The packed IP address for storage.
   */
  public function getStorableValue(): string;

}
