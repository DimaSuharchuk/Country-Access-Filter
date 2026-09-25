<?php

namespace Drupal\Tests\country_access_filter;

use Drupal\country_access_filter\Service\CountryService;
use Drupal\country_access_filter\DTO\IpInput;
use Drupal\country_access_filter\DTO\Tracked404;
use Drupal\country_access_filter\IpAccess;
use Drupal\country_access_filter\Service\storage\IpStorage;
use Drupal\country_access_filter\Service\storage\Tracker404Storage;

/**
 * Tests one-time removal of historically corrupted unknown-country records.
 */
final class UnknownCountryUpdateTest extends AuditTestBase {

  /**
   * Tests cleanup includes locked decisions and leaves other countries intact.
   */
  public function testCleanupAndRediscovery(): void {
    $storage = new IpStorage($this->db);
    $trackers = new Tracker404Storage($this->db);
    $fixtures = [
      $this->ip('192.0.2.1', IpAccess::Allowed, CountryService::COUNTRY_CODE_UNDEFINED),
      $this->ip('2001:db8::1', IpAccess::Denied, CountryService::COUNTRY_CODE_UNDEFINED),
      $this->ip('192.0.2.2', IpAccess::Allowed, 'UA'),
      $this->ip('2001:db8::2', IpAccess::Denied, 'US'),
    ];

    foreach ($fixtures as $index => $ip) {
      self::assertTrue($index === 1 ? $storage->deny($ip) : $storage->save($ip));
      self::assertTrue($trackers->save(new Tracked404($ip, 1234567890, 3)));
    }

    $orphan = $this->ip('192.0.2.99');
    self::assertTrue($trackers->save(new Tracked404($orphan, 1234567890, 2)));
    country_access_filter_update_10011();
    country_access_filter_update_10011();
    // Updates finish before normal requests load the runtime storage services.
    $storage = new IpStorage($this->db);
    $trackers = new Tracker404Storage($this->db);

    foreach ($fixtures as $ip) {
      $loaded = $storage->load(new IpInput($ip->getId()));

      if ($ip->getCountryCode() === CountryService::COUNTRY_CODE_UNDEFINED) {
        self::assertNull($loaded);
        self::assertNull($trackers->load($ip));
      }
      else {
        self::assertSame($ip->getCountryCode(), $loaded->getCountryCode());
        self::assertSame($ip->isAllowed(), $loaded->isAllowed());
        self::assertSame(3, $trackers->load($ip)->getCount());
      }
    }

    self::assertSame(2, $trackers->load($orphan)->getCount());
    // A legitimate unknown country can be stored again after the update.
    self::assertTrue($storage->save($fixtures[0]));
    self::assertSame(CountryService::COUNTRY_CODE_UNDEFINED, (new IpStorage($this->db))->load(new IpInput('192.0.2.1'))->getCountryCode());
    self::assertFalse((new IpStorage($this->db))->load(new IpInput('192.0.2.1'))->isAccessLocked());
  }

}
