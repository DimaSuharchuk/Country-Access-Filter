<?php

namespace Drupal\country_access_filter\Service\storage;

use Drupal\Core\Database\Connection;
use Drupal\country_access_filter\DTO\Ip;
use Drupal\country_access_filter\DTO\Tracked404;
use Exception;

/**
 * Loads and persists IP addresses tracked for repeated 404 responses.
 */
class Tracker404Storage {

  /**
   * The name of the table that stores 404 tracking data.
   *
   * @var string
   */
  const TABLE = 'country_access_404_tracker';

  /**
   * Loaded trackers keyed by their normalized IP address.
   *
   * @var \Drupal\country_access_filter\DTO\Tracked404[]
   */
  protected array $list;

  /**
   * Constructs the 404 tracker storage service.
   *
   * @param \Drupal\Core\Database\Connection $db
   *   The database connection.
   */
  public function __construct(protected Connection $db) {
    $list = &drupal_static(static::class . '_list', []);
    $this->list ??= $list;
  }

  /**
   * Loads the 404 tracker for an IP address.
   *
   * @param \Drupal\country_access_filter\DTO\Ip $ip
   *   The IP address to look up.
   *
   * @return \Drupal\country_access_filter\DTO\Tracked404|null
   *   The tracker, or NULL if it does not exist.
   */
  public function load(Ip $ip): ?Tracked404 {
    if (!$ip->getId()) {
      return NULL;
    }

    if (isset($this->list[$ip->getId()])) {
      return $this->list[$ip->getId()];
    }

    try {
      $record = $this->db->select(static::TABLE, 't')
        ->fields('t')
        ->condition('ip', $ip->getStorableValue())
        ->execute()
        ->fetchObject();
    }
    catch (Exception) {
      $record = NULL;
    }

    if (!$record) {
      return NULL;
    }

    return new Tracked404($ip, $record->first_404, $record->count);
  }

  /**
   * Creates a 404 tracker with an initial count of one.
   *
   * @param \Drupal\country_access_filter\DTO\Ip $ip
   *   The IP address whose 404 responses should be tracked.
   *
   * @return \Drupal\country_access_filter\DTO\Tracked404
   *   The new tracker.
   */
  public function create(Ip $ip): Tracked404 {
    $tracker = new Tracked404($ip, time(), 1);

    $this->save($tracker);

    return $tracker;
  }

  /**
   * Saves a 404 tracker.
   *
   * @param \Drupal\country_access_filter\DTO\Tracked404 $tracker
   *   The tracker to save.
   *
   * @return bool
   *   TRUE if the tracker was saved, or FALSE otherwise.
   */
  public function save(Tracked404 $tracker): bool {
    try {
      $this->db->merge(static::TABLE)
        ->keys([
          'ip' => $tracker->ip->getStorableValue(),
        ])
        ->fields([
          'first_404' => $tracker->getFirstTimestamp(),
          'count' => $tracker->getCount(),
        ])
        ->execute();

      $this->list[$tracker->ip->getId()] = $tracker;
    }
    catch (Exception) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Deletes an IP address's 404 tracker.
   *
   * @param \Drupal\country_access_filter\DTO\Tracked404 $tracker
   *   The tracker to delete.
   *
   * @return bool
   *   TRUE if the tracker was deleted, or FALSE otherwise.
   */
  public function delete(Tracked404 $tracker): bool {
    try {
      $this->db->delete(static::TABLE)
        ->condition('ip', $tracker->ip->getStorableValue())
        ->execute();

      unset($this->list[$tracker->ip->getId()]);

      return TRUE;
    }
    catch (Exception) {
    }

    return FALSE;
  }

}
