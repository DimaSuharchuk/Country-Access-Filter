<?php

namespace Drupal\country_access_filter\Service;

use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use function explode;

class CountryAccessService {

  protected Connection $database;

  protected ConfigFactoryInterface $configFactory;

  protected ClientInterface $httpClient;

  protected SerializationInterface $serialization;

  protected LoggerChannelInterface $logger;

  protected ImmutableConfig $config;

  public function __construct(
    Connection                    $database,
    ConfigFactoryInterface        $config_factory,
    ClientInterface               $http_client,
    SerializationInterface        $serialization,
    LoggerChannelFactoryInterface $logger
  ) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->config = $config_factory->get('country_access_filter.settings');
    $this->httpClient = $http_client;
    $this->serialization = $serialization;
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
    $url = "http://www.geoplugin.net/json.gp?ip=$ip";

    try {
      $response = $this->httpClient->request('GET', $url);
      $data = $this->serialization->decode($response->getBody()->getContents());

      if ($this->config->get('debug_mode')) {
        $this->logger->debug('Debug: <pre>@response</pre>', ['@response' => print_r($data, TRUE)]);
      }

      if (empty($data['geoplugin_countryCode'])) {
        return CountryAccess::Error;
      }

      $country_code = $data['geoplugin_countryCode'];
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
      catch (Exception $e) {
        $this->logger->error($e->getMessage());
      }

      return $status;
    }
    catch (GuzzleException $e) {
      $this->logger->error('Error fetching country data: @message', ['@message' => $e->getMessage()]);
    }

    return CountryAccess::Error;
  }

}
