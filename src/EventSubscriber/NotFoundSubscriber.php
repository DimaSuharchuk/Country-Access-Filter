<?php

namespace Drupal\country_access_filter\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\country_access_filter\DTO\IpInput;
use Drupal\country_access_filter\DTO\Tracked404;
use Drupal\country_access_filter\Service\storage\IpStorage;
use Drupal\country_access_filter\Service\storage\Tracker404Storage;
use Exception;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tracks repeated 404 responses and bans IP addresses over the limit.
 */
class NotFoundSubscriber implements EventSubscriberInterface {

  /**
   * The logger for country access and 404 tracking events.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs the 404 tracking event subscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\Core\Database\Connection $db
   *   The database connection.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The active request stack.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger channel factory.
   * @param \Drupal\country_access_filter\Service\storage\IpStorage $ipStorage
   *   The storage service for IP access decisions.
   * @param \Drupal\country_access_filter\Service\storage\Tracker404Storage $trackerStorage
   *   The storage service for 404 tracking records.
   */
  public function __construct(
    readonly protected ConfigFactoryInterface $configFactory,
    readonly protected Connection $db,
    readonly protected RequestStack $requestStack,
    LoggerChannelFactoryInterface $logger,
    protected IpStorage $ipStorage,
    protected Tracker404Storage $trackerStorage,
  ) {
    $this->logger = $logger->get('country_access_filter');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::EXCEPTION => ['onException', 0],
    ];
  }

  /**
   * Tracks a main-request 404 for a previously recorded IP address.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   The exception event to inspect.
   */
  public function onException(ExceptionEvent $event): void {
    if (!$event->isMainRequest() || !$event->getThrowable() instanceof NotFoundHttpException) {
      return;
    }

    $config = $this->configFactory->get('country_access_filter.settings');

    if (!$config->get('enabled') || !$config->get('track_404')) {
      return;
    }

    $threshold = (int) $config->get('track_404_threshold');
    $window_seconds = $config->get('track_404_window') ?: NULL;

    try {
      $ip_input = $this->requestStack->getCurrentRequest()->getClientIp();
      $ip = $this->ipStorage->load(new IpInput($ip_input));

      if (!$ip) {
        return;
      }

      $tracker = $this->trackerStorage->load($ip);

      $now = time();
      $is_expired = $tracker && $window_seconds && ($now - $tracker->getFirstTimestamp() > $window_seconds);
      $first_timestamp = $tracker && !$is_expired ? $tracker->getFirstTimestamp() : $now;
      $new_count = $tracker && !$is_expired ? $tracker->getCount() + 1 : 1;
      $updated_tracker = new Tracked404($ip, $first_timestamp, $new_count);

      if ($new_count >= $threshold) {
        if (!$this->ipStorage->deny($ip)) {
          $this->logger->error('Failed to ban IP @ip after exceeding 404s.', [
            '@ip' => $ip->toReadable(),
          ]);
          return;
        }

        if ($tracker && !$this->trackerStorage->delete($tracker)) {
          $this->logger->error('Failed to remove the 404 tracker for banned IP @ip.', [
            '@ip' => $ip->toReadable(),
          ]);
        }

        $this->logger->info('IP @ip is banned for exceeding 404s. Country @country.', [
          '@ip' => $ip->toReadable(),
          '@country' => $ip->getCountryCode(),
        ]);
      }
      elseif (!$this->trackerStorage->save($updated_tracker)) {
        $this->logger->error('Failed to save the 404 tracker for IP @ip.', [
          '@ip' => $ip->toReadable(),
        ]);
      }
    }
    catch (Exception $e) {
      $this->logger->error($e->getMessage());
    }
  }

}
