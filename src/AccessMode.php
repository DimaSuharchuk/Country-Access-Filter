<?php

namespace Drupal\country_access_filter;

/**
 * Defines how the selected countries affect access.
 */
enum AccessMode: string {

  /** Allow access only from the selected countries. */
  case ALLOW = 'allow';

  /** Deny access from the selected countries. */
  case DENY = 'deny';

}
