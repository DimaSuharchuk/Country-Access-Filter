<?php

namespace Drupal\country_access_filter\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Link;
use Drupal\Core\Locale\CountryManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\country_access_filter\DTO\Ip;
use Drupal\country_access_filter\DTO\IpInput;
use Drupal\country_access_filter\IpAccess;
use Drupal\country_access_filter\Service\CountryService;
use Drupal\country_access_filter\Service\storage\IpStorage;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds country IP details and handles administrative IP actions.
 */
class FormController extends ControllerBase {

  // The settings form retains this controller when Drupal caches the form.
  use DependencySerializationTrait;

  /**
   * The country access service.
   */
  protected CountryService $countryService;

  /**
   * The HTTP client for IP information requests.
   */
  protected ClientInterface $httpClient;

  /**
   * The IP storage service.
   */
  protected IpStorage $ipStorage;

  /**
   * The country names' provider.
   */
  protected CountryManagerInterface $countryManager;

  /**
   * Creates the controller with its required services.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The service container.
   *
   * @return static
   *   The initialized controller.
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);

    $instance->countryService = $container->get('country_access_filter.country_service');
    $instance->httpClient = $container->get('http_client');
    $instance->ipStorage = $container->get('country_access_filter.ip_storage');
    $instance->countryManager = $container->get('country_manager');

    return $instance;
  }

  /**
   * Builds the AJAX table of IP addresses for a country.
   *
   * @param string $country
   *   The ISO 3166-1 alpha-2 country code.
   *
   * @return array
   *   The render array for the country IP table.
   */
  public function countryDetailsAjaxCallback(string $country): array {
    return $this->buildIpTable($this->ipStorage->loadByCountry($country));
  }

  /**
   * Gets the country name and code for an IP dialog title.
   *
   * @param string $country
   *   The country code.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The translated dialog title.
   */
  public function countryDetailsTitle(string $country): TranslatableMarkup {
    $name = $this->countryManager->getList()[$country] ?? $this->t('Unknown country');

    return $this->t('@country (@code) IPs', ['@country' => $name, '@code' => $country]);
  }

  /**
   * Builds the shared IP table for country details and individual search results.
   *
   * @param \Drupal\country_access_filter\DTO\Ip[] $ips
   *   The IP addresses to display.
   *
   * @return array
   *   The IP table with the existing administrative actions.
   */
  public function buildIpTable(array $ips): array {
    $table = [
      '#theme' => 'table',
      '#header' => [
        $this->t('IP'),
        $this->t('Access'),
        $this->t('Change access'),
        $this->t('Remove from ban list'),
        $this->t('IP info'),
      ],
      '#rows' => [],
      '#attributes' => [
        'class' => ['country-details-table'],
      ],
    ];

    foreach ($ips as $ip) {
      $table['#rows'][] = [
        'data' => [
          [
            'data' => $ip->toReadable(),
            'class' => ['ip'],
          ],
          [
            'data' => $this->getIpAccessText($ip),
            'class' => ['access'],
          ],
          [
            'data' => $this->getIpChangeAccessLink($ip),
            'class' => ['ip-change-access-link'],
          ],
          [
            'data' => $this->getIpRemoveLink($ip),
            'class' => ['ip-remove-link'],
          ],
          [
            'data' => $this->getIpInfoLink($ip),
            'class' => ['ip-info'],
          ],
        ],
        'class' => ['row'],
        'data-id' => $ip->getId(),
      ];
    }

    return $table;
  }

  /**
   * Changes an IP address's access and updates the AJAX tables.
   *
   * @param string $ip_input
   *   The IP address to update.
   * @param int $access
   *   The new access value: 1 to allow or 0 to deny.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The commands that update the tables and show the result.
   */
  public function ipChangeAccessAjaxCallback(string $ip_input, int $access): AjaxResponse {
    $response = new AjaxResponse();

    if (!$ip = $this->ipStorage->load(new IpInput($ip_input))) {
      return $response;
    }

    // Update in DB.
    $new_access = $access === 1 ? IpAccess::Allowed : IpAccess::Denied;
    $ip = new Ip($ip->getStorableValue(), $new_access, $ip->getCountryCode(), TRUE);

    if ($this->ipStorage->save($ip)) {
      // Update in the table.
      $row_selector = $this->getIpRowSelector($ip);
      $response->addCommand(new HtmlCommand("$row_selector td.access", $this->getIpAccessText($ip)));
      $link = $this->getIpChangeAccessLink($ip)->toRenderable();
      $response->addCommand(new HtmlCommand("$row_selector td.ip-change-access-link", $link));
      // Update in the countries table.
      $this->addCountryTableRowUpdateCommands($response, $ip);
      // Message.
      $response->addCommand(new MessageCommand($this->t('Access for IP @ip has been changed.', ['@ip' => $ip->toReadable()])));
    }
    else {
      $response->addCommand(new MessageCommand($this->t('Access for IP @ip has not been changed.', ['@ip' => $ip->toReadable()]), NULL, ['type' => 'error']));
    }

    return $response;
  }

  /**
   * Removes an IP address and updates the AJAX tables.
   *
   * @param string $ip_input
   *   The IP address to remove.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The commands that update the tables and show the result.
   */
  public function ipRemoveAjaxCallback(string $ip_input): AjaxResponse {
    $response = new AjaxResponse();

    if (!$ip = $this->ipStorage->load(new IpInput($ip_input))) {
      return $response;
    }

    if ($this->ipStorage->delete($ip)) {
      // Update in the IPs table.
      $response->addCommand(new RemoveCommand($this->getIpRowSelector($ip)));
      // Update in the countries table.
      $this->addCountryTableRowUpdateCommands($response, $ip);
      // Message.
      $response->addCommand(new MessageCommand($this->t('IP @ip has been removed.', ['@ip' => $ip->toReadable()])));
    }
    else {
      $response->addCommand(new MessageCommand($this->t('IP @ip has not been removed.', ['@ip' => $ip->toReadable()]), NULL, ['type' => 'error']));
    }

    return $response;
  }

  /**
   * Fetches plain-text geolocation information for a stored IP address.
   *
   * @param string $ip_input
   *   The IP address to look up.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The IP address and provider response, or a bad-request response.
   */
  public function ipInfoCallback(string $ip_input): Response {
    if (!$ip = $this->ipStorage->load(new IpInput($ip_input))) {
      return new Response((string) $this->t('Invalid IP address.'), Response::HTTP_BAD_REQUEST, [
        'Content-Type' => 'text/plain; charset=UTF-8',
      ]);
    }

    $readable_ip = $ip->toReadable();

    try {
      $response = $this->httpClient->request('GET', "http://ip-api.com/json/$readable_ip");
      $data = json_decode($response->getBody()->getContents(), TRUE) ?: [];
    }
    catch (GuzzleException $exception) {
      $data = [
        'error' => $exception->getMessage(),
      ];
    }

    return new Response($readable_ip . PHP_EOL . PHP_EOL . print_r($data, TRUE), Response::HTTP_OK, [
      'Content-Type' => 'text/plain; charset=UTF-8',
    ]);
  }

  /**
   * Updates the country statistics row after changing an IP address.
   *
   * @param \Drupal\Core\Ajax\AjaxResponse $response
   *   The AJAX response to append commands to.
   * @param \Drupal\country_access_filter\DTO\Ip $ip
   *   The updated or removed IP address.
   */
  private function addCountryTableRowUpdateCommands(AjaxResponse $response, Ip $ip): void {
    $country = $ip->getCountryCode();
    $stats = $this->ipStorage->getCountryStats()[$country] ?? FALSE;
    $row_selector = "#country-access-table tr[data-country=$country]";

    if (!$stats) {
      $response->addCommand(new RemoveCommand($row_selector));

      return;
    }

    $response->addCommand(new HtmlCommand("$row_selector td:nth-child(2)", $this->getCountryStatsText($stats)));
    $response->addCommand(new InvokeCommand($row_selector, 'removeClass', ['allowed mixed']));

    if ($classes = $this->getCountryRowClasses($country, $stats)) {
      $response->addCommand(new InvokeCommand($row_selector, 'addClass', [implode(' ', $classes)]));
    }
  }

  /**
   * Formats the summary counts for a country table row.
   *
   * @param object $stats
   *   Country counts for total, allowed, and denied IP addresses.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The translated country statistics.
   */
  private function getCountryStatsText(object $stats): TranslatableMarkup {
    return $this->t('@count (allowed @allowed, denied @denied)', [
      '@count' => $stats->count,
      '@allowed' => $stats->allowed,
      '@denied' => $stats->denied,
    ]);
  }

  /**
   * Gets the access indicator classes for a country table row.
   *
   * @param string $country
   *   The country's ISO 3166-1 alpha-2 code.
   * @param object $stats
   *   The number of allowed and denied IP addresses for the country.
   *
   * @return string[]
   *   The access classes for the row.
   */
  private function getCountryRowClasses(string $country, object $stats): array {
    $classes = [];
    $country_allowed = $this->countryService->isCountryAllowed($country);

    if ($country_allowed) {
      $classes[] = 'allowed';
    }

    if (
      $country_allowed && $stats->denied
      || !$country_allowed && $stats->allowed
    ) {
      $classes[] = 'mixed';
    }

    return $classes;
  }

  /**
   * Gets the CSS selector for an IP row.
   *
   * @param \Drupal\country_access_filter\DTO\Ip $ip
   *   The IP address displayed in the row.
   *
   * @return string
   *   The IP row selector.
   */
  private function getIpRowSelector(Ip $ip): string {
    return "tr[data-id='{$ip->getId()}']";
  }

  /**
   * Gets a readable indicator of the IP access decision.
   *
   * @param \Drupal\country_access_filter\DTO\Ip $ip
   *   The IP address to inspect.
   *
   * @return string
   *   A symbol indicating allowed or denied access.
   */
  private function getIpAccessText(Ip $ip): string {
    return $ip->isAllowed() ? '✅' : '⛔';
  }

  /**
   * Builds the link that changes the IP access decision.
   *
   * @param \Drupal\country_access_filter\DTO\Ip $ip
   *   The IP address to update.
   *
   * @return \Drupal\Core\Link
   *   The AJAX access action link.
   */
  private function getIpChangeAccessLink(Ip $ip): Link {
    $is_allowed = $ip->isAllowed();

    return Link::createFromRoute(
      $is_allowed ? $this->t('Deny access') : $this->t('Give access'),
      'country_access_filter.form.country.details.ip.access',
      [
        'access' => $is_allowed ? 0 : 1,
        'ip_input' => $ip->getId(),
      ],
      [
        'attributes' => [
          'class' => ['use-ajax', 'caf-action'],
        ],
      ]
    );
  }

  /**
   * Builds the link that removes the IP from storage.
   *
   * @param \Drupal\country_access_filter\DTO\Ip $ip
   *   The IP address to remove.
   *
   * @return \Drupal\Core\Link
   *   The AJAX remove action link.
   */
  private function getIpRemoveLink(Ip $ip): Link {
    return Link::createFromRoute(
      $this->t('Remove'),
      'country_access_filter.form.country.details.ip.remove',
      ['ip_input' => $ip->getId()],
      [
        'attributes' => [
          'class' => ['use-ajax', 'caf-action'],
        ],
      ]
    );
  }

  /**
   * Builds a link to the IP geolocation details page.
   *
   * @param \Drupal\country_access_filter\DTO\Ip $ip
   *   The IP address to look up.
   *
   * @return \Drupal\Core\Link
   *   The IP information link.
   */
  private function getIpInfoLink(Ip $ip): Link {
    return Link::createFromRoute(
      $this->t('IP info'),
      'country_access_filter.form.country.details.ip.info',
      ['ip_input' => $ip->getId()],
      [
        'attributes' => [
          'target' => '_blank',
          'class' => ['caf-action'],
        ],
      ],
    );
  }

}
