<?php

namespace Drupal\Tests\country_access_filter;

use Drupal\country_access_filter\Controller\FormController;
use Drupal\country_access_filter\Service\CountryService;
use Drupal\country_access_filter\Service\storage\IpStorage;
use GuzzleHttp\ClientInterface;

/**
 * Tests controller behavior.
 */
final class ControllerTest extends AuditTestBase {

  /**
   * Creates a controller with mocked storage services.
   *
   * @param bool $saveResult
   *   Whether the mocked storage save succeeds.
   * @param bool $deleteResult
   *   Whether the mocked storage delete succeeds.
   *
   * @return \Drupal\country_access_filter\Controller\FormController
   *   The controller configured with mocked storage services.
   */
  private function controller(bool $saveResult = FALSE, bool $deleteResult = FALSE): FormController {
    $this->configurePolicy();
    $storage = $this->getMockBuilder(IpStorage::class)->disableOriginalConstructor()->onlyMethods(['load', 'save', 'delete'])->getMock();
    $storage->method('load')->willReturn($this->ip());

    $storage->method('save')->willReturnCallback(function ($ip) use ($saveResult) {
      self::assertTrue($ip->isAccessLocked());
      self::assertSame('UA', $ip->getCountryCode());

      return $saveResult;
    });

    $storage->method('delete')->willReturn($deleteResult);
    $this->container->set('country_access_filter.ip_storage', $storage);
    $this->container->set('country_access_filter.country_service', $this->getMockBuilder(CountryService::class)->disableOriginalConstructor()->getMock());
    $this->container->set('http_client', $this->createMock(ClientInterface::class));

    return FormController::create($this->container);
  }

  /**
   * Tests storage error produces usable ajax message.
   *
   * @param string $method
   *   The controller action to invoke.
   * @param array $args
   *   The arguments to pass to the controller action.
   *
   * @dataProvider errorActions
   */
  public function testStorageErrorProducesUsableAjaxMessage(string $method, array $args): void {
    $commands = $this->controller()->$method(...$args)->getCommands();

    self::assertCount(1, $commands);
    self::assertSame('message', $commands[0]['command']);
    self::assertSame('error', $commands[0]['messageOptions']['type']);
    self::assertTrue($commands[0]['messageWrapperQuerySelector'] === NULL || is_string($commands[0]['messageWrapperQuerySelector']), 'Drupal.Message requires a CSS selector or null, not an array.');
  }

  /**
   * Provides IP actions for AJAX error handling tests.
   */
  public static function errorActions(): array {
    return [['ipChangeAccessAjaxCallback', ['192.0.2.1', 0]], ['ipRemoveAjaxCallback', ['192.0.2.1']]];
  }

  /**
   * Tests unknown ip info is bad request.
   */
  public function testUnknownIpInfoIsBadRequest(): void {
    $controller = $this->controller();
    $property = new \ReflectionProperty($controller, 'ipStorage');
    $property->setValue($controller, new IpStorage($this->db));

    self::assertSame(400, $controller->ipInfoCallback('192.0.2.9')->getStatusCode());
  }

  /**
   * Tests injected country names and the inherited translation mechanism.
   */
  public function testCountryTitles(): void {
    $controller = $this->controller();
    $unused = $this->createMock(\Drupal\Core\Locale\CountryManagerInterface::class);
    $unused->expects(self::never())->method('getList');
    $this->container->set('country_manager', $unused);
    self::assertSame('Ukraine (UA) IPs', (string) $controller->countryDetailsTitle('UA'));
    self::assertSame('Unknown country (XX) IPs', (string) $controller->countryDetailsTitle('XX'));
  }

}
