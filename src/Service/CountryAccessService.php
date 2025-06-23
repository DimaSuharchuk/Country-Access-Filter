<?php

namespace Drupal\country_access_filter\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Exception;
use function explode;

class CountryAccessService {

  protected LoggerChannelInterface $logger;

  protected ImmutableConfig $config;

  public function __construct(
    protected Connection $database,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger,
    protected Helper $helper,
  ) {
    $this->config = $config_factory->get('country_access_filter.settings');
    $this->logger = $logger->get('country_access_filter');
  }

  public function hasAccess(string $ip): bool {
    try {
      $status = $this->database->select('country_access_filter_ips', 'ips')
        ->fields('ips', ['status'])
        ->condition('ip', ip2long($ip))
        ->execute()
        ->fetchField();
    }
    catch (Exception $e) {
      $status = FALSE;
      $this->logger->error($e->getMessage());
    }

    return $status !== FALSE ? (bool) $status : $this->checkAccessFromExternalService($ip) === CountryAccess::Allow;
  }

  protected function checkAccessFromExternalService(string $ip): CountryAccess {
    if (!$country_code = $this->helper->getCountryCodeByIp($ip)) {
      return CountryAccess::Error;
    }

    $allowed_countries = explode(' ', $this->config->get('countries'));
    $status = in_array($country_code, $allowed_countries) ? CountryAccess::Allow : CountryAccess::Deny;

    try {
      $this->database->insert('country_access_filter_ips')
        ->fields([
          'ip' => ip2long($ip),
          'status' => (int) ($status === CountryAccess::Allow),
          'country_code' => $country_code,
        ])
        ->execute();
    }
    catch (Exception) {
    }

    return $status;
  }

}
