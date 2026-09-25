<?php

namespace Drupal\country_access_filter\DTO;

/**
 * Stores the 404 request count and time window for an IP address.
 */
class Tracked404 {

  /**
   * Constructs a 404 tracking record for an IP address.
   *
   * @param \Drupal\country_access_filter\DTO\Ip $ip
   *   The tracked IP address.
   * @param int $timestamp
   *   The first 404 timestamp in the tracking window.
   * @param int $count
   *   The number of 404 responses in the tracking window.
   */
  public function __construct(
    public readonly Ip $ip,
    private readonly int $timestamp,
    private readonly int $count,
  ) {}

  /**
   * Gets the start time of the current 404 tracking window.
   *
   * @return int
   *   The first 404 timestamp, as a Unix timestamp.
   */
  public function getFirstTimestamp(): int {
    return $this->timestamp;
  }

  /**
   * Gets the number of 404 responses in the current tracking window.
   *
   * @return int
   *   The number of tracked 404 responses.
   */
  public function getCount(): int {
    return $this->count;
  }

}
