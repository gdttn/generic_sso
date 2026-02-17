# Generic SSO

Authenticates Drupal users via the `REMOTE_USER` server variable (or
equivalent) set by the web server. No LDAP or other external authentication
backend required.

## Requirements

- Drupal 10.5+ (expected to work on 10, 11 and 12 at least).
- Web server configured to set `REMOTE_USER` (e.g. via mod_auth_kerb,
  mod_auth_sspi, mod_auth_openidc, or similar auth module)

## Configuration

On your web server, make sure the _at least_ the path /user/login/sso is
protected by your chosen module.  It is OK to protect all pages, but if you'd
like Drupal to control the experience you can set just /user/login/sso and use
the exclusion lists.

Within Drupal Admin (`/admin/config/people/generic-sso`) make sure that the
_Server variable_ matches whichever `$_SERVER` variable is sent (default is
`REMOTE_USER`, but this can vary by configuration).  If your module sends
user@domain or user@realm, you will need to enable realm stripping options.

## Origin

This code was derived from [ldap_sso](https://www.drupal.org/project/ldap_sso)
(with all LDAP dependencies removed and simplified where possible).

## Transition from ldap_sso

* You can import the relevant config variables from the old module using [...]
  
* If the new form doesn't appear in the appropriate admin menu, you can navigate to
  it via the Extend menu or by URL.  It might be useful to try running
     `\Drupal::service('plugin.manager.menu.link')->rebuild()`
  _after_ removing ldap_sso in `drush`, for changes to take effect.
