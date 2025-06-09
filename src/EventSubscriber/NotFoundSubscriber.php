<?php

namespace Drupal\country_access_filter\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\country_access_filter\Service\Helper;
use Exception;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

class NotFoundSubscriber implements EventSubscriberInterface {

  protected LoggerChannelInterface $logger;

  public function __construct(
    readonly protected ConfigFactoryInterface $configFactory,
    readonly protected Connection $db,
    readonly protected RequestStack $requestStack,
    LoggerChannelFactoryInterface $logger,
    readonly protected Helper $helper,
  ) {
    $this->logger = $logger->get('country_access_filter');
  }

  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::EXCEPTION => ['onException', 0],
    ];
  }

  public function onException(ExceptionEvent $event): void {
    if (!$event->getThrowable() instanceof NotFoundHttpException) {
      return;
    }

    $config = $this->configFactory->get('country_access_filter.settings');

    if (!$config->get('track_404')) {
      return;
    }

    $threshold = (int) $config->get('track_404_threshold');
    $window_hours = $config->get('track_404_window');
    $window_seconds = $window_hours ? ($window_hours * 3600) : NULL;

    try {
      $ip = $this->requestStack->getCurrentRequest()->getClientIp();
      $ip_int = ip2long($ip);
      $now = time();

      $record = $this->db->select('country_access_404_tracker', 't')
        ->fields('t')
        ->condition('ip', $ip_int)
        ->execute()
        ->fetchObject();

      if ($record) {
        $is_expired = $window_seconds && ($now - $record->first_404 > $window_seconds);

        if ($is_expired) {
          $this->db->update('country_access_404_tracker')
            ->fields([
              'count' => 1,
              'first_404' => $now,
            ])
            ->condition('ip', $ip_int)
            ->execute();
        }
        else {
          $new_count = $record->count + 1;

          if ($new_count > $threshold) {
            // Add to the blocklist.
            $this->db->merge('country_access_filter_ips')
              ->keys([
                'ip' => $ip_int,
              ])
              ->fields([
                'status' => 0,
                'country_code' => 'XX',
              ])
              ->execute();

            // Clean up.
            $this->db->delete('country_access_404_tracker')
              ->condition('ip', $ip_int)
              ->execute();

            $this->logger->info('IP @ip is banned for exceeding 404s. Country @country.', [
              '@ip' => $ip,
              '@country' => $this->helper->getCountryCodeByIP($ip),
            ]);
          }
          else {
            $this->db->update('country_access_404_tracker')
              ->fields(['count' => $new_count])
              ->condition('ip', $ip_int)
              ->execute();
          }
        }
      }
      else {
        $this->db->insert('country_access_404_tracker')
          ->fields([
            'ip' => $ip_int,
            'first_404' => $now,
            'count' => 1,
          ])
          ->execute();
      }
    }
    catch (Exception $e) {
      $this->logger->error($e->getMessage());
    }
  }

}
