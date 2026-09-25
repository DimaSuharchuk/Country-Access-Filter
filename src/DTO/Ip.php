<?php

namespace Drupal\country_access_filter\DTO;

use Drupal\country_access_filter\IpAccess;

/**
 * Represents a validated IP address and its access decision.
 */
class Ip implements IpInterface {

  private readonly string $ipUnpacked;

  /**
   * Constructs an IP value with its access decision and country code.
   *
   * @param string $ipPacked
   *   The packed IPv4 or IPv6 address.
   * @param \Drupal\country_access_filter\IpAccess $access
   *   The access decision for this IP address.
   * @param string $countryCode
   *   The ISO 3166-1 alpha-2 country code.
   * @param bool $accessLocked
   *   Whether country configuration must leave the access decision unchanged.
   */
  public function __construct(
    private readonly string $ipPacked,
    private readonly IpAccess $access,
    private readonly string $countryCode,
    private readonly bool $accessLocked = FALSE,
  ) {}

  /** {@inheritdoc} */
  public function getId(): string {
    return $this->ipUnpacked ??= inet_ntop($this->ipPacked);
  }

  /** {@inheritdoc} */
  public function toReadable(): string {
    return $this->getId();
  }

  /** {@inheritdoc} */
  public function getStorableValue(): string {
    return $this->ipPacked;
  }

  /**
   * Determines whether access is allowed for this IP address.
   *
   * @return bool
   *   TRUE if the IP address is allowed, or FALSE otherwise.
   */
  public function isAllowed(): bool {
    return $this->getAccess() === IpAccess::Allowed;
  }

  /**
   * Determines whether the access decision is independent of country rules.
   *
   * @return bool
   *   TRUE for a manual decision or an automatic 404 ban.
   */
  public function isAccessLocked(): bool {
    return $this->accessLocked;
  }

  /**
   * Gets the access decision for this IP address.
   *
   * @return \Drupal\country_access_filter\IpAccess
   *   The access decision.
   */
  public function getAccess(): IpAccess {
    return $this->access;
  }

  /**
   * Gets the ISO 3166-1 alpha-2 country code for this IP address.
   *
   * @return string
   *   The country code.
   */
  public function getCountryCode(): string {
    return $this->countryCode;
  }

}
