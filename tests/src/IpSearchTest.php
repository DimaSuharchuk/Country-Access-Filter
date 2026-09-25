<?php

namespace Drupal\Tests\country_access_filter;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Form\FormState;
use Drupal\country_access_filter\Controller\FormController;
use Drupal\country_access_filter\DTO\IpInput;
use Drupal\country_access_filter\Form\CountryAccessFilterSettingsForm;
use Drupal\country_access_filter\Service\CountryService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Tests IP lookup using the visitor access path and shared dialog table.
 */
final class IpSearchTest extends AuditTestBase {

  /**
   * Tests existing locks, new IPv6 lookup, and unsaved settings isolation.
   */
  public function testLookup(): void {
    $this->configurePolicy();
    $storage = $this->container->get('country_access_filter.ip_storage');
    $storage->deny($this->ip());
    $handler = new MockHandler([new Response(200, [], '{"countryCode":"US"}')]);
    $client = new Client(['handler' => HandlerStack::create($handler)]);
    $this->container->set('http_client', $client);
    $this->container->set('country_access_filter.country_service', new CountryService(
      $storage, $client, new Json(), $this->container->get('config.factory'), $this->container->get('country_manager'),
    ));
    $instance = CountryAccessFilterSettingsForm::create($this->container);
    $form = [];
    $state = new FormState();
    $state->setValues(['ip_search_address' => ' 192.0.2.1 ', 'countries' => ['US'], 'enabled' => FALSE]);
    $instance->submitIpSearch($form, $state);
    $ip = $state->get('ip_search_result');
    self::assertFalse($ip->isAllowed());
    self::assertTrue($ip->isAccessLocked());
    self::assertCount(1, $handler, 'Known IPs must not call the provider.');
    $state->setValue('ip_search_address', '2001:db8::42');
    $instance->submitIpSearch($form, $state);
    $ip = $state->get('ip_search_result');
    self::assertSame('US', $ip->getCountryCode());
    self::assertFalse($ip->isAllowed(), 'Lookup must use saved country rules.');
    self::assertFalse($ip->isAccessLocked());
    self::assertNotNull($storage->load(new IpInput('2001:db8::42')));
    self::assertCount(0, $handler);
    self::assertSame('UA', $this->container->get('config.factory')->get('country_access_filter.settings')->get('countries'));
    self::assertTrue($this->container->get('config.factory')->get('country_access_filter.settings')->get('enabled'));
    $controller = FormController::create($this->container);
    $table = $controller->buildIpTable([$ip]);
    self::assertCount(1, $table['#rows']);
    self::assertSame('2001:db8::42', $table['#rows'][0]['data-id']);
    self::assertEquals($controller->countryDetailsAjaxCallback('US'), $table);
    self::assertSame('Ukraine (UA) IPs', (string) $controller->countryDetailsTitle('UA'));
    self::assertSame('Unknown country (XX) IPs', (string) $controller->countryDetailsTitle('XX'));
  }

  /**
   * Tests invalid input and unavailable providers return errors, not stale IPs.
   */
  public function testLookupFailures(): void {
    $this->configurePolicy();
    $storage = $this->container->get('country_access_filter.ip_storage');
    $handler = new MockHandler([new Response(503)]);
    $client = new Client(['handler' => HandlerStack::create($handler)]);
    $this->container->set('country_access_filter.country_service', new CountryService(
      $storage, $client, new Json(), $this->container->get('config.factory'), $this->container->get('country_manager'),
    ));
    $instance = CountryAccessFilterSettingsForm::create($this->container);
    $form = [];
    $state = new FormState();

    foreach (['', 'not-an-ip', ['unexpected'], '192.0.2.99'] as $input) {
      $state->set('ip_search_result', $this->ip());
      $state->setValue('ip_search_address', $input);
      $instance->submitIpSearch($form, $state);
      self::assertNull($state->get('ip_search_result'));
      $commands = $instance->ipSearchAjaxCallback($form, $state)->getCommands();
      self::assertCount(1, $commands);
      self::assertSame('message', $commands[0]['command']);
      self::assertSame('error', $commands[0]['messageOptions']['type']);
    }

    self::assertNull($storage->load(new IpInput('192.0.2.99')));
    self::assertCount(0, $handler);
  }

}
