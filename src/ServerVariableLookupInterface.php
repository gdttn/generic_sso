<?php

declare(strict_types=1);

namespace Drupal\generic_sso;

/**
 * Helper function to make dummy data available in functional tests.
 */
interface ServerVariableLookupInterface {

  /**
   * Get authentication name from override or server variable.
   *
   * @param string|null $variable
   *   SSO variable to check, such as REMOTE_USER.
   *
   * @return string|null
   *   Authentication name.
   */
  public function getAuthenticationNameFromServer(?string $variable): ?string;

}
