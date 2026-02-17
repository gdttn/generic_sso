<?php

declare(strict_types=1);

namespace Drupal\generic_sso;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Redirect response that includes cookies.
 *
 * @see https://github.com/alterphp/components/blob/master/src/AlterPHP/Component/HttpFoundation/RedirectResponseWithCookie.php
 */
class RedirectResponseWithCookie extends RedirectResponse {

  /**
   * Creates a valid redirect response and pushes cookies with them.
   *
   * @param string $url
   *   The URL to redirect to.
   * @param int $status
   *   The status code (302 by default)
   * @param array $cookies
   *   An array of Cookie objects.
   */
  public function __construct($url, $status = 302, array $cookies = []) {

    parent::__construct($url, $status);
    foreach ($cookies as $cookie) {
      if (!$cookie instanceof Cookie) {
        throw new \InvalidArgumentException(sprintf('RedirectResponseWithCookie: >=1 array member is not a valid Cookie object.'));
      }
      $this->headers->setCookie($cookie);
    }
  }

}
