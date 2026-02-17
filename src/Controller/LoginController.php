<?php

declare(strict_types=1);

namespace Drupal\generic_sso\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\generic_sso\RedirectResponseWithCookie;
use Drupal\generic_sso\ServerVariableLookupInterface;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Login controller.
 */
final class LoginController extends ControllerBase {

  /**
   * Config.
   *
   * @var \Drupal\Core\Config\Config|\Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

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
   * Time.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Lookup service.
   *
   * @var \Drupal\generic_sso\ServerVariableLookupInterface
   */
  protected $serverVariableLookup;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructor.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The logging interface.
   * @param \Drupal\Core\Config\ConfigFactory $configFactory
   *   Factory for configuration.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   Time.
   * @param \Drupal\generic_sso\ServerVariableLookupInterface $server_variable_lookup
   *   Variable used on the server for identified user, often REMOTE_USER.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    LoggerInterface $logger,
    ConfigFactory $configFactory,
    AccountInterface $account,
    TimeInterface $time,
    ServerVariableLookupInterface $server_variable_lookup,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->logger = $logger;
    $this->config = $configFactory->get('generic_sso.settings');
    $this->account = $account;
    $this->time = $time;
    $this->serverVariableLookup = $server_variable_lookup;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('logger.channel.generic_sso'),
      $container->get('config.factory'),
      $container->get('current_user'),
      $container->get('datetime.time'),
      $container->get('generic_sso.server_variable'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Login.
   *
   * A proxy function for the actual authentication routine. This assumes that
   * any authentication from the underlying web server is good enough, and only
   * checks that there are values in place for the user name. In the case that
   * there are no credentials set by the underlying web server, the user is
   * redirected to the normal user login form.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current Symfony HTTP Request.
   *
   * @return \Drupal\generic_sso\RedirectResponseWithCookie
   *   Redirect response.
   */
  public function login(Request $request): RedirectResponseWithCookie {
    $this->logger->debug('Beginning SSO login.');

    $remote_user = $this->serverVariableLookup
      ->getAuthenticationNameFromServer($this->config->get('ssoVariable'));
    $realm = NULL;

    if ($remote_user && $this->config->get('ssoSplitUserRealm')) {
      [$remote_user, $realm] = $this->splitUserNameRealm($remote_user);
    }

    $this->logger->debug('SSO raw result is username=@remote_user, (realm=@realm).', [
      '@remote_user' => $remote_user,
      '@realm' => $realm,
    ]);

    if ($remote_user) {
      $this->logger->debug('User found, logging in.');
      $this->loginRemoteUser($remote_user, $realm);

      $destination = $request->query->get('destination');
      // In subdirectories we need to remove the base path.
      if ($request->getBasePath()) {
        $base = str_replace('/', '\/', $request->getBasePath());
        $destination = preg_replace("/^$base/", "", $destination);
      }
      $finalDestination = $destination ? Url::fromUserInput($destination) : Url::fromRoute('<front>');
    }
    else {
      $this->logger->debug('User missing.');
      $this->remoteUserMissing();
      $finalDestination = Url::fromRoute('user.login');
    }

    // Removes our automated SSO semaphore, should it have been set.
    if (method_exists(Cookie::class, 'create')) {
      $cookies[] = Cookie::create('sso_login_running', '', $this->time->getRequestTime() - 3600, base_path());
    }
    else {
      $cookies[] = new Cookie('sso_login_running', '', $this->time->getRequestTime() - 3600, base_path());
    }

    return new RedirectResponseWithCookie($finalDestination->toString(), 302, $cookies);
  }

  /**
   * Access callback.
   */
  public function access() {
    if ($this->account->isAnonymous()) {
      return AccessResult::allowed();
    }

    return AccessResult::forbidden();
  }

  /**
   * Perform the actual logging in of the user.
   *
   * @param string $remote_user
   *   Remote user name.
   * @param string|null $realm
   *   Realm information.
   */
  private function loginRemoteUser(string $remote_user, ?string $realm): void {
    $this->logger->debug('Continuing SSO login with username=@remote_user, (realm=@realm).', [
      '@remote_user' => $remote_user,
      '@realm' => $realm,
    ]);

    $user = $this->validateUser($remote_user);

    if ($user && !$user->isAnonymous()) {
      $this->loginUserSetFinalize($user);
    }
    else {
      $this->loginUserNotSetFinalize();
    }
  }

  /**
   * Validate user by looking up the Drupal account.
   *
   * @param string $remote_user
   *   Remote user name.
   *
   * @return \Drupal\user\UserInterface|false
   *   Returns the user if available or FALSE when the authentication is not
   *   successful.
   */
  private function validateUser(string $remote_user) {
    $this->logger->debug('Starting validation for SSO user.');

    $username = Html::escape($remote_user);
    $account = user_load_by_name($username);

    if ($account instanceof UserInterface) {
      $this->logger->debug('Remote user has local uid @uid', [
        '@uid' => $account->id(),
      ]);
      return $account;
    }

    // Auto-create user if configured.
    if ($this->config->get('autoCreateUser')) {
      $this->logger->info('Auto-creating Drupal account for SSO user @user.', [
        '@user' => $username,
      ]);

      /** @var \Drupal\user\UserInterface $new_account */
      $new_account = $this->entityTypeManager->getStorage('user')->create([
        'name' => $username,
        'status' => 1,
      ]);
      $new_account->enforceIsNew();
      $new_account->save();

      return $new_account;
    }

    $this->logger->debug('Remote user is not valid.');
    return FALSE;
  }

  /**
   * Returns the relevant lifetime from configuration.
   *
   * @return int
   *   Either 0 for session or a past timestamp.
   */
  private function getCookieLifeTime(): int {
    if ($this->config->get('cookieExpire')) {
      // Length of session.
      $cookie_lifetime = 0;
    }
    else {
      // A value quickly in the past.
      $cookie_lifetime = $this->time->getRequestTime() - 3600;
    }
    return $cookie_lifetime;
  }

  /**
   * Finalize login with user not set.
   */
  private function loginUserNotSetFinalize(): void {
    $this->logger->debug('User not found, SSO aborted.');

    setcookie('sso_stop', 'sso_stop', $this->getCookieLifeTime(), base_path(), '');

    $this->messenger()
      ->addError($this->t('Sorry, a matching account was not found. You can log in with non-SSO credentials (if permitted) on the %user_login_form.', [
        '%user_login_form' => Link::fromTextAndUrl('login form', Url::fromRoute('user.login'))->toString(),
      ]));
    $this->logger->debug('User not found, redirecting to front page.');
  }

  /**
   * Finalize login with user set.
   *
   * @param \Drupal\user\UserInterface $account
   *   Valid user account.
   */
  private function loginUserSetFinalize(UserInterface $account): void {
    $this->logger->debug('Success with SSO login.');
    user_login_finalize($account);
    if ($this->config->get('enableLoginConfirmationMessage')) {
      $this->messenger()
        ->addStatus($this->t('You have been successfully authenticated'));
    }
    $this->logger->debug('Login successful, redirecting to front page.');
  }

  /**
   * Handle missing remote user.
   */
  private function remoteUserMissing(): void {
    $this->logger->debug('$_SERVER[\'@variable\'] not found', [
      '@variable' => $this->config->get('ssoVariable'),
    ]);
    setcookie('sso_stop', 'sso_stop', $this->getCookieLifeTime(), base_path(), '');
    $this->messenger()
      ->addError($this->t('You were not authenticated by the server. You may log in with your credentials below.'));
  }

  /**
   * Split username from realm.
   *
   * @param string $remote_user
   *   String to split at '@'.
   *
   * @return array
   *   Remote user and realm string separated.
   */
  protected function splitUserNameRealm(string $remote_user): array {
    $realm = NULL;
    $domainMatch = preg_match('/^([A-Za-z0-9_\-\.]+)@([A-Za-z0-9_\-.]+)$/', $remote_user, $matches);
    if ($remote_user && $domainMatch) {
      $remote_user = $matches[1];
      // This can be used later if realms is ever supported properly.
      $realm = $matches[2];
    }
    return [$remote_user, $realm];
  }

}
