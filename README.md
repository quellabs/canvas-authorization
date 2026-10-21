# Canvas Authorization

Authentication support for Canvas applications. Password login and OpenID Connect
(OIDC) login can be installed together or separately. The generated controllers,
entities, and templates belong to the application and can be customized there.

## Installation

Run these commands from an application that has this package installed:

| Login offered | Commands |
| --- | --- |
| Password only | `php ./vendor/bin/sculpt install:auth` |
| Password + OIDC | `php ./vendor/bin/sculpt install:auth`, then `php ./vendor/bin/sculpt install:auth-oidc` |
| OIDC only, in a new app | `php ./vendor/bin/sculpt install:auth-oidc` |

OIDC is optional. `install:auth-oidc` detects whether `install:auth` has already
created a password controller: if so, it adds OIDC alongside password login;
otherwise, it installs OIDC-only login. It never replaces an existing password
controller or template. Installing OIDC leaves it disabled until you configure
the provider and set `enabled` to `true`.

The controllers own different URLs. With password + OIDC, the password
`AuthenticationController` handles `/login`, `/logout`, registration, and password
reset. `OidcAuthenticationController` handles `/auth/oidc/...` URLs, including
the provider callback. The login page links to `/auth/oidc/start`; the OIDC
callback eventually signs the user into the same local account session. The two
controllers do not register the same routes.

An OIDC-only app has no password `AuthenticationController`. Instead,
`OidcOnlyLoginController` supplies an OIDC-only `/login` page and `/logout`, while
`OidcAuthenticationController` still handles `/auth/oidc/...`. The installer
chooses the combined setup when a password controller already exists, so both
login controllers never claim the same `/login` and `/logout` routes.

Configure the issuer, client ID, and exact HTTPS callback URL in `config/oidc.php`.
Put the client secret in `config/oidc.local.php` and keep real secrets out of version
control. Register the full `redirect_uri` at your provider, generate and apply the
identity-table migration, and then set `enabled` to `true`. The installer prints the
commands and remaining deployment steps.

For an application with an existing, customized login template, add this link
manually to offer OIDC login:

```html
<a href="/auth/oidc/start">Sign in with your organization</a>
```

The installer does not replace an existing login template. See [OIDC.md](OIDC.md)
for configuration, first-login linking and registration, migration, provider
replacement, and upgrade instructions.

## Tests

```sh
php ./vendor/bin/phpunit
php ./vendor/bin/phpunit -c phpunit.integration.xml
```

The integration suite requires MySQL. Connection settings and defaults are in
[OIDC.md](OIDC.md#running-the-test-suites).
