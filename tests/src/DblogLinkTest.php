<?php

namespace Drupal\Tests\country_access_filter;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

require_once dirname(__DIR__, 2) . '/country_access_filter.module';

/**
 * Tests IP information links on watchdog event tables.
 */
final class DblogLinkTest extends AuditTestBase {

  /**
   * Tests permissions, normalization, table scope, and preserved operations.
   *
   * @param string $hostname
   *   The stored watchdog hostname.
   * @param bool $permission
   *   Whether the viewer can access IP information.
   * @param bool $event
   *   Whether this is a watchdog event table.
   * @param string|null $normalized
   *   The expected IP, or NULL when no action should be added.
   *
   * @dataProvider hostnames
   */
  public function testIpAction(string $hostname, bool $permission, bool $event, ?string $normalized): void {
    $this->configurePolicy();
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn($permission);
    $this->container->set('current_user', $account);
    $this->container->set('cache_contexts_manager', new CacheContextsManager($this->container, ['user.permissions']));
    $existing = ['#markup' => '<a href="/existing">Existing operation</a>'];
    $variables = [
      'attributes' => ['class' => [$event ? 'dblog-event' : 'another-table']],
      'rows' => [
        // Deliberately reverse the core row order to avoid index coupling.
        ['cells' => [['content' => new TranslatableMarkup('Operations')], ['content' => $existing]]],
        ['cells' => [['content' => new TranslatableMarkup('Hostname')], ['content' => $hostname]]],
      ],
    ];
    country_access_filter_preprocess_table($variables);
    $content = $variables['rows'][0]['cells'][1]['content'];

    if ($normalized === NULL) {
      self::assertSame($existing, $content);

      return;
    }

    self::assertSame($existing, $content['existing']);
    $link = $content['ip_info'];
    self::assertSame('country_access_filter.form.country.details.ip.info', $link['#url']->getRouteName());
    self::assertSame(['ip_input' => $normalized], $link['#url']->getRouteParameters());
    self::assertSame('_blank', $link['#url']->getOption('attributes')['target']);
    self::assertSame('noopener', $link['#url']->getOption('attributes')['rel']);
    self::assertInstanceOf(AccessResult::class, $link['#access']);
    self::assertSame($permission, $link['#access']->isAllowed());
    self::assertContains('user.permissions', $link['#access']->getCacheContexts());
  }

  /**
   * Provides valid and invalid hostnames, permissions, and unrelated tables.
   */
  public static function hostnames(): array {
    return [
      ['192.0.2.1', TRUE, TRUE, '192.0.2.1'],
      ['2001:db8::1', TRUE, TRUE, '2001:db8::1'],
      ['::ffff:192.0.2.1', TRUE, TRUE, '192.0.2.1'],
      ['192.0.2.1', FALSE, TRUE, '192.0.2.1'],
      ['example.com', TRUE, TRUE, NULL],
      ['', TRUE, TRUE, NULL],
      ['<script>alert(1)</script>', TRUE, TRUE, NULL],
      ['192.0.2.1', TRUE, FALSE, NULL],
    ];
  }

}
