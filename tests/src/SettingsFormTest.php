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

  /**
   * Tests unchecked checkbox values are omitted from stored configuration.
   */
  public function testCheckboxSubmission(): void {
    $this->submit(['countries' => ['UA' => 'UA', 'US' => 0, 'UG' => 0]]);
    self::assertSame('UA', $this->container->get('config.factory')->get('country_access_filter.settings')->get('countries'));
    $this->submit(['countries' => ['UA' => 0, 'US' => 0, 'UG' => 0]]);
    self::assertSame('', $this->container->get('config.factory')->get('country_access_filter.settings')->get('countries'));
  }

  /**
   * Tests validation accepts selected and unchecked checkbox entries.
   */
  public function testCheckboxValidation(): void {
    $this->configurePolicy();
    $form = [];
    $state = new FormState();
    $state->setValues(['countries' => ['UA' => 'UA', 'US' => 0]]);
    $instance = CountryAccessFilterSettingsForm::create($this->container);
    $instance->validateForm($form, $state);
    self::assertSame([], $state->getErrors());
  }

  /**
   * Tests saved selections come first, with alphabetical order in each group.
   */
  public function testCountryOrder(): void {
    $this->configurePolicy(['countries' => "US\nUA"]);
    $form = CountryAccessFilterSettingsForm::create($this->container)->buildForm([], new FormState());
    $picker = $form['countries_wrapper']['countries'];
    self::assertSame('checkboxes', $picker['#type']);
    self::assertSame(['UA', 'US', 'UG'], array_keys($picker['#options']));
    self::assertSame(['UA', 'US'], $picker['#default_value']);
    self::assertContains('caf-country-picker__other', $picker['UG']['#wrapper_attributes']['class']);
  }

}
