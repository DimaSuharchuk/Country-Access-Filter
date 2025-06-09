<?php

namespace Drupal\country_access_filter\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Session\AccountInterface;
use Drupal\country_access_filter\Service\CountryAccessService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class Subscriber implements EventSubscriberInterface {

  protected ImmutableConfig $config;

  public function __construct(
    protected RequestStack $request,
    ConfigFactoryInterface $config_factory,
    protected AccountInterface $user,
    protected CountryAccessService $country_access,
  ) {
    $this->config = $config_factory->get('country_access_filter.settings');
  }

  public static function getSubscribedEvents(): array {
    $events[KernelEvents::REQUEST][] = ['onKernelRequest', 255];

    return $events;
  }

  public function onKernelRequest(RequestEvent $event): void {
    // Only handle the main request, not sub-requests.
    if (
      !$this->config->get('enabled')
      || !$event->isMainRequest()
      || $this->user->isAuthenticated()
    ) {
      return;
    }

    $ip = $this->request->getCurrentRequest()->getClientIp();

    if (!$ip || !$this->country_access->hasAccess($ip)) {
      $response = new Response();
      $response->setStatusCode(Response::HTTP_SERVICE_UNAVAILABLE);
      $event->setResponse($response);
    }
  }

}
