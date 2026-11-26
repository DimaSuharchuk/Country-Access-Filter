<?php

namespace Drupal\Tests\country_access_filter;

/**
 * Verifies migration uses active settings and survives stale imports.
 */
class StateMigrationTest extends AuditTestBase {

  public function testActiveSettingsArePreserved(): void {
    $this->configurePolicy(['countries' => 'US', 'enabled' => FALSE]);
    $state = $this->container->get('state');
    $state->set('country_access_filter.settings', NULL);
    country_access_filter_update_10012();
    $settings = $this->container->get('country_access_filter.settings');
    self::assertSame('US', $settings->get('countries'));
    self::assertFalse($settings->get('enabled'));

    $this->container->get('config.factory')->getEditable('country_access_filter.settings')
      ->set('countries', 'UA')
      ->set('enabled', TRUE)
      ->save();
    country_access_filter_update_10012();
    self::assertSame('US', $settings->get('countries'));
    self::assertFalse($settings->get('enabled'));
  }

}
