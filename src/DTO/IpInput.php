<?php

namespace Drupal\country_access_filter\DTO;

/**
 * Normalizes and validates an IP address supplied by a request.
 */
class IpInput implements IpInterface {

  private readonly string $packed;

  private readonly string $normalized;

  private readonly bool $isValid;

  /**
   * Constructs an input value from an untrusted IP address.
   *
   * @param mixed $ip
   *   The input to validate as an IP address.
   */
  public function __construct(private readonly mixed $ip) {}

  /** {@inheritdoc} */
  public function getId(): string {
    return $this->normalized ??= $this->unpack();
  }

  /** {@inheritdoc} */
  public function toReadable(): string {
    return $this->getId();
  }

  /** {@inheritdoc} */
  public function getStorableValue(): string {
    return $this->packed ??= $this->pack();
  }

  /**
   * Determines whether the input contains a valid IP address.
   *
   * @return bool
   *   TRUE if the input is a valid IPv4 or IPv6 address, or FALSE otherwise.
   */
  public function isValid(): bool {
    return $this->isValid ??= (filter_var($this->ip, FILTER_VALIDATE_IP) !== FALSE && $this->getId() !== '');
  }

  /**
   * Converts the input address to its packed binary representation.
   *
   * @return string
   *   The packed address, or an empty string if packing fails.
   */
  private function pack(): string {
    if (!is_string($this->ip)) {
      return '';
    }

    $packed = inet_pton($this->ip);

    if ($packed === FALSE) {
      return '';
    }

    // Normalize IPv4-mapped IPv6 addresses to regular 4-byte IPv4.
    if (
      strlen($packed) === 16
      && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff"
    ) {
      return substr($packed, 12);
    }

    return $packed;
  }

  /**
   * Converts the packed input address to normalized text.
   *
   * @return string
   *   The normalized address, or an empty string if conversion fails.
   */
  private function unpack(): string {
    return inet_ntop($this->getStorableValue()) ?: '';
  }

}
