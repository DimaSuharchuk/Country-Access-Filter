<?php

namespace Drupal\Tests\country_access_filter;

/**
 * Tests configuration upgrades from the original hours-based settings.
 */
final class LegacyConfigUpdateTest extends AuditTestBase {

  /**
   * Tests missing defaults and the access mode are initialized in update order.
   */
  public function testMissingDefaults(): void {
    $this->configurePolicy();
    $config = $this->container->get('config.factory')->getEditable('country_access_filter.settings');
    $config->clear('track_404')->clear('track_404_threshold')->clear('track_404_window')->clear('country_access_mode')->save();
    country_access_filter_update_10004();
    country_access_filter_update_10004();

    self::assertFalse($config->get('track_404'));
    self::assertSame(5, $config->get('track_404_threshold'));
    self::assertSame(1, $config->get('track_404_window'));
    country_access_filter_update_10005();
    country_access_filter_update_10006();

    self::assertSame('allow', $config->get('country_access_mode'));
    self::assertSame(3600, $config->get('track_404_window'));
    self::assertSame('UA', $config->get('countries'));
    self::assertTrue($config->get('enabled'));
  }

  /**
   * Tests existing values survive defaults and hours convert exactly once.
   *
   * @param mixed $hours
   *   The legacy time window.
   * @param mixed $seconds
   *   The expected upgraded window.
   *
   * @dataProvider windows
   */
  public function testExistingSettings($hours, $seconds): void {
    $this->configurePolicy(['track_404' => FALSE, 'track_404_threshold' => 17, 'track_404_window' => $hours]);
    $config = $this->container->get('config.factory')->getEditable('country_access_filter.settings');
    country_access_filter_update_10004();

    self::assertFalse($config->get('track_404'));
    self::assertSame(17, $config->get('track_404_threshold'));
    self::assertSame($hours, $config->get('track_404_window'));
    country_access_filter_update_10006();

    self::assertSame($seconds, $config->get('track_404_window'));
  }

  /**
   * Provides legacy windows, including the unlimited-window representations.
   */
  public static function windows(): array {
    return [[2, 7200], ['3', 10800], [0, 0], [NULL, NULL], ['', '']];
  }

}
