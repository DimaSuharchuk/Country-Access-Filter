<?php

namespace Drupal\Tests\country_access_filter;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Locale\CountryManagerInterface;
use Drupal\country_access_filter\DTO\IpInput;
use Drupal\country_access_filter\Service\CountryService;
use Drupal\country_access_filter\Service\storage\IpStorage;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Tests country service behavior.
 */
final class CountryServiceTest extends AuditTestBase {

  /**
   * Creates the country service with a mocked HTTP provider.
   *
   * @param \GuzzleHttp\Handler\MockHandler $handler
   *   The responses for the HTTP provider.
   * @param array $config
   *   The configuration values for the country policy.
   *
   * @return \Drupal\country_access_filter\Service\CountryService
   *   The country access service for the test.
   */
  private function service(MockHandler $handler, array $config = []): CountryService {
    $countries = $this->createMock(CountryManagerInterface::class);
    $countries->method('getList')->willReturn(['UA' => 'Ukraine', 'US' => 'United States', 'UG' => 'Uganda']);

    return new CountryService(new IpStorage($this->db), new Client(['handler' => HandlerStack::create($handler)]), new Json(), $this->configFactory($config), $countries);
  }

  /**
   * Tests country policies.
   *
   * @param string $mode
   *   The country access mode.
   * @param string $selected
   *   The configured country codes.
   * @param string $country
   *   The visitor's country code.
   * @param bool $allowed
   *   Whether the visitor should be allowed.
   *
   * @dataProvider policies
   */
  public function testCountryPolicies(string $mode, string $selected, string $country, bool $allowed): void {
    $handler = new MockHandler([new Response(200, [], json_encode(['countryCode' => $country]))]);
    $service = $this->service($handler, ['country_access_mode' => $mode, 'countries' => $selected]);

    self::assertSame($allowed, $service->hasAccess(new IpInput('192.0.2.1')));
    self::assertSame($allowed, $service->hasAccess(new IpInput('::ffff:192.0.2.1')));
    self::assertCount(0, $handler, 'Repeated IP must use stored decision.');
  }

  /**
   * Provides country policies for access tests.
   */
  public static function policies(): array {
    return [['allow', 'UA', 'UA', TRUE], ['allow', 'UA', 'US', FALSE], ['deny', 'UG', 'UA', TRUE], ['deny', 'UG', 'UG', FALSE], ['allow', '', 'UA', FALSE], ['deny', '', 'UA', TRUE]];
  }

  /**
   * Tests invalid ip does not call provider.
   */
  public function testInvalidIpDoesNotCallProvider(): void {
    self::assertFalse($this->service(new MockHandler([]))->hasAccess(new IpInput('invalid')));
  }

  /**
   * Tests provider failure denies access.
   */
  public function testProviderFailureDeniesAccess(): void {
    self::assertFalse($this->service(new MockHandler([new Response(429)]))->hasAccess(new IpInput('192.0.2.1')));
    self::assertNull((new IpStorage($this->db))->load(new IpInput('192.0.2.1')));
  }

  /**
   * Tests malformed country does not crash request.
   *
   * @param string $body
   *   The malformed JSON returned by the lookup provider.
   *
   * @dataProvider malformedResponses
   */
  public function testMalformedCountryDoesNotCrashRequest(string $body): void {
    $service = $this->service(new MockHandler([new Response(200, [], $body)]));
    self::assertFalse($service->hasAccess(new IpInput('192.0.2.1')));
    self::assertNull((new IpStorage($this->db))->load(new IpInput('192.0.2.1')), 'Malformed responses must not create a permanent denial.');
  }

  /**
   * Provides invalid provider responses for validation tests.
   */
  public static function malformedResponses(): array {
    return [['{"countryCode":[]}'], ['{"countryCode":123}'], ['{"countryCode":"USA"}'], ['{"countryCode":"ua"}'], ['{"countryCode":"UA\\n"}'], ['{"countryCode":""}'], ['not json'], ['null'], ['"UA"']];
  }

  /**
   * Tests unknown country is denied.
   */
  public function testUnknownCountryIsDenied(): void {
    self::assertFalse($this->service(new MockHandler([new Response(200, [], '{}')]))->hasAccess(new IpInput('192.0.2.1')));
  }

  /**
   * Tests config import changes previously learned decision.
   */
  public function testConfigImportChangesPreviouslyLearnedDecision(): void {
    $service = $this->configurePolicy();
    (new IpStorage($this->db))->save($this->ip());
    self::assertTrue($service->hasAccess(new IpInput('192.0.2.1')));
    self::assertTrue($service->isCountryAllowed('UA'));
    // Config import saves Config objects and dispatches config.save without
    // submitting the settings form.
    $this->container->get('config.factory')->getEditable('country_access_filter.settings')->set('countries', 'US')->save();
    self::assertFalse($service->isCountryAllowed('UA'));
    self::assertFalse($service->hasAccess(new IpInput('192.0.2.1')), 'An IP learned under the old policy must follow imported configuration.');
    self::assertFalse($this->storedAccess());
  }

  /**
   * Tests config mode change allows previously denied ip.
   */
  public function testConfigModeChangeAllowsPreviouslyDeniedIp(): void {
    $service = $this->configurePolicy();

    (new IpStorage($this->db))->save($this->ip('192.0.2.1', \Drupal\country_access_filter\IpAccess::Denied, 'US'));

    self::assertFalse($service->hasAccess(new IpInput('192.0.2.1')));

    $this->container->get('config.factory')->getEditable('country_access_filter.settings')->set('country_access_mode', 'deny')->save();

    self::assertTrue($service->isCountryAllowed('US'));
    self::assertTrue($service->hasAccess(new IpInput('192.0.2.1')));
    self::assertTrue($this->storedAccess());
  }

  /**
   * Tests unrelated config save does not reset ip ban.
   */
  public function testUnrelatedConfigSaveDoesNotResetIpBan(): void {
    $this->configurePolicy();

    (new IpStorage($this->db))->save($this->ip('192.0.2.1', \Drupal\country_access_filter\IpAccess::Denied));

    $this->container->get('config.factory')->getEditable('system.site')->set('name', 'Test')->save();

    self::assertFalse($this->storedAccess());
  }

  /**
   * Tests country list is not shared between different configurations.
   */
  public function testCountryListIsNotSharedBetweenDifferentConfigurations(): void {
    self::assertTrue($this->service(new MockHandler([]))->isCountryAllowed('UA'));
    self::assertFalse($this->service(new MockHandler([]), ['countries' => 'US'])->isCountryAllowed('UA'));
  }

  /**
   * Tests visitor lookups have bounded connection and total request times.
   */
  public function testLookupTimeouts(): void {
    $handler = new MockHandler([new Response(200, [], '{"countryCode":"UA"}')]);
    $this->service($handler)->hasAccess(new IpInput('192.0.2.1'));

    self::assertSame(2, $handler->getLastOptions()['connect_timeout']);
    self::assertSame(5, $handler->getLastOptions()['timeout']);
  }

}
