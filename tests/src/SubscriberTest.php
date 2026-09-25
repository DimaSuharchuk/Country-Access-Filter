<?php

namespace Drupal\Tests\country_access_filter;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\LogMessageParser;
use Drupal\Core\Session\AccountInterface;
use Drupal\country_access_filter\DTO\IpInput;
use Drupal\country_access_filter\DTO\Tracked404;
use Drupal\country_access_filter\EventSubscriber\NotFoundSubscriber;
use Drupal\country_access_filter\EventSubscriber\Subscriber;
use Drupal\country_access_filter\Service\CountryService;
use Drupal\country_access_filter\Service\storage\IpStorage;
use Drupal\country_access_filter\Service\storage\Tracker404Storage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests subscriber behavior.
 */
final class SubscriberTest extends AuditTestBase {

  private array $logContexts = [];

  private array $errors = [];

  /**
   * Dispatches a simulated 404 exception event.
   *
   * @param array $config
   *   The configuration values for this event.
   * @param \Throwable|null $exception
   *   The exception to dispatch, or NULL to dispatch a 404.
   * @param int $type
   *   The request type.
   * @param \Drupal\country_access_filter\Service\storage\IpStorage|null $ipStorage
   *   The IP storage service, or NULL to use a real storage service.
   * @param \Drupal\country_access_filter\Service\storage\Tracker404Storage|null $trackerStorage
   *   The tracker storage service, or NULL to use a real storage service.
   */
  private function track(array $config = [], ?\Throwable $exception = NULL, int $type = HttpKernelInterface::MAIN_REQUEST, ?IpStorage $ipStorage = NULL, ?Tracker404Storage $trackerStorage = NULL): void {
    // Fresh storage instances model independent HTTP requests.
    drupal_static_reset();
    $request = Request::create('/missing', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.1']);
    $stack = new RequestStack();
    $stack->push($request);
    $logger = $this->createMock(LoggerChannelInterface::class);

    $logger->method('info')->willReturnCallback(function ($message, $context) {
      $this->logContexts[] = [$message, $context];
    });

    $logger->method('error')->willReturnCallback(function ($message, $context) {
      $this->errors[] = [$message, $context];
    });

    $factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $factory->method('get')->willReturn($logger);
    $subscriber = new NotFoundSubscriber($this->configFactory($config), $this->db, $stack, $factory, $ipStorage ?? new IpStorage($this->db), $trackerStorage ?? new Tracker404Storage($this->db));
    $subscriber->onException(new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, $type, $exception ?? new NotFoundHttpException()));
  }

  /**
   * Tests ban at threshold and cleanup.
   */
  public function testBanAtThresholdAndCleanup(): void {
    (new IpStorage($this->db))->save($this->ip());

    for ($i = 1; $i < 5; $i++) {
      $this->track();
      self::assertTrue($this->storedAccess());
    }

    $this->track();
    self::assertFalse($this->storedAccess());
    self::assertNull((new Tracker404Storage($this->db))->load($this->ip()));
  }

  /**
   * Tests threshold one bans first404.
   */
  public function testThresholdOneBansFirst404(): void {
    (new IpStorage($this->db))->save($this->ip());
    $this->track(['track_404_threshold' => 1]);
    self::assertFalse($this->storedAccess());
    self::assertNull((new Tracker404Storage($this->db))->load($this->ip()));
    self::assertSame('UA', (new IpStorage($this->db))->load(new IpInput('192.0.2.1'))->getCountryCode());
    self::assertTrue((new IpStorage($this->db))->load(new IpInput('192.0.2.1'))->isAccessLocked());
    $this->configurePolicy();
    $this->container->get('config.factory')->getEditable('country_access_filter.settings')->save();
    self::assertFalse($this->storedAccess());
  }

  /**
   * Tests autobans preserve both resolved and legitimately unknown countries.
   *
   * @param string $country
   *   The country originally returned by the provider.
   *
   * @dataProvider banCountries
   */
  public function testAutobanPreservesCountry(string $country): void {
    (new IpStorage($this->db))->save($this->ip('192.0.2.1', \Drupal\country_access_filter\IpAccess::Allowed, $country));
    $this->track(['track_404_threshold' => 1]);
    $ip = (new IpStorage($this->db))->load(new IpInput('192.0.2.1'));
    self::assertFalse($ip->isAllowed());
    self::assertTrue($ip->isAccessLocked());
    self::assertSame($country, $ip->getCountryCode());
  }

  /**
   * Provides countries that must survive an automatic ban unchanged.
   */
  public static function banCountries(): array {
    return [['UA'], ['US'], ['XX']];
  }

  /**
   * Tests threshold one bans when previous window expired.
   */
  public function testThresholdOneBansWhenPreviousWindowExpired(): void {
    (new IpStorage($this->db))->save($this->ip());
    (new Tracker404Storage($this->db))->save(new Tracked404($this->ip(), time() - 10000, 4));
    $this->track(['track_404_threshold' => 1]);
    self::assertFalse($this->storedAccess());
    self::assertNull((new Tracker404Storage($this->db))->load($this->ip()));
  }

  /**
   * Tests failed ban preserves tracker and does not log success.
   */
  public function testFailedBanPreservesTrackerAndDoesNotLogSuccess(): void {
    (new IpStorage($this->db))->save($this->ip());
    (new Tracker404Storage($this->db))->save(new Tracked404($this->ip(), time(), 4));
    $storage = $this->getMockBuilder(IpStorage::class)->setConstructorArgs([$this->db])->onlyMethods(['deny'])->getMock();
    $storage->expects(self::once())->method('deny')->willReturn(FALSE);
    $this->track(ipStorage: $storage);
    self::assertTrue($this->storedAccess());
    self::assertSame(4, (new Tracker404Storage($this->db))->load($this->ip())->getCount());
    self::assertSame([], $this->logContexts);
    self::assertCount(1, $this->errors);
    self::assertSame('192.0.2.1', $this->errors[0][1]['@ip']);
  }

  /**
   * Tests failed tracker save is logged.
   */
  public function testFailedTrackerSaveIsLogged(): void {
    (new IpStorage($this->db))->save($this->ip());
    $storage = $this->getMockBuilder(Tracker404Storage::class)->setConstructorArgs([$this->db])->onlyMethods(['save'])->getMock();
    $storage->expects(self::once())->method('save')->willReturn(FALSE);
    $this->track(trackerStorage: $storage);
    self::assertTrue($this->storedAccess());
    self::assertSame([], $this->logContexts);
    self::assertCount(1, $this->errors);
  }

  /**
   * Tests failed tracker cleanup does not undo ban.
   */
  public function testFailedTrackerCleanupDoesNotUndoBan(): void {
    (new IpStorage($this->db))->save($this->ip());
    (new Tracker404Storage($this->db))->save(new Tracked404($this->ip(), time(), 4));
    $storage = $this->getMockBuilder(Tracker404Storage::class)->setConstructorArgs([$this->db])->onlyMethods(['delete'])->getMock();
    $storage->expects(self::once())->method('delete')->willReturn(FALSE);
    $this->track(trackerStorage: $storage);
    self::assertFalse($this->storedAccess());
    self::assertCount(1, $this->errors);
    self::assertCount(1, $this->logContexts);
  }

  /**
   * Tests disabled module does not track.
   */
  public function testDisabledModuleDoesNotTrack(): void {
    (new IpStorage($this->db))->save($this->ip());
    $this->track(['enabled' => FALSE]);
    self::assertNull((new Tracker404Storage($this->db))->load($this->ip()));
  }

  /**
   * Tests disabled tracking and non404 are ignored.
   */
  public function testDisabledTrackingAndNon404AreIgnored(): void {
    (new IpStorage($this->db))->save($this->ip());
    $this->track(['track_404' => FALSE]);
    $this->track([], new AccessDeniedHttpException());
    self::assertNull((new Tracker404Storage($this->db))->load($this->ip()));
  }

  /**
   * Tests subrequest does not count as another visitor404.
   */
  public function testSubrequestDoesNotCountAsAnotherVisitor404(): void {
    (new IpStorage($this->db))->save($this->ip());
    $this->track([], NULL, HttpKernelInterface::SUB_REQUEST);
    self::assertNull((new Tracker404Storage($this->db))->load($this->ip()));
  }

  /**
   * Tests expired window starts again.
   */
  public function testExpiredWindowStartsAgain(): void {
    (new IpStorage($this->db))->save($this->ip());
    (new Tracker404Storage($this->db))->save(new Tracked404($this->ip(), time() - 10000, 4));
    $this->track();
    self::assertTrue($this->storedAccess());
    self::assertSame(1, (new Tracker404Storage($this->db))->load($this->ip())->getCount());
  }

  /**
   * Tests unlimited window retains count.
   */
  public function testUnlimitedWindowRetainsCount(): void {
    (new IpStorage($this->db))->save($this->ip());
    (new Tracker404Storage($this->db))->save(new Tracked404($this->ip(), time() - 10000, 4));
    $this->track(['track_404_window' => '']);
    self::assertFalse($this->storedAccess());
  }

  /**
   * Tests ban log contains readable ip.
   */
  public function testBanLogContainsReadableIp(): void {
    (new IpStorage($this->db))->save($this->ip());
    $this->track(['track_404_threshold' => 2]);
    $this->track(['track_404_threshold' => 2]);
    self::assertCount(1, $this->logContexts);
    [$message, $context] = $this->logContexts[0];
    $placeholders = (new LogMessageParser())->parseMessagePlaceholders($message, $context);
    self::assertSame('192.0.2.1', $placeholders['@ip'] ?? NULL);
  }

  /**
   * Tests request filtering.
   *
   * @param bool $enabled
   *   Whether country filtering is enabled.
   * @param bool $authenticated
   *   Whether the current user is authenticated.
   * @param int $type
   *   The request type.
   * @param bool $allowed
   *   Whether the IP address is allowed.
   * @param bool $blocked
   *   Whether the request should be blocked.
   *
   * @dataProvider requestCases
   */
  public function testRequestFiltering(bool $enabled, bool $authenticated, int $type, bool $allowed, bool $blocked): void {
    $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.1']);
    $stack = new RequestStack();
    $stack->push($request);
    $user = $this->createMock(AccountInterface::class);
    $user->method('isAuthenticated')->willReturn($authenticated);
    $service = $this->getMockBuilder(CountryService::class)->disableOriginalConstructor()->onlyMethods(['hasAccess'])->getMock();
    $service->expects($enabled && !$authenticated && $type === HttpKernelInterface::MAIN_REQUEST ? self::once() : self::never())->method('hasAccess')->willReturn($allowed);
    $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $type);
    (new Subscriber($stack, $this->configFactory(['enabled' => $enabled]), $user, $service))->onKernelRequest($event);
    self::assertSame($blocked, $event->hasResponse());

    if ($blocked) {
      self::assertSame(503, $event->getResponse()->getStatusCode());
    }
  }

  /**
   * Provides request states for access subscriber tests.
   */
  public static function requestCases(): array {
    return [[TRUE, FALSE, 1, FALSE, TRUE], [TRUE, FALSE, 1, TRUE, FALSE], [FALSE, FALSE, 1, FALSE, FALSE], [TRUE, TRUE, 1, FALSE, FALSE], [TRUE, FALSE, 2, FALSE, FALSE]];
  }

}
