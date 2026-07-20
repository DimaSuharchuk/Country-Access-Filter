<?php

namespace Drupal\country_access_filter\Service;

use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Locale\CountryManagerInterface;
use Drupal\country_access_filter\AccessMode;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class Helper {

  public function __construct(
    protected ClientInterface $httpClient,
    protected SerializationInterface $serialization,
    protected ConfigFactoryInterface $configFactory,
    protected CountryManagerInterface $countryManager,
  ) {}

  function isValidIpv4(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== FALSE;
  }

  function getCountryCodeByIP(string $ip): ?string {
    if (!$this->isValidIpv4($ip)) {
      return NULL;
    }

    try {
      $response = $this->httpClient->request('GET', "http://ip-api.com/json/$ip?fields=countryCode");
      $data = $this->serialization->decode($response->getBody()->getContents());

      return $data['countryCode'] ?? 'XX';
    }
    catch (GuzzleException) {
    }

    return NULL;
  }

  function getAllowedCountries(): array {
    $result = &drupal_static(__METHOD__);

    if ($result === NULL) {
      $config = $this->configFactory->get('country_access_filter.settings');
      $selected_countries = explode(' ', $config->get('countries'));
      $countries_allowed = $config->get('country_access_mode') === AccessMode::ALLOW->value ? $selected_countries : array_diff(array_keys($this->countryManager->getList()), $selected_countries);

      $result = array_combine($countries_allowed, $countries_allowed);
    }

    return $result;
  }

  function isCountryAllowed(string $country): bool {
    return array_key_exists($country, $this->getAllowedCountries());
  }

}
