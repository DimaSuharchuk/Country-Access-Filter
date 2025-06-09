<?php

namespace Drupal\country_access_filter\Service;

use Drupal\Component\Serialization\SerializationInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class Helper {

  public function __construct(
    protected ClientInterface $httpClient,
    protected SerializationInterface $serialization,
  ) {
  }

  function isValidIpv4(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== FALSE;
  }

  function getCountryCodeByIP(string $ip): ?string {
    if (!$this->isValidIpv4($ip)) {
      return NULL;
    }

    $url = "http://www.geoplugin.net/json.gp?ip=$ip";

    try {
      $response = $this->httpClient->request('GET', $url);
      $data = $this->serialization->decode($response->getBody()->getContents());

      return $data['geoplugin_countryCode'] ?? 'XX';
    }
    catch (GuzzleException) {
    }

    return NULL;
  }

}
