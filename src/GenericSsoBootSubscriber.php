<?php
declare(strict_types=1);

namespace Drupal\generic_sso;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Routing\RedirectDestinationInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Provides the automated single sign-on provider.
 */
class GenericSsoBootSubscriber implements EventSubscriberInterface {

  /**
   * SSO config.
   *
   * @var \Drupal\Core\Config\Config|\Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Front page.
   *
   * @var array|mixed|null
   */
  protected $frontpage;

  /**
   * Current path.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPath;

  /**
   * Logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The redirect destination service.
   *
   * @var \Drupal\Core\Routing\RedirectDestinationInterface
   */
  protected $redirectDestination;

  /**
   * Request Stack.
   *
   * @var \Symfony\Component\HttpFoundation\Request|null
   */
  protected $currentRequest;

  /**
   * Default paths to exclude.
   * TOOD: work out why /admin/config/search/clean-urls/check was here
   *
   * @var string[]
   */
  protected const DEFAULT_EXCLUDE_PATHS = [
    '/user/login/sso',
    '/user/login',
    '/user/logout',
    '/user',
  ];

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactory $configFactory
   *   Factory for configuration.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   Request stack.
   * @param \Drupal\Core\Path\CurrentPathStack $currentPath
   *   Adds the current path.
   * @param \Psr\Log\LoggerInterface $logger
   *   Logger.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   * @param \Drupal\Core\Routing\RedirectDestinationInterface $redirect_destination
   *   Redirect destination.
   */
  public function __construct(
    ConfigFactory $configFactory,
    RequestStack $request_stack,
    CurrentPathStack $currentPath,
    LoggerInterface $logger,
    AccountInterface $account,
    RedirectDestinationInterface $redirect_destination,
  ) {
    $this->config = $configFactory->get('generic_sso.settings');
    $this->frontpage = $configFactory->get('system.site')->get('page.front');
    $this->currentRequest = $request_stack->getCurrentRequest();
    $this->currentPath = $currentPath;
    $this->logger = $logger;
    $this->account = $account;
    $this->redirectDestination = $redirect_destination;
  }

  /**
   * Determine if we should attempt SSO.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   Event to act upon.
   */
  public function checkSsoLoad(RequestEvent $event): void {
    if ((PHP_SAPI === 'cli') || $this->account->isAuthenticated()) {
      $this->logger->debug('CLI or logged in user, no SSO.');
      return;
    }

    if (!$this->config->get('seamlessLogin')) {
      $this->logger->debug('Automated SSO not active.');
      return;
    }

    if ($this->checkExcludePath()) {
      $this->logger->debug('Excluded path');
      return;
    }

    if ($this->currentRequest->cookies->get('sso_login_running', FALSE)) {
      $this->logger->debug('SSO login running cookie present, aborting.');
      exit(0);
    }

    if ($this->currentRequest->cookies->get('sso_stop', FALSE)) {
      $this->logger->debug('Anonymous user with cookie to not continue SSO login');
      return;
    }

    $this->logger->debug('Transferring to login controller');
    $this->transferSsoLoginController();
    exit(0);
  }

  /**
   * {@inheritdoc}
   * Set priority one higher than ldap_sso, for transitional purposes
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => ['checkSsoLoad', 31]];
  }

  /**
   * Continue booting assuming we are doing SSO.
   */
  protected function transferSsoLoginController(): void {
    // This is set to destination() since the request uri is usually
    // system/40x already.
    $original_path = $this->redirectDestination->get();
    $pathWithDestination = Url::fromRoute('generic_sso.login_controller')->toString() . '?destination=' . $original_path;
    if (method_exists(Cookie::class, 'create')) {
      $cookie = Cookie::create('sso_login_running', 'true', 0, base_path());
    }
    else {
      $cookie = new Cookie('sso_login_running', 'true', 0, base_path());
    }
    $response = new RedirectResponseWithCookie($pathWithDestination, 302, [$cookie]);
    $response->send();
  }

  /**
   * Check to exclude paths from SSO.
   *
   * @return bool
   *   Path excluded or not.
   */
  protected function checkExcludePath(): bool {
    if ($_SERVER['PHP_SELF'] === $this->currentRequest->getBasePath() . '/index.php') {
      // Remove base_path from current path to match subdirectories, too.
      $path = str_replace($this->currentRequest->getBasePath(), '', $this->currentPath->getPath());
    }
    else {
      // cron.php, etc.
      $path = ltrim($_SERVER['PHP_SELF'], '/');
    }

    if (\in_array($path, self::DEFAULT_EXCLUDE_PATHS, TRUE)) {
      return TRUE;
    }

    if (\is_array($this->config->get('ssoExcludedHosts'))) {
      $host = $_SERVER['SERVER_NAME'];
      foreach ($this->config->get('ssoExcludedHosts') as $host_to_check) {
        if ($host_to_check === $host) {
          return TRUE;
        }
      }
    }

    foreach ($this->config->get('ssoExcludedPaths') as $path_to_exclude) {
      if (
        mb_strtolower($path) === mb_strtolower($path_to_exclude) ||
        ($path_to_exclude === '<front>' && mb_strtolower($this->frontpage) === mb_strtolower($path))
      ) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
