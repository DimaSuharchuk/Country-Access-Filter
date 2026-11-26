<?php

namespace Drupal\Tests\country_access_filter;

use Drupal\Core\Database\Database;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\country_access_filter\DTO\Ip;
use Drupal\country_access_filter\DTO\IpInput;
use Drupal\country_access_filter\IpAccess;
use Drupal\country_access_filter\Service\storage\IpStorage;
use PHPUnit\Framework\TestCase;

/**
 * Provides shared setup and helpers for module tests.
 */
abstract class AuditTestBase extends TestCase {

  /**
   * The isolated Drupal database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $db;

  /**
   * The service container used by the test.
   */
  protected ContainerBuilder $container;

  /**
   * Creates a country service with in-memory Drupal configuration.
   *
   * @param array $values
   *   The configuration values for the country policy.
   *
   * @return \Drupal\country_access_filter\Service\CountryService
   *   The country access service configured for the test.
   */
  protected function configurePolicy(array $values = []): \Drupal\country_access_filter\Service\CountryService {
    $storage = new \Drupal\Core\Config\MemoryStorage();
    $storage->write('country_access_filter.settings', $values + [
      'enabled' => TRUE, 'countries' => 'UA', 'country_access_mode' => 'allow',
      'track_404' => TRUE, 'track_404_threshold' => 5, 'track_404_window' => 3600,
    ]);
    $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
    $typed = $this->createMock(\Drupal\Core\Config\TypedConfigManagerInterface::class);
    $factory = new \Drupal\Core\Config\ConfigFactory($storage, $dispatcher, $typed);
    $dispatcher->addSubscriber($factory);
    $countries = $this->createMock(\Drupal\Core\Locale\CountryManagerInterface::class);
    $countries->method('getList')->willReturn(['UA' => 'Ukraine', 'US' => 'United States', 'UG' => 'Uganda']);
    $ipStorage = new IpStorage($this->db);
    $settings = $this->settings($values);
    $this->container->set('country_access_filter.settings', $settings);
    $service = new \Drupal\country_access_filter\Service\CountryService(
      $ipStorage,
      new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([]))]),
      new \Drupal\Component\Serialization\Json(), $settings, $countries, new \Psr\Log\NullLogger(),
    );

    $this->container->set('config.storage', $storage);
    $this->container->set('config.factory', $factory);
    $this->container->set('config.typed', $typed);
    $this->container->set('country_manager', $countries);
    $this->container->set('country_access_filter.country_service', $service);
    $this->container->set('country_access_filter.ip_storage', $ipStorage);
    $this->container->set('http_client', $this->createMock(\GuzzleHttp\ClientInterface::class));
    $this->container->set('cache_tags.invalidator', $this->createMock(\Drupal\Core\Cache\CacheTagsInvalidatorInterface::class));

    return $service;
  }

  /**
   * Sets up the isolated test database and Drupal container.
   */
  protected function setUp(): void {
    parent::setUp();
    drupal_static_reset();
    new Settings([]);
    Database::addConnectionInfo('caf_audit', 'default', [
      'driver' => 'sqlite',
      'namespace' => 'Drupal\\sqlite\\Driver\\Database\\sqlite',
      'database' => ':memory:',
      'prefix' => '',
    ]);
    $this->db = Database::getConnection('default', 'caf_audit');
    $this->container = new ContainerBuilder();
    $this->container->set('database', $this->db);
    $this->container->set('messenger', $this->createMock(\Drupal\Core\Messenger\MessengerInterface::class));
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(fn($markup) => $markup->getUntranslatedString());
    $this->container->set('string_translation', $translation);
    \Drupal::setContainer($this->container);

    foreach (country_access_filter_schema() as $name => $spec) {
      $this->db->schema()->createTable($name, $spec);
    }
  }

  /**
   * Closes the isolated test database and resets the Drupal container.
   */
  protected function tearDown(): void {
    Database::removeConnection('caf_audit');
    \Drupal::unsetContainer();
    drupal_static_reset();
    parent::tearDown();
  }

  /**
   * Creates isolated instance settings.
   */
  protected function settings(array $values = []): \Drupal\country_access_filter\Service\Settings {
    $data = ['country_access_filter.settings' => $values + ['track_404' => TRUE] + \Drupal\country_access_filter\Service\Settings::DEFAULTS];
    $state = $this->createMock(\Drupal\Core\State\StateInterface::class);
    $state->method('get')->willReturnCallback(function ($key, $default = NULL) use (&$data) {
      return $data[$key] ?? $default;
    });
    $state->method('set')->willReturnCallback(function ($key, $value) use (&$data) {
      $data[$key] = $value;
    });
    $this->container->set('state', $state);

    return new \Drupal\country_access_filter\Service\Settings($state);
  }

  /**
   * Creates an IP value for a test.
   *
   * @param string $address
   *   The textual IP address.
   * @param \Drupal\country_access_filter\IpAccess $access
   *   The access decision for the IP address.
   * @param string $country
   *   The ISO 3166-1 alpha-2 country code.
   *
   * @return \Drupal\country_access_filter\DTO\Ip
   *   The constructed IP value.
   */
  protected function ip(string $address = '192.0.2.1', IpAccess $access = IpAccess::Allowed, string $country = 'UA'): Ip {
    return new Ip((new IpInput($address))->getStorableValue(), $access, $country);
  }

  /**
   * Loads the stored access decision for an IP address.
   *
   * @param string $address
   *   The textual IP address to load.
   *
   * @return bool
   *   TRUE when access is allowed, or FALSE otherwise.
   */
  protected function storedAccess(string $address = '192.0.2.1'): bool {
    return (new IpStorage($this->db))->load(new IpInput($address))->isAllowed();
  }

}
