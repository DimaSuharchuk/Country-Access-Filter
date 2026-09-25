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
   * @param \Psr\Log\LoggerInterface|null $logger
   *   The logger to inspect, or NULL to discard test log messages.
   *
   * @return \Drupal\country_access_filter\Service\CountryService
   *   The country access service for the test.
   */
  private function service(MockHandler $handler, array $config = [], ?\Psr\Log\LoggerInterface $logger = NULL): CountryService {
    $countries = $this->createMock(CountryManagerInterface::class);
    $countries->method('getList')->willReturn(['UA' => 'Ukraine', 'US' => 'United States', 'UG' => 'Uganda']);

    return new CountryService(new IpStorage($this->db), new Client(['handler' => HandlerStack::create($handler)]), new Json(), $this->configFactory($config), $countries, $logger ?? new \Psr\Log\NullLogger());
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
    $handler = new MockHandler([new Response(200, [], json_encode(['status' => 'success', 'countryCode' => $country]))]);
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
    return [['{"status":"success","countryCode":[]}'], ['{"status":"success","countryCode":123}'], ['{"status":"success","countryCode":"USA"}'], ['{"status":"success","countryCode":"ua"}'], ['{"status":"success","countryCode":"UA\\n"}'], ['{"status":"success","countryCode":""}'], ['not json'], ['null'], ['"UA"']];
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
    $handler = new MockHandler([new Response(200, [], '{"status":"success","countryCode":"UA"}')]);
    $this->service($handler)->hasAccess(new IpInput('192.0.2.1'));

    self::assertSame(2, $handler->getLastOptions()['connect_timeout']);
    self::assertSame(5, $handler->getLastOptions()['timeout']);
  }

  /**
   * Tests provider errors are logged without persistence and recover on retry.
   *
   * @param int $status
   *   The HTTP status code.
   * @param string $body
   *   The provider response body.
   * @param string $reason
   *   The expected watchdog reason fragment.
   *
   * @dataProvider providerErrors
   */
  public function testProviderErrorRecovery(int $status, string $body, string $reason): void {
    $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
    $logger->expects(self::once())->method('error')->with(
      'IP geolocation failed for @ip: @reason',
      self::callback(fn($context) => $context['@ip'] === '192.0.2.1' && str_contains($context['@reason'], $reason)),
    );
    $handler = new MockHandler([
      new Response($status, [], $body),
      new Response(200, [], '{"status":"success","countryCode":"UA"}'),
    ]);
    $service = $this->service($handler, [], $logger);
    $input = new IpInput('192.0.2.1');

    self::assertFalse($service->hasAccess($input));
    self::assertNull((new IpStorage($this->db))->load($input));
    self::assertTrue($service->hasAccess($input));
    self::assertSame('UA', (new IpStorage($this->db))->load($input)->getCountryCode());
  }

  /**
   * Provides transport-level responses and application-level error payloads.
   */
  public static function providerErrors(): array {
    return [
      [200, '{"status":"fail","message":"subscription required"}', 'subscription required'],
      [403, '{"status":"fail","message":"invalid API key"}', 'HTTP 403: invalid API key'],
      [429, '', 'HTTP 429'],
      [502, '<html>Unavailable</html>', 'HTTP 502'],
      [200, '{}', 'invalid response'],
      [200, 'not json', 'invalid response'],
      [200, '{"status":"success"}', 'missing country code'],
      [200, '{"status":"fail","message":[]}', 'No error details'],
    ];
  }

  /**
   * Tests an unreachable provider is logged and cannot create an XX decision.
   */
  public function testConnectionFailure(): void {
    $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
    $logger->expects(self::once())->method('error')->with(
      self::anything(), self::callback(fn($context) => str_contains($context['@reason'], 'did not respond')),
    );
    $handler = new MockHandler([new \GuzzleHttp\Exception\ConnectException(
      'Connection timed out', new \GuzzleHttp\Psr7\Request('GET', 'http://ip-api.com'),
    )]);
    $service = $this->service($handler, [], $logger);

    self::assertFalse($service->hasAccess(new IpInput('192.0.2.1')));
    self::assertNull((new IpStorage($this->db))->load(new IpInput('192.0.2.1')));
  }

  /**
   * Tests a successful, explicitly unknown country remains a valid result.
   */
  public function testExplicitUnknownCountry(): void {
    $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
    $logger->expects(self::never())->method('error');
    $service = $this->service(new MockHandler([new Response(200, [], '{"status":"success","countryCode":"XX"}')]), [], $logger);

    self::assertFalse($service->hasAccess(new IpInput('192.0.2.1')));
    self::assertSame(CountryService::COUNTRY_CODE_UNDEFINED, (new IpStorage($this->db))->load(new IpInput('192.0.2.1'))->getCountryCode());
  }

  /**
   * Tests full administrative lookups retain the same timeout limits.
   */
  public function testFullLookupTimeouts(): void {
    $handler = new MockHandler([new Response(200, [], '{"status":"success","countryCode":"UA"}')]);
    $this->service($handler)->getIpInfo(new IpInput('192.0.2.1'), TRUE);

    self::assertSame(2, $handler->getLastOptions()['connect_timeout']);
    self::assertSame(5, $handler->getLastOptions()['timeout']);
    self::assertSame('', $handler->getLastRequest()->getUri()->getQuery());
  }

}
