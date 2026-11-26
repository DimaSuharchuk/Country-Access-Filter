<?php

namespace Drupal\Tests\country_access_filter;

use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\country_access_filter\Form\CountryAccessFilterSettingsForm;
use Drupal\country_access_filter\IpAccess;
use Drupal\country_access_filter\DTO\IpInput;
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
   * Tests manual decisions survive country policy changes in both directions.
   *
   * @dataProvider manualPolicyTransitions
   */
  public function testManualDecisionSurvivesPolicyTransitions(string $address, bool $manual_allow, array $initial, array $changed): void {
    $service = $this->configurePolicy([
      'country_access_mode' => $initial[0],
      'countries' => implode(' ', $initial[1]),
    ]);
    $storage = $this->container->get('country_access_filter.ip_storage');
    $input = new IpInput($address);
    $initial_allow = $service->isCountryAllowed('UA');
    $ip = $this->ip($address, $initial_allow ? IpAccess::Allowed : IpAccess::Denied);
    self::assertTrue($storage->save($ip));

    // Keep an unlocked IP from the same country as a control.
    $control = new IpInput('192.0.2.99');
    self::assertTrue($storage->save($this->ip($control->getId(), $initial_allow ? IpAccess::Allowed : IpAccess::Denied)));
    self::assertTrue($manual_allow ? $storage->allow($ip) : $storage->deny($ip));
    $instance = CountryAccessFilterSettingsForm::create($this->container);

    // Change policy, restore it, then change again to catch stale caches.
    foreach ([$changed, $initial, $changed] as [$mode, $countries]) {
      $state = new FormState();
      $state->setValues([
        'enabled' => TRUE,
        'country_access_mode' => $mode,
        'countries' => $countries,
        'track_404' => FALSE,
        'track_404_threshold' => 5,
        'track_404_window' => 3600,
      ]);
      $form = [];
      $instance->submitForm($form, $state);
      $country_allow = $mode === 'allow' ? in_array('UA', $countries, TRUE) : !in_array('UA', $countries, TRUE);
      self::assertSame($country_allow, $service->isCountryAllowed('UA'));
      self::assertSame($manual_allow, $service->hasAccess($input));
      self::assertSame($country_allow, $service->hasAccess($control));
      self::assertTrue($storage->load($input)->isAccessLocked());

      // Verify persisted values independently of the storage object's cache.
      drupal_static_reset();
      $fresh = new IpStorage($this->db);
      self::assertSame($manual_allow, $fresh->load($input)->isAllowed());
      self::assertTrue($fresh->load($input)->isAccessLocked());
      self::assertSame('UA', $fresh->load($input)->getCountryCode());
      self::assertSame($country_allow, $fresh->load($control)->isAllowed());
      self::assertFalse($fresh->load($control)->isAccessLocked());
    }
  }

  /**
   * Covers both IP families, manual statuses, modes, and empty country lists.
   */
  public static function manualPolicyTransitions(): array {
    $policies = [
      'allow UA' => ['allow', ['UA']],
      'allow US' => ['allow', ['US']],
      'allow none' => ['allow', []],
      'deny UA' => ['deny', ['UA']],
      'deny US' => ['deny', ['US']],
      'deny none' => ['deny', []],
    ];
    $cases = [];

    foreach (['192.0.2.1', '2001:db8::1'] as $address) {
      foreach ([FALSE, TRUE] as $manual_allow) {
        foreach ($policies as $initial_name => $initial) {
          foreach ($policies as $changed_name => $changed) {
            $name = $address . ' manual ' . (int) $manual_allow . ': ' . $initial_name . ' -> ' . $changed_name;
            $cases[$name] = [$address, $manual_allow, $initial, $changed];
          }
        }
      }
    }

    return $cases;
  }

  /**
   * Tests unchecked checkbox values are omitted from stored configuration.
   */
  public function testCheckboxSubmission(): void {
    $this->submit(['countries' => ['UA' => 'UA', 'US' => 0, 'UG' => 0]]);
    self::assertSame('UA', $this->container->get('country_access_filter.settings')->get('countries'));
    $this->submit(['countries' => ['UA' => 0, 'US' => 0, 'UG' => 0]]);
    self::assertSame('', $this->container->get('country_access_filter.settings')->get('countries'));
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
