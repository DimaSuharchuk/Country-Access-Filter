<?php

namespace Drupal\Tests\country_access_filter;

use Drupal\country_access_filter\DTO\IpInput;
use Drupal\country_access_filter\Service\storage\IpStorage;
use Drupal\country_access_filter\Service\storage\Tracker404Storage;

/**
 * Tests update behavior.
 */
final class UpdateTest extends AuditTestBase {

  /**
   * Tests legacy migration preserves data across batches.
   *
   * @param string $table
   *   The database table being updated.
   * @param string $hook
   *   The update hook to invoke.
   *
   * @dataProvider tables
   */
  public function testLegacyMigrationPreservesDataAcrossBatches(string $table, string $hook): void {
    $schema = $this->db->schema();
    $schema->dropTable($table);
    $spec = country_access_filter_schema()[$table];
    $spec['fields']['ip'] = ['type' => 'int', 'size' => 'big', 'unsigned' => TRUE, 'not null' => TRUE];
    if ($table === IpStorage::TABLE) {
      $spec['fields']['status'] = $spec['fields']['access'];
      unset($spec['fields']['access']);
    }
    $schema->createTable($table, $spec);
    $addresses = [0, 2147483648, 4294967295];
    for ($i = 1; $i <= 1001; $i++) {
      $addresses[] = $i;
    }
    foreach ($addresses as $i => $address) {
      $fields = $table === IpStorage::TABLE ? ['status' => $i % 2, 'country_code' => 'UA'] : ['first_404' => 1234567890, 'count' => 3];
      $this->db->insert($table)->fields(['ip' => $address] + $fields)->execute();
    }
    $sandbox = [];
    $passes = 0;
    do {
      $hook($sandbox);
      self::assertLessThan(10, ++$passes, 'Migration must terminate.');
    } while ($sandbox['#finished'] < 1);
    self::assertGreaterThanOrEqual(3, $passes);
    if ($table === IpStorage::TABLE) {
      country_access_filter_update_10009();
      country_access_filter_update_10009();
      self::assertFalse($schema->fieldExists($table, 'status'));
    }
    self::assertSame(count($addresses), (int) $this->db->select($table)->countQuery()->execute()->fetchField());
    foreach ([0, 2147483648, 4294967295, 1001] as $address) {
      $input = new IpInput(long2ip($address));
      $row = $this->db->select($table, 'i')->fields('i')->condition('ip', $input->getStorableValue())->execute()->fetchObject();
      self::assertNotFalse($row, 'Migrated address ' . $input->getId());
      if ($table === IpStorage::TABLE) {
        self::assertSame('UA', $row->country_code);
        self::assertSame((string) (array_search($address, $addresses, TRUE) % 2), $row->access);
      }
      else {
        self::assertSame('3', $row->count);
        self::assertSame('1234567890', $row->first_404);
      }
    }
  }

  /**
   * Provides database tables and update hooks for migration tests.
   */
  public static function tables(): array {
    return [[IpStorage::TABLE, 'country_access_filter_update_10007'], [Tracker404Storage::TABLE, 'country_access_filter_update_10008']];
  }

  /**
   * Tests empty legacy migration.
   *
   * @param string $table
   *   The database table being updated.
   * @param string $hook
   *   The update hook to invoke.
   *
   * @dataProvider tables
   */
  public function testEmptyLegacyMigration(string $table, string $hook): void {
    $this->db->schema()->dropTable($table);
    $spec = country_access_filter_schema()[$table];
    $spec['fields']['ip'] = ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE];
    $this->db->schema()->createTable($table, $spec);
    $sandbox = [];
    $hook($sandbox);
    self::assertSame(1, $sandbox['#finished']);
    self::assertFalse($this->db->schema()->fieldExists($table, 'ip_packed'));
  }

}
