# Canvas Authorization

Authentication support for Canvas applications. The `install:auth` command generates
password login and registration; `install:auth-oidc` adds opt-in OpenID Connect (OIDC)
Authorization Code login. The generated controllers, entities, and templates belong to
the application and can be customized there.

## Install OIDC

Run these commands from an application that has this package installed. For password
and OIDC login together:

```sh
php ./vendor/bin/sculpt install:auth
php ./vendor/bin/sculpt install:auth-oidc
```

If the application already has password login from `install:auth`, run only the second
command. For a new application that should expose only OIDC login, run:

```sh
php ./vendor/bin/sculpt install:auth-oidc --mode=oidc_only
```

The OIDC-only installer refuses an application with an existing password
`AuthenticationController`, because both controllers would register `/login` and
`/logout`.

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
