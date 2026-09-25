<?php

namespace Drupal\country_access_filter\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountInterface;
use Drupal\country_access_filter\DTO\IpInput;
use Drupal\country_access_filter\Service\CountryService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Restricts main requests according to the visitor's country.
 */
class Subscriber implements EventSubscriberInterface {

  protected ImmutableConfig $config;

  /**
   * Constructs the request event subscriber.
   *
   * @param \Symfony\Component\HttpFoundation\RequestStack $request
   *   The active request stack.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The current user account.
   * @param \Drupal\country_access_filter\Service\CountryService $countryService
   *   The service that determines IP access.
   */
  public function __construct(
    protected RequestStack $request,
    ConfigFactoryInterface $config_factory,
    protected AccountInterface $user,
    protected CountryService $countryService,
  ) {
    $this->config = $config_factory->get('country_access_filter.settings');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[KernelEvents::REQUEST][] = ['onKernelRequest', 255];

    return $events;
  }

  /**
   * Blocks an unauthenticated visitor if their IP is not allowed.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event to inspect.
   */
  public function onKernelRequest(RequestEvent $event): void {
    // Only handle the main request, not sub-requests.
    if (
      !$this->config->get('enabled')
      || !$event->isMainRequest()
      || $this->user->isAuthenticated()
    ) {
      return;
    }

    $ip = new IpInput($this->request->getCurrentRequest()->getClientIp());

    if (!$ip->isValid() || !$this->countryService->hasAccess($ip)) {
      $response = new Response();
      $response->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
      $event->setResponse($response);
    }
  }

}
