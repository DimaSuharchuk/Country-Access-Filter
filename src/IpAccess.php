<?php

namespace Drupal\country_access_filter;

/**
 * Defines the access decision for an IP address.
 */
enum IpAccess {

  /** The IP address is allowed. */
  case Allowed;

  /** The IP address is denied. */
  case Denied;

  /** The access decision could not be determined. */
  case Error;

}
