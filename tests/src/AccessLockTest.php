<?php

namespace Drupal\Tests\country_access_filter;

use Drupal\country_access_filter\DTO\IpInput;
use Drupal\country_access_filter\IpAccess;
use Drupal\country_access_filter\Service\storage\IpStorage;

/**
 * Tests persistent IP exceptions and their upgrade migration.
 */
final class AccessLockTest extends AuditTestBase {

  /**
   * Tests manual decisions remain locked through toggles and config saves.
   */
  public function testManualDecisionsAndDeletion(): void {
    $this->configurePolicy();
    $storage = $this->container->get('country_access_filter.ip_storage');
    $input = new IpInput('2001:db8::1');
    $ip = $this->ip($input->getId());
    self::assertTrue($storage->save($ip));
    self::assertFalse($storage->load($input)->isAccessLocked());
    self::assertTrue($storage->deny($ip));
    self::assertTrue((new IpStorage($this->db))->load($input)->isAccessLocked());
    self::assertTrue($storage->loadByCountry('UA')[$input->getId()]->isAccessLocked());
    $config = $this->container->get('config.factory')->getEditable('country_access_filter.settings');
    $config->set('countries', 'UA US')->save();
    self::assertFalse($storage->load($input)->isAllowed());
    self::assertTrue($storage->allow($storage->load($input)));
    $config->set('country_access_mode', 'deny')->save();
    self::assertTrue($storage->load($input)->isAllowed());
    self::assertTrue($storage->load($input)->isAccessLocked());
    self::assertTrue($storage->delete($storage->load($input)));
    self::assertNull($storage->load($input));
    self::assertTrue($storage->save($ip));
    $config->save();
    self::assertFalse($storage->load($input)->isAllowed());
    self::assertFalse($storage->load($input)->isAccessLocked());
  }

  /**
   * Tests legacy decisions are preserved and only policy exceptions are locked.
   *
   * @param string $mode
   *   The configured country policy mode.
   * @param string $countries
   *   The selected country codes.
   * @param string[] $allowed
   *   The country codes allowed by this policy.
   *
   * @dataProvider policies
   */
  public function testMigration(string $mode, string $countries, array $allowed): void {
    $this->configurePolicy(['country_access_mode' => $mode, 'countries' => $countries]);
    $this->db->schema()->dropField(IpStorage::TABLE, 'access_locked');
    $expected = [];
    foreach (['UA', 'US', 'XX'] as $index => $country) {
      foreach ([0, 1] as $access) {
        $address = "2001:db8::$index$access";
        $this->db->insert(IpStorage::TABLE)->fields([
          'ip' => (new IpInput($address))->getStorableValue(),
          'country_code' => $country,
          'access' => $access,
        ])->execute();
        $expected[$address] = [$country, (bool) $access, (bool) $access !== in_array($country, $allowed, TRUE)];
      }
    }
    country_access_filter_update_10010();
    // Retrying the update must preserve all decisions and inferred exceptions.
    country_access_filter_update_10010();
    $storage = new IpStorage($this->db);
    foreach ($expected as $address => [$country, $access, $locked]) {
      $ip = $storage->load(new IpInput($address));
      self::assertSame($country, $ip->getCountryCode());
      self::assertSame($access, $ip->isAllowed());
      self::assertSame($locked, $ip->isAccessLocked());
    }
    $input = new IpInput('192.0.2.9');
    $this->db->insert(IpStorage::TABLE)->fields([
      'ip' => $input->getStorableValue(), 'country_code' => 'UA', 'access' => 1,
    ])->execute();
    self::assertFalse($storage->load($input)->isAccessLocked());
  }

  /**
   * Provides allowlists, denylists, and empty country selections.
   */
  public static function policies(): array {
    return [
      ['allow', " UA\nUS ", ['UA', 'US']],
      ['deny', 'UA', ['US', 'UG']],
      ['allow', '', []],
      ['deny', '', ['UA', 'US', 'UG']],
    ];
  }

}
