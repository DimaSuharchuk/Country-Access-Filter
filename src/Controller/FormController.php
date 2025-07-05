<?php

namespace Drupal\country_access_filter\Controller;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\MessageCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

class FormController extends ControllerBase {

  private Connection $db;

  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);

    $instance->db = $container->get('database');

    return $instance;
  }

  public function countryDetails($country): array {
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
      ],
      '#rows' => [],
      '#attributes' => [
        'class' => ['country-details-table'],
      ],
    ];

    foreach ($rows as $ip => $status) {
      $table['#rows'][] = [
        'data' => [
          long2ip($ip),
          $this->_getIpStatusText($status),
          $this->_getIpStatusLink($ip, $status),
          $this->_getIpRemoveLink($ip),
        ],
        'class' => ['row'],
        'data-id' => $ip,
      ];
    }

    return $table;
  }

  public function ipSetStatus($ip, $status): AjaxResponse {
    $response = new AjaxResponse();

    if (!is_numeric($ip) || !is_numeric($status)) {
      return $response;
    }

    // Update in DB.
    try {
      $this->db->update('country_access_filter_ips')
        ->fields([
          'status' => (int) $status,
        ])
        ->condition('ip', $ip)
        ->execute();
    }
    catch (Exception) {
    }

    // Update on form.
    $row = new FormattableMarkup('<tr class="row" data-id="@ip"><td>@ip_human</td><td>@status_text</td><td>@status_link</td><td>@remove_link</td></tr>', [
      '@ip' => $ip,
      '@ip_human' => long2ip($ip),
      '@status_text' => $this->_getIpStatusText($status),
      '@status_link' => $this->_getIpStatusLink($ip, $status)->toString(),
      '@remove_link' => $this->_getIpRemoveLink($ip)->toString(),
    ]);
    $response->addCommand(new ReplaceCommand("tr[data-id=$ip]", $row));
    $response->addCommand(new MessageCommand($this->t('Status for IP @ip has been changed.', ['@ip' => long2ip($ip)])));

    return $response;
  }

  public function ipRemove($ip): AjaxResponse {
    $response = new AjaxResponse();

    try {
      $this->db->delete('country_access_filter_ips')
        ->condition('ip', $ip)
        ->execute();

      $response->addCommand(new RemoveCommand("tr[data-id=$ip]"));
      $response->addCommand(new MessageCommand($this->t('IP @ip has been removed.', ['@ip' => long2ip($ip)])));
    }
    catch (Exception) {
    }

    return $response;
  }

  private function _getIpStatusText($status): string {
    return $status ? '✅' : '⛔';
  }

  private function _getIpStatusLink($ip, $status): Link {
    return Link::createFromRoute(
      $status ? $this->t('Deny access') : $this->t('Give access'),
      'country_access_filter.form.country.details.ip.status',
      [
        'status' => (int) !$status,
        'ip' => $ip,
      ],
      [
        'attributes' => [
          'class' => ['use-ajax', 'action'],
        ],
      ]
    );
  }

  private function _getIpRemoveLink($ip): Link {
    return Link::createFromRoute(
      $this->t('Remove'),
      'country_access_filter.form.country.details.ip.remove',
      ['ip' => $ip],
      [
        'attributes' => [
          'class' => ['use-ajax', 'action'],
        ],
      ]
    );
  }

}
