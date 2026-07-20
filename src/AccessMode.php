<?php

namespace Drupal\country_access_filter;

enum AccessMode: string {

  case ALLOW = 'allow';
  case DENY = 'deny';

}
