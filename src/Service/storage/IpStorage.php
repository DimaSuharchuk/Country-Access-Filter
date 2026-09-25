<?php

namespace Drupal\country_access_filter\Service\storage;

use Drupal\Core\Database\Connection;
use Drupal\country_access_filter\DTO\Ip;
use Drupal\country_access_filter\DTO\IpInput;
use Drupal\country_access_filter\DTO\IpInterface;
use Drupal\country_access_filter\IpAccess;
use Exception;

/**
 * Loads and persists country access decisions for IP addresses.
 */
class IpStorage {

  /**
   * The name of the table that stores IP access decisions.
   *
   * @var string
   */
  const TABLE = 'country_access_filter_ips';

  /**
   * Loaded IP addresses keyed by their normalized address.
   *
   * @var \Drupal\country_access_filter\DTO\Ip[]
   */
  protected array $list;

  /**
   * Constructs the IP storage service.
   *
   * @param \Drupal\Core\Database\Connection $db
   *   The database connection.
   */
  public function __construct(protected Connection $db) {
    $list = &drupal_static(static::class . '_list', []);
    $this->list ??= $list;
  }

  /**
   * Loads an IP address if it exists in storage.
   *
   * @param \Drupal\country_access_filter\DTO\IpInput $ip
   *   The IP address to load.
   *
   * @return \Drupal\country_access_filter\DTO\Ip|null
   *   The stored IP address, or NULL if it is invalid or not found.
   */
  public function load(IpInput $ip): ?Ip {
    if (!$ip->isValid()) {
      return NULL;
    }

    return $this->loadMultiple([$ip])[$ip->getId()] ?? NULL;
  }

  /**
   * Loads stored IP addresses that match the given input objects.
   *
   * @param IpInterface[] $ips
   *   The IP addresses to load.
   *
   * @return \Drupal\country_access_filter\DTO\Ip[]
   *   The stored IP addresses keyed by normalized address.
   */
  public function loadMultiple(array $ips): array {
    $valid_ips = [];

    foreach ($ips as $ip) {
      if ($key = $ip->getId()) {
        $valid_ips[$key] = $ip;
      }
    }

    if (!$valid_ips) {
      return [];
    }

    $to_load = array_filter($valid_ips, fn($key) => !isset($this->list[$key]), ARRAY_FILTER_USE_KEY);

    if ($to_load) {
      try {
        $query = $this->db->select(static::TABLE, 'i')
          ->fields('i')
          ->condition('ip', array_map(fn(IpInterface $ip): string => $ip->getStorableValue(), $to_load), 'IN');

        foreach ($query->execute() as $object) {
          $ip = new Ip($object->ip, $object->access == 1 ? IpAccess::Allowed : IpAccess::Denied, $object->country_code);
          $this->list[$ip->getId()] = $ip;
        }
      }
      catch (Exception) {
      }
    }

    return array_intersect_key($this->list, $valid_ips);
  }

  /**
   * Loads all stored IP addresses associated with a country.
   *
   * @param string $country
   *   The ISO 3166-1 alpha-2 country code.
   *
   * @return \Drupal\country_access_filter\DTO\Ip[]
   *   The stored IP addresses keyed by normalized address.
   */
  public function loadByCountry(string $country): array {
    return $this->loadMultiple($this->getIpsByProperty('country_code', $country));
  }

  /**
   * Loads stored IP addresses that match a database field value.
   *
   * @param string $property
   *   The database field to match. The IP field is not supported.
   * @param string|string[] $values
   *   One value or a list of values to match.
   *
   * @return \Drupal\country_access_filter\DTO\Ip[]
   *   The matching IP addresses keyed by normalized address.
   */
  public function getIpsByProperty(string $property, string|array $values): array {
    if ($property === 'ip') {
      return [];
    }

    $ips = [];

    try {
      $query = $this->db->select(static::TABLE, 'i')
        ->fields('i')
        ->condition($property, $values, is_array($values) ? 'IN' : '=');

      foreach ($query->execute() as $object) {
        $ip = new Ip($object->ip, $object->access == 1 ? IpAccess::Allowed : IpAccess::Denied, $object->country_code);
        $this->list[$ip->getId()] = $ips[$ip->getId()] = $ip;
      }
    }
    catch (Exception) {
    }

    return $ips;
  }

  /**
   * Saves or replaces an IP access decision.
   *
   * @param \Drupal\country_access_filter\DTO\Ip $ip
   *   The IP address and access decision to save.
   *
   * @return bool
   *   TRUE if the IP address was saved, or FALSE if storage failed.
   */
  public function save(Ip $ip): bool {
    try {
      $this->db->merge(static::TABLE)
        ->keys([
          'ip' => $ip->getStorableValue(),
        ])
        ->fields([
          'access' => (int) ($ip->isAllowed()),
          'country_code' => $ip->getCountryCode(),
        ])
        ->execute();

      $this->list[$ip->getId()] = $ip;
    }
    catch (Exception) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Allows the given IP address.
   *
   * @param \Drupal\country_access_filter\DTO\Ip $ip
   *   The IP address to allow.
   *
   * @return bool
   *   TRUE if the access decision was saved, or FALSE otherwise.
   */
  public function allow(Ip $ip): bool {
    return $this->save(new Ip($ip->getStorableValue(), IpAccess::Allowed, $ip->getCountryCode()));
  }

  /**
   * Denies the given IP address.
   *
   * @param \Drupal\country_access_filter\DTO\Ip $ip
   *   The IP address to deny.
   *
   * @return bool
   *   TRUE if the access decision was saved, or FALSE otherwise.
   */
  public function deny(Ip $ip): bool {
    return $this->save(new Ip($ip->getStorableValue(), IpAccess::Denied, $ip->getCountryCode()));
  }

  /**
   * Deletes an IP address from storage.
   *
   * @param \Drupal\country_access_filter\DTO\Ip $ip
   *   The IP address to delete.
   *
   * @return bool
   *   TRUE if the delete succeeded, or FALSE otherwise.
   */
  public function delete(Ip $ip): bool {
    try {
      $this->db->delete(static::TABLE)
        ->condition('ip', $ip->getStorableValue())
        ->execute();

      unset($this->list[$ip->getId()]);

      return TRUE;
    }
    catch (Exception) {
    }

    return FALSE;
  }

  /**
   * Updates access decisions for the specified countries.
   *
   * @param string[] $countries
   *   The country codes whose access decisions should be updated.
   * @param \Drupal\country_access_filter\IpAccess $access
   *   The access decision to apply.
   */
  public function updateAccessByCountries(array $countries, IpAccess $access): void {
    try {
      $this->db->update(static::TABLE)
        ->fields(['access' => (int) ($access === IpAccess::Allowed)])
        ->condition('country_code', $countries, 'IN')
        ->execute();

      foreach ($this->list as $key => $ip) {
        if (in_array($ip->getCountryCode(), $countries, TRUE)) {
          unset($this->list[$key]);
        }
      }
    }
    catch (Exception) {
    }
  }

  /**
   * Counts the stored allowed and denied IP addresses.
   *
   * @return int[]
   *   Counts keyed by access value: 0 for denied and 1 for allowed.
   */
  public function getIpAccessCounts(): array {
    $counts = [
      0 => 0,
      1 => 0,
    ];

    $query = $this->db
      ->select(static::TABLE, 'i')
      ->fields('i', ['access'])
      ->groupBy('access');
    $query->addExpression('COUNT(access)', 'count');

    try {
      foreach ($query->execute()->fetchAllKeyed() as $access => $count) {
        $counts[$access] += $count;
      }
    }
    catch (Exception) {
    }

    return $counts;
  }

  /**
   * Gets stored IP counts and access totals grouped by country.
   *
   * @return object[]
   *   Statistics keyed by country code. Each object contains country_code,
   *   count, allowed, and denied properties.
   */
  public function getCountryStats(): array {
    $countries = [];

    $query = $this->db
      ->select(static::TABLE, 'i')
      ->fields('i', ['country_code'])
      ->groupBy('country_code');
    $query->addExpression('COUNT(country_code)', 'count');
    $query->addExpression('SUM(CASE WHEN access = 1 THEN 1 ELSE 0 END)', 'allowed');
    $query->addExpression('SUM(CASE WHEN access = 0 THEN 1 ELSE 0 END)', 'denied');

    try {
      foreach ($query->execute()->fetchAll() as $item) {
        $country = $item->country_code;

        if (!isset($countries[$country])) {
          $countries[$country] = (object) [
            'country_code' => $country,
            'count' => 0,
            'allowed' => 0,
            'denied' => 0,
          ];
        }

        $countries[$country]->count += $item->count;
        $countries[$country]->allowed += $item->allowed;
        $countries[$country]->denied += $item->denied;
      }
    }
    catch (Exception) {
    }

    return $countries;
  }

}
