<?php

namespace Drupal\Tests\country_access_filter;

use Drupal\country_access_filter\DTO\IpInput;
use Drupal\country_access_filter\DTO\Tracked404;
use Drupal\country_access_filter\IpAccess;
use Drupal\country_access_filter\Service\storage\IpStorage;
use Drupal\country_access_filter\Service\storage\Tracker404Storage;

/**
 * Tests ip storage behavior.
 */
final class IpStorageTest extends AuditTestBase {

  /**
   * Tests ip round trip.
   *
   * @param string $input
   *   The input address to normalize and store.
   * @param string $normalized
   *   The expected normalized address.
   * @param int $bytes
   *   The expected packed address length.
   *
   * @dataProvider validIps
   */
  public function testIpRoundTrip(string $input, string $normalized, int $bytes): void {
    $ip = new IpInput($input);
    self::assertTrue($ip->isValid());
    self::assertSame($normalized, $ip->getId());
    self::assertSame($normalized, $ip->toReadable());
    self::assertSame($normalized, $ip->getId());
    self::assertSame($bytes, strlen($ip->getStorableValue()));
    $storage = new IpStorage($this->db);
    self::assertTrue($storage->save($this->ip($input)));
    $loaded = (new IpStorage($this->db))->load(new IpInput($normalized));
    self::assertSame($normalized, $loaded->getId());
    self::assertSame('UA', $loaded->getCountryCode());
    self::assertTrue($loaded->isAllowed());
  }

  /**
   * Provides IPv4 and IPv6 addresses for normalization tests.
   */
  public static function validIps(): array {
    return [
      ['0.0.0.0', '0.0.0.0', 4],
      ['255.255.255.255', '255.255.255.255', 4],
      ['192.0.2.1', '192.0.2.1', 4],
      ['2001:0DB8:0000:0000:0000:0000:0000:0001', '2001:db8::1', 16],
      ['::1', '::1', 16],
      ['::', '::', 16],
      ['::ffff:192.0.2.1', '192.0.2.1', 4],
    ];
  }

  /**
   * Tests invalid input.
   *
   * @param mixed $input
   *   The invalid address input to validate.
   *
   * @dataProvider invalidIps
   */
  public function testInvalidInput($input): void {
    self::assertFalse((new IpInput($input))->isValid());
    self::assertNull((new IpStorage($this->db))->load(new IpInput($input)));
  }

  /**
   * Provides invalid input values for validation tests.
   */
  public static function invalidIps(): array {
    return [[NULL], [''], [FALSE], [123], [[]], ['999.1.2.3'], ['example.com'], ['192.0.2.1/24'], [' 192.0.2.1']];
  }

  /**
   * Tests allow deny and stats.
   */
  public function testAllowDenyAndStats(): void {
    $storage = new IpStorage($this->db);
    $ip = $this->ip();
    self::assertTrue($storage->save($ip));
    self::assertTrue($storage->deny($ip));
    self::assertFalse($storage->load(new IpInput($ip->getId()))->isAllowed());
    self::assertTrue($storage->save($this->ip('2001:db8::1', IpAccess::Allowed, 'US')));
    self::assertEquals([0 => 1, 1 => 1], $storage->getIpAccessCounts());
    self::assertCount(1, $storage->loadByCountry('UA'));
    self::assertEquals(1, $storage->getCountryStats()['UA']->denied);
    self::assertTrue($storage->allow($ip));
    self::assertTrue($this->storedAccess());
  }

  /**
   * Tests delete invalidates loaded ip.
   */
  public function testDeleteInvalidatesLoadedIp(): void {
    $storage = new IpStorage($this->db);
    $ip = $this->ip();
    $storage->save($ip);
    self::assertTrue($storage->delete($ip));
    self::assertNull((new IpStorage($this->db))->load(new IpInput($ip->getId())));
    self::assertNull($storage->load(new IpInput($ip->getId())), 'Deleted IP must not survive in the storage cache.');
  }

  /**
   * Tests country update invalidates loaded ip.
   */
  public function testCountryUpdateInvalidatesLoadedIp(): void {
    $storage = new IpStorage($this->db);
    $storage->save($this->ip());
    $storage->updateAccessByCountries(['UA'], IpAccess::Denied);
    self::assertFalse($this->storedAccess());
    self::assertFalse($storage->load(new IpInput('192.0.2.1'))->isAllowed());
  }

  /**
   * Tests tracker round trip.
   */
  public function testTrackerRoundTrip(): void {
    $storage = new Tracker404Storage($this->db);
    $tracker = new Tracked404($this->ip(), 1234567890, 4);
    self::assertTrue($storage->save($tracker));
    $loaded = (new Tracker404Storage($this->db))->load($this->ip());
    self::assertSame(4, $loaded->getCount());
    self::assertSame(1234567890, $loaded->getFirstTimestamp());
  }

  /**
   * Tests tracker delete invalidates cache.
   */
  public function testTrackerDeleteInvalidatesCache(): void {
    $storage = new Tracker404Storage($this->db);
    $tracker = $storage->create($this->ip());
    self::assertTrue($storage->delete($tracker));
    self::assertNull((new Tracker404Storage($this->db))->load($this->ip()));
    self::assertNull($storage->load($this->ip()));
  }

  /**
   * Tests property array loads all matching countries.
   */
  public function testPropertyArrayLoadsAllMatchingCountries(): void {
    $storage = new IpStorage($this->db);
    $storage->save($this->ip());
    $storage->save($this->ip('2001:db8::1', IpAccess::Allowed, 'US'));
    self::assertCount(2, $storage->getIpsByProperty('country_code', ['UA', 'US']));
  }

}
