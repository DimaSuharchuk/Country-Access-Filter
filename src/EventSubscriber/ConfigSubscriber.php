<?php

namespace Drupal\country_access_filter\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\country_access_filter\Service\CountryService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps stored IP decisions in sync with country configuration saves/imports.
 */
class ConfigSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the country configuration event subscriber.
   *
   * @param \Drupal\country_access_filter\Service\CountryService $countryService
   *   The service that updates stored IP decisions.
   */
  public function __construct(protected CountryService $countryService) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => ['onConfigSave']];
  }

  /**
   * Updates saved IP decisions when module settings are saved or imported.
   *
   * @param \Drupal\Core\Config\ConfigCrudEvent $event
   *   The configuration save event.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();

    if ($config->getName() === 'country_access_filter.settings') {
      $this->countryService->synchronizeAccess($config);
    }
  }

}
