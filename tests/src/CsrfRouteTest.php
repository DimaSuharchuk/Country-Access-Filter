<?php

namespace Drupal\Tests\country_access_filter;

use Drupal\Core\Access\AccessArgumentsResolverFactory;
use Drupal\Core\Access\AccessManager;
use Drupal\Core\Access\CheckProvider;
use Drupal\Core\Access\CsrfAccessCheck;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\ParamConverter\ParamConverterManagerInterface;
use Drupal\Core\PrivateKey;
use Drupal\Core\Routing\AccessAwareRouter;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\MetadataBag;
use Drupal\Core\Site\Settings;
use Drupal\user\Access\PermissionAccessCheck;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests incoming requests against module routes and Drupal's access pipeline.
 */
final class CsrfRouteTest extends AuditTestBase {

  /**
   * Tests missing, invalid, cross-action and cross-session tokens are rejected.
   *
   * @param string $action
   *   The route action suffix.
   * @param string $address
   *   The IP address in the route.
   *
   * @dataProvider actions
   */
  public function testMutationAccess(string $action, string $address): void {
    $this->container->set('cache_contexts_manager', new \Drupal\Core\Cache\Context\CacheContextsManager($this->container, ['user.permissions']));
    $settings = new Settings(['hash_salt' => 'isolated-csrf-test']);
    $key = $this->getMockBuilder(PrivateKey::class)->disableOriginalConstructor()->getMock();
    $key->method('get')->willReturn('isolated-private-key');
    $metadata = new MetadataBag($settings);
    $generator = new CsrfTokenGenerator($key, $metadata);
    $this->container->set('csrf_check', new CsrfAccessCheck($generator));
    $this->container->set('permission_check', new PermissionAccessCheck());
    $checks = new CheckProvider([], $this->container);
    $checks->addCheckService('csrf_check', 'access', ['_csrf_token'], TRUE);
    $checks->addCheckService('permission_check', 'access', ['_permission']);
    $routes = new RouteCollection();

    foreach (Yaml::parseFile(dirname(__DIR__, 2) . '/country_access_filter.routing.yml') as $name => $definition) {
      $route = new Route($definition['path'], $definition['defaults'], $definition['requirements']);
      $route->setDefault('_route_object', $route);
      $routes->add($name, $route);
    }

    $checks->setChecks($routes);
    $account = $this->createMock(AccountInterface::class);
    $permitted = TRUE;

    $account->method('hasPermission')->willReturnCallback(static function ($permission) use (&$permitted) {
      return $permitted && $permission === 'administer site configuration';
    });

    $manager = new AccessManager(
      $this->createMock(RouteProviderInterface::class),
      $this->createMock(ParamConverterManagerInterface::class),
      new AccessArgumentsResolverFactory(), $account, $checks,
    );
    $matcher = new UrlMatcher($routes, new RequestContext());
    $inner = $this->getMockBuilder(RouterInterface::class)->onlyMethods(get_class_methods(RouterInterface::class))->addMethods(['matchRequest'])->getMock();

    $inner->method('matchRequest')->willReturnCallback(function (Request $request) use ($matcher) {
      $matched = $matcher->match($request->getPathInfo());
      $matched['_raw_variables'] = new \Symfony\Component\HttpFoundation\ParameterBag(array_intersect_key($matched, array_flip(['ip_input', 'access', 'country'])));

      return $matched;
    });

    $router = new AccessAwareRouter($inner, $manager, $account);
    $name = 'country_access_filter.form.country.details.ip.' . $action;
    $path = strtr($routes->get($name)->getPath(), ['{ip_input}' => $address, '{access}' => '0']);
    $token = $generator->get(ltrim($path, '/'));
    $other_session = new CsrfTokenGenerator($key, new MetadataBag($settings));
    $invalid = [NULL, 'invalid', $generator->get('other/action'), $other_session->get(ltrim($path, '/'))];

    foreach ($invalid as $candidate) {
      $request = Request::create($path, 'GET', $candidate === NULL ? [] : ['token' => $candidate]);

      try {
        $router->matchRequest($request);
        self::fail('An invalid CSRF token allowed the mutation route.');
      }
      catch (AccessDeniedHttpException $exception) {
        self::assertSame(403, $exception->getStatusCode());
      }
    }

    $matched = $router->matchRequest(Request::create($path, 'GET', ['token' => $token]));
    self::assertSame($name, $matched['_route']);
    // Details require permission but do not mutate data or require a token.
    $details = $router->matchRequest(Request::create('/ajax/caf/details/UA'));
    self::assertSame('country_access_filter.form.country.details', $details['_route']);
    $permitted = FALSE;

    try {
      $router->matchRequest(Request::create($path, 'GET', ['token' => $token]));
      self::fail('A CSRF token bypassed the required administrative permission.');
    }
    catch (AccessDeniedHttpException $exception) {
      self::assertSame(403, $exception->getStatusCode());
    }
  }

  /**
   * Provides both mutation routes for IPv4 and IPv6 addresses.
   */
  public static function actions(): array {
    return [['access', '192.0.2.1'], ['remove', '192.0.2.1'], ['access', '2001:db8::1'], ['remove', '2001:db8::1']];
  }

  /**
   * Tests country details render existing IPs without lookup or mutation.
   */
  public function testCountryDetailsAreReadOnly(): void {
    $this->configurePolicy();
    $storage = $this->getMockBuilder(\Drupal\country_access_filter\Service\storage\IpStorage::class)
      ->disableOriginalConstructor()->getMock();
    $storage->expects(self::once())->method('loadByCountry')->with('UA')->willReturn([$this->ip()]);
    $storage->expects(self::never())->method('save');
    $storage->expects(self::never())->method('delete');
    $storage->expects(self::never())->method('updateAccessByCountries');
    $service = $this->getMockBuilder(\Drupal\country_access_filter\Service\CountryService::class)
      ->disableOriginalConstructor()->getMock();
    $service->expects(self::never())->method('hasAccess');
    $service->expects(self::never())->method('getIpInfo');
    $this->container->set('country_access_filter.ip_storage', $storage);
    $this->container->set('country_access_filter.country_service', $service);
    $controller = \Drupal\country_access_filter\Controller\FormController::create($this->container);

    self::assertCount(1, $controller->countryDetailsAjaxCallback('UA')['#rows']);
  }

}
