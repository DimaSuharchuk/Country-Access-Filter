<?php

namespace Drupal\Tests\country_access_filter;

use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\country_access_filter\Form\CountryAccessFilterSettingsForm;
use Drupal\country_access_filter\IpAccess;
use Drupal\country_access_filter\Service\storage\IpStorage;

/**
 * Tests settings form behavior.
 */
final class SettingsFormTest extends AuditTestBase {

  /**
   * Submits the settings form with the specified values.
   *
   * @param array $values
   *   The form values to submit.
   */
  private function submit(array $values = []): void {
    $this->configurePolicy();
    $this->container->set('messenger', $this->createMock(MessengerInterface::class));
    $state = new FormState();
    $state->setValues($values + ['enabled' => TRUE, 'country_access_mode' => 'allow', 'countries' => ['UA'], 'track_404' => TRUE, 'track_404_threshold' => 5, 'track_404_window' => 3600]);
    $form = [];
    CountryAccessFilterSettingsForm::create($this->container)->submitForm($form, $state);
  }

  /**
   * Tests that a manual decision survives settings form submission.
   */
  public function testSavingUnchangedCountryPolicyPreservesIndividualBan(): void {
    (new IpStorage($this->db))->deny($this->ip());
    $this->submit(['track_404_threshold' => 10]);
    self::assertFalse($this->storedAccess(), 'Changing only the 404 threshold must not lift individual bans.');
  }

  /**
   * Tests that a manual decision survives settings form submission.
   */
  public function testSavingUnchangedCountryPolicyPreservesIndividualAllow(): void {
    (new IpStorage($this->db))->allow($this->ip('192.0.2.1', IpAccess::Denied, 'US'));
    $this->submit();
    self::assertTrue($this->storedAccess(), 'Saving country settings must preserve a manual IP exception.');
  }

  /**
   * Tests changed country policy updates learned ips.
   */
  public function testChangedCountryPolicyUpdatesLearnedIps(): void {
    (new IpStorage($this->db))->save($this->ip());
    $this->submit(['countries' => ['US']]);
    self::assertFalse($this->storedAccess());
  }

}
