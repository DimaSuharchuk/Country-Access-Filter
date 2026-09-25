<?php

namespace Drupal\country_access_filter\Service;

use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Config\ConfigBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Locale\CountryManagerInterface;
use Drupal\country_access_filter\AccessMode;
use Drupal\country_access_filter\DTO\Ip;
use Drupal\country_access_filter\DTO\IpInput;
use Drupal\country_access_filter\IpAccess;
use Drupal\country_access_filter\Service\storage\IpStorage;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Determines IP access from the configured country policy.
 */
class CountryService {

  /**
   * Country code used when the IP lookup provider omits a country code.
   *
   * @var string
   */
  const COUNTRY_CODE_UNDEFINED = 'XX';

  /**
   * Constructs the country access service.
   *
   * @param \Drupal\country_access_filter\Service\storage\IpStorage $ipStorage
   *   The storage service for IP access decisions.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client for country lookup requests.
   * @param \Drupal\Component\Serialization\SerializationInterface $serialization
   *   The decoder for country lookup responses.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory.
   * @param \Drupal\Core\Locale\CountryManagerInterface $countryManager
   *   The service that provides the list of countries.
   */
  public function __construct(
    protected IpStorage $ipStorage,
    protected ClientInterface $httpClient,
    protected SerializationInterface $serialization,
    protected ConfigFactoryInterface $configFactory,
    protected CountryManagerInterface $countryManager,
  ) {}

  /**
   * Determines whether the visitor at the given IP address may access the site.
   *
   * @param \Drupal\country_access_filter\DTO\IpInput $ip_input
   *   The IP address to check.
   *
   * @return bool
   *   TRUE when access is allowed, or FALSE otherwise.
   */
  public function hasAccess(IpInput $ip_input): bool {
    if (!$ip_input->isValid()) {
      return FALSE;
    }

    $ip = $this->ipStorage->load($ip_input);

    if (!$ip) {
      $country_code = $this->getCountryCodeByIP($ip_input);

      if (!$country_code) {
        return FALSE;
      }

      $access = $this->isCountryAllowed($country_code) ? IpAccess::Allowed : IpAccess::Denied;

      $ip = new Ip($ip_input->getStorableValue(), $access, $country_code);
      $this->ipStorage->save($ip);
    }

    return $ip->isAllowed();
  }

  /**
   * Looks up the country code for a valid IP address.
   *
   * @param \Drupal\country_access_filter\DTO\IpInput $ip
   *   The IP address to look up.
   *
   * @return string|null
   *   The two-letter country code, or NULL if the lookup fails.
   */
  public function getCountryCodeByIP(IpInput $ip): ?string {
    if (!$ip->isValid()) {
      return NULL;
    }

    try {
      $response = $this->httpClient->request('GET', "http://ip-api.com/json/{$ip->toReadable()}?fields=countryCode", [
        'connect_timeout' => 2,
        'timeout' => 5,
      ]);
      $data = $this->serialization->decode($response->getBody()->getContents());

      if (!is_array($data)) {
        return NULL;
      }

      $country_code = $data['countryCode'] ?? static::COUNTRY_CODE_UNDEFINED;

      if (!is_string($country_code) || !preg_match('/^[A-Z]{2}$/D', $country_code)) {
        return NULL;
      }

      return $country_code;
    }
    catch (GuzzleException) {
    }

    return NULL;
  }

  /**
   * Gets the countries allowed by the supplied or active access policy.
   *
   * @param \Drupal\Core\Config\ConfigBase|null $config
   *   The configuration to evaluate, or NULL to use active configuration.
   *
   * @return array
   *   Country codes keyed by their country code.
   */
  public function getAllowedCountries(?ConfigBase $config = NULL): array {
    $config ??= $this->configFactory->get('country_access_filter.settings');
    $selected_countries = preg_split('/\s+/', trim((string) $config->get('countries')), -1, PREG_SPLIT_NO_EMPTY);
    $countries_allowed = $config->get('country_access_mode') === AccessMode::ALLOW->value ? $selected_countries : array_diff(array_keys($this->countryManager->getList()), $selected_countries);

    return array_combine($countries_allowed, $countries_allowed);
  }

  /**
   * Applies country rules to stored IP addresses.
   *
   * @param \Drupal\Core\Config\ConfigBase $config
   *   The configuration containing the country access policy.
   */
  public function synchronizeAccess(ConfigBase $config): void {
    $allowed = array_keys($this->getAllowedCountries($config));
    $denied = array_diff(array_keys($this->countryManager->getList()), $allowed);

    if ($allowed) {
      $this->ipStorage->updateAccessByCountries($allowed, IpAccess::Allowed);
    }

    if ($denied) {
      $this->ipStorage->updateAccessByCountries($denied, IpAccess::Denied);
    }
  }

  /**
   * Determines whether the country code is allowed by the active policy.
   *
   * @param string $country
   *   The ISO 3166-1 alpha-2 country code to check.
   *
   * @return bool
   *   TRUE if access from the country is allowed, or FALSE otherwise.
   */
  public function isCountryAllowed(string $country): bool {
    return array_key_exists($country, $this->getAllowedCountries());
  }

}
