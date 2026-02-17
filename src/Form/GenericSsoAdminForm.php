<?php

declare(strict_types=1);

namespace Drupal\generic_sso\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides the configuration form for Generic SSO.
 */
final class GenericSsoAdminForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'generic_sso_admin_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['generic_sso.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('generic_sso.settings');

    $form['information'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<h2>Single sign-on (SSO)</h2><p>Single sign-on enables users of this site to be authenticated by visiting the URL /user/login/sso, or automatically if selected below. The web server must be configured to set the REMOTE_USER (or equivalent) server variable.</p>'),
    ];

    $form['seamlessLogin'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Turn on automated single sign-on'),
      '#description' => $this->t('This requires that you have authentication turned on in your web server for at least the path /user/login/sso (enabling it for the entire host works too).'),
      '#default_value' => $config->get('seamlessLogin'),
    ];

    $form['ssoSplitUserRealm'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Split user name and realm'),
      '#description' => $this->t('If your usernames are presented as user@realm, you need to enable this to split the two.  The realm is logged and may be used in future, but this is not implemented.'),
      '#default_value' => $config->get('ssoSplitUserRealm'),
    ];

    $form['cookieExpire'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Invalidate SSO cookie immediately'),
      '#description' => $this->t("Turn this on if you want to make it possible for users to log right back in after logging out with automated single sign-on.<br>This is off by default and set to a session cookie so opening a browser clears the setting."),
      '#default_value' => $config->get('cookieExpire'),
    ];

    $form['ssoVariable'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Server variable containing the user'),
      '#description' => $this->t('This is usually REMOTE_USER or REDIRECT_REMOTE_USER.'),
      '#default_value' => $config->get('ssoVariable'),
    ];

    $form['ssoExcludedPaths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('SSO Excluded Paths'),
      '#description' => $this->t("Common paths to exclude from SSO are for example cron.php.<br>This module already excludes some system paths, such as /user/login.<br>Specify pages by using their paths. Enter one path per line. The '*' character is a wildcard.<br>Example paths are %blog for the blog page and %blog-wildcard for all pages below it. %front is the front page.  Setting exclusions does not prevent login on protected pages, it just means users will see a 403 first.",
        ['%blog' => 'blog', '%blog-wildcard' => 'blog/*', '%front' => '<front>']),
      '#default_value' => is_array($config->get('ssoExcludedPaths')) ? implode("\n", $config->get('ssoExcludedPaths')) : '',
    ];

    $form['ssoExcludedHosts'] = [
      '#type' => 'textarea',
      '#title' => $this->t('SSO Excluded Hosts'),
      '#description' => $this->t('If your site is accessible via multiple hostnames, you may only want the SSO module to authenticate against some of them.<br>Enter one host per line.'),
      '#default_value' => is_array($config->get('ssoExcludedHosts')) ? implode("\n", $config->get('ssoExcludedHosts')) : '',
    ];

    $form['autoCreateUser'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically create Drupal accounts'),
      '#description' => $this->t('When enabled, a new Drupal user account will be created automatically if the REMOTE_USER does not match an existing account.'),
      '#default_value' => $config->get('autoCreateUser'),
    ];

    $form['login'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Login customization'),
    ];

    $form['login']['redirectOnLogout'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Redirect users on logout'),
      '#description' => $this->t('Recommended to be set for most sites to a non-SSO path. Can cause issues with immediate cookie invalidation and automated SSO.'),
      '#default_value' => $config->get('redirectOnLogout'),
    ];

    $form['login']['logoutRedirectPath'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Logout redirect path'),
      '#description' => $this->t('An internal Drupal path that users will be redirected to on logout'),
      '#default_value' => $config->get('logoutRedirectPath'),
      '#required' => FALSE,
      '#states' => [
        'visible' => [
          'input[name="redirectOnLogout"]' => ['checked' => TRUE],
        ],
        'required' => [
          'input[name="redirectOnLogout"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['login']['enableLoginConfirmationMessage'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show a confirmation message on successful login'),
      '#default_value' => $config->get('enableLoginConfirmationMessage'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => 'Save',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('redirectOnLogout')) {
      if ($form_state->getValue('logoutRedirectPath') === '') {
        $form_state->setErrorByName('logoutRedirectPath', $this->t('Redirect logout path cannot be blank'));
      }

      try {
        Url::fromUserInput($form_state->getValue('logoutRedirectPath'));
      }
      catch (\InvalidArgumentException $ex) {
        $form_state->setErrorByName('logoutRedirectPath', $this->t('The path you entered for Redirect logout path is not a valid internal path, internal paths should start with: /, ? or #'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $this->config('generic_sso.settings')
      ->set('ssoExcludedPaths', $this->linesToArray($values['ssoExcludedPaths']))
      ->set('ssoExcludedHosts', $this->linesToArray($values['ssoExcludedHosts']))
      ->set('seamlessLogin', $values['seamlessLogin'])
      ->set('ssoSplitUserRealm', $values['ssoSplitUserRealm'])
      ->set('cookieExpire', $values['cookieExpire'])
      ->set('ssoVariable', $values['ssoVariable'])
      ->set('redirectOnLogout', $values['redirectOnLogout'])
      ->set('logoutRedirectPath', $values['logoutRedirectPath'])
      ->set('enableLoginConfirmationMessage', $values['enableLoginConfirmationMessage'])
      ->set('autoCreateUser', $values['autoCreateUser'])
      ->save();
  }

  /**
   * Converts a multiline text value to an array of trimmed, non-empty lines.
   *
   * @param string $value
   *   The multiline string.
   *
   * @return array
   *   Array of non-empty trimmed lines.
   */
  private function linesToArray(string $value): array {
    return array_values(array_filter(array_map('trim', explode("\n", $value)), 'strlen'));
  }

}
