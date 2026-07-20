<?php

namespace Drupal\country_access_filter\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\country_access_filter\Service\Helper;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

class FormController extends ControllerBase {

  private Connection $db;

  private Helper $helper;

  private ClientInterface $httpClient;

  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);

    $instance->db = $container->get('database');
    $instance->helper = $container->get('country_access_filter.helper');
    $instance->httpClient = $container->get('http_client');

    return $instance;
  }

  public function countryDetailsAjaxCallback($country): array {
    try {
      $rows = $this->db->select('country_access_filter_ips', 'i')
        ->fields('i', ['ip', 'status'])
        ->condition('country_code', $country)
        ->execute()
        ->fetchAllKeyed();
    }
    catch (Exception) {
      $rows = [];
    }

    $table = [
      '#theme' => 'table',
      '#header' => [
        $this->t('IP'),
        $this->t('Status'),
        $this->t('Access'),
        $this->t('Remove from ban list'),
        $this->t('IP info'),
      ],
      '#rows' => [],
      '#attributes' => [
        'class' => ['country-details-table'],
      ],
    ];

    foreach ($rows as $ip => $status) {
      $table['#rows'][] = [
        'data' => [
          [
            'data' => $this->ipToReadable($ip),
            'class' => ['ip'],
          ],
          [
            'data' => $this->getIpStatusText($status),
            'class' => ['status'],
          ],
          [
            'data' => $this->getIpStatusLink($ip, $status),
            'class' => ['ip-set-status-link'],
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
        'data-id' => $ip,
      ];
    }

    return $table;
  }

  public function ipSetStatusAjaxCallback(int $ip, int $status): AjaxResponse {
    $response = new AjaxResponse();

    $country = $this->getIpCountry($ip);

    // Update in DB.
    try {
      $this->db->update('country_access_filter_ips')
        ->fields([
          'status' => $status,
        ])
        ->condition('ip', $ip)
        ->execute();
    }
    catch (Exception) {
    }

    // Update in the table.
    $response->addCommand(new HtmlCommand("tr[data-id=$ip] td.status", $this->getIpStatusText($status)));
    $link = $this->getIpStatusLink($ip, $status)->toRenderable();
    $response->addCommand(new HtmlCommand("tr[data-id=$ip] td.ip-set-status-link", $link));
    // Update in the countries table.
    $this->addCountryTableRowUpdateCommands($response, $country);
    // Message.
    $response->addCommand(new MessageCommand($this->t('Status for IP @ip has been changed.', ['@ip' => $this->ipToReadable($ip)])));

    return $response;
  }

  public function ipRemoveAjaxCallback(int $ip): AjaxResponse {
    $response = new AjaxResponse();

    try {
      $country = $this->getIpCountry($ip);

      $this->db->delete('country_access_filter_ips')
        ->condition('ip', $ip)
        ->execute();

      // Update in the IPs table.
      $response->addCommand(new RemoveCommand("tr[data-id=$ip]"));
      // Update in the countries table.
      $this->addCountryTableRowUpdateCommands($response, $country);
      // Message.
      $response->addCommand(new MessageCommand($this->t('IP @ip has been removed.', ['@ip' => $this->ipToReadable($ip)])));
    }
    catch (Exception) {
    }

    return $response;
  }

  public function ipInfoCallback(int $ip): Response {
    $readable_ip = $this->ipToReadable($ip);

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

  private function addCountryTableRowUpdateCommands(AjaxResponse $response, ?string $country): void {
    if (!$country) {
      return;
    }

    $stats = $this->getCountryStats($country);
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

  private function getCountryStats(string $country): ?object {
    try {
      $query = $this->db
        ->select('country_access_filter_ips', 'i')
        ->fields('i', ['country_code'])
        ->condition('country_code', $country)
        ->groupBy('country_code');
      $query->addExpression('COUNT(country_code)', 'count');
      $query->addExpression('SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END)', 'allowed');
      $query->addExpression('SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END)', 'denied');
      $stats = $query->execute()->fetchObject();
    }
    catch (Exception) {
      $stats = FALSE;
    }

    return $stats ?: NULL;
  }

  private function getIpCountry(int $ip): ?string {
    try {
      $country = $this->db->select('country_access_filter_ips', 'i')
        ->fields('i', ['country_code'])
        ->condition('ip', $ip)
        ->execute()
        ->fetchField();
    }
    catch (Exception) {
      $country = FALSE;
    }

    return $country ?: NULL;
  }

  private function getCountryStatsText(object $stats): TranslatableMarkup {
    return $this->t('@count (allowed @allowed, denied @denied)', [
      '@count' => $stats->count,
      '@allowed' => $stats->allowed,
      '@denied' => $stats->denied,
    ]);
  }

  private function getCountryRowClasses(string $country, object $stats): array {
    $classes = [];
    $country_allowed = $this->helper->isCountryAllowed($country);

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

  private function ipToReadable(int $ip): string {
    return long2ip($ip);
  }

  private function getIpStatusText(int $status): string {
    return $status ? '✅' : '⛔';
  }

  private function getIpStatusLink(int $ip, int $status): Link {
    return Link::createFromRoute(
      $status ? $this->t('Deny access') : $this->t('Give access'),
      'country_access_filter.form.country.details.ip.status',
      [
        'status' => $status ? 0 : 1,
        'ip' => $ip,
      ],
      [
        'attributes' => [
          'class' => ['use-ajax', 'caf-action'],
        ],
      ]
    );
  }

  private function getIpRemoveLink(int $ip): Link {
    return Link::createFromRoute(
      $this->t('Remove'),
      'country_access_filter.form.country.details.ip.remove',
      ['ip' => $ip],
      [
        'attributes' => [
          'class' => ['use-ajax', 'caf-action'],
        ],
      ]
    );
  }

  private function getIpInfoLink(int $ip): Link {
    return Link::createFromRoute(
      $this->t('IP info'),
      'country_access_filter.form.country.details.ip.info',
      ['ip' => $ip],
      [
        'attributes' => [
          'target' => '_blank',
          'class' => ['caf-action'],
        ],
      ],
    );
  }

}
