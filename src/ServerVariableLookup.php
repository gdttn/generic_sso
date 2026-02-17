<?php

declare(strict_types=1);

namespace Drupal\generic_sso;

/**
 * Helper function to make dummy data available in functional tests.
 */
class ServerVariableLookup implements ServerVariableLookupInterface {

  /**
   * {@inheritdoc}
   */
  public function getAuthenticationNameFromServer(?string $variable): ?string {
    $remote_user = NULL;

    if (isset($_SERVER[$variable])) {
      $remote_user = $_SERVER[$variable];
    }

    return $remote_user;
  }

}
