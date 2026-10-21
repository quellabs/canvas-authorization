# OpenID Connect login

Browser-based OpenID Connect (OIDC) Authorization Code login, as an opt-in addition to this
package's password login. One statically configured issuer and client per application.

## Installing

**Combined login (password + OIDC), fresh app:**

```
php ./vendor/bin/sculpt install:auth
php ./vendor/bin/sculpt install:auth-oidc
```

`install:auth-oidc` adds the OIDC routes and completion pages alongside the password
controller `install:auth` already generated; it never overwrites `AuthenticationController`,
`UserEntity`, or your edited templates.

**OIDC-only login, fresh app:**

```
php ./vendor/bin/sculpt install:auth-oidc --mode=oidc_only
```

Skips `install:auth` entirely. Also generates the shared `UserEntity`,
`AuthenticatedController`, and `AuthErrorHandler`, plus an OIDC-only `/login` and `/logout`.
Refuses to run if `src/Controllers/AuthenticationController.php` already exists, since that
controller and the OIDC-only login page would both claim `/login` and `/logout`. To move an
existing combined-mode app to OIDC-only: keep combined mode, make sure every account that
should keep working has a linked identity (ask users to link via `/auth/oidc/link/start`, or
link them yourself — see **Administrative provisioning** below), then remove the password
routes/controller and set `login_mode` to `oidc_only` in `config/oidc.php`.

**Existing application already on `install:auth`:**

Just run `install:auth-oidc`. Your `AuthenticationController`, `UserEntity`, and templates are
untouched. The login template `install:auth` generates gets an additional "Sign in with your
organization" button automatically on the *next* `install:auth` run; for a template you've
already edited, add it manually:

```html
<a href="/auth/oidc/start">Sign in with your organization</a>
```

## Provider registration

Register this application with your provider and give it this exact callback URL — providers
reject anything that doesn't match exactly:

```
https://your-domain.example/auth/oidc/callback
```

The provider must support OIDC discovery, the authorization code flow, PKCE `S256`, and signed
ID tokens using `RS256`.

## Configuration

`config/oidc.php` (safe to commit):

| Key | Meaning |
|---|---|
| `enabled` | Leave `false` until every step below is done. |
| `issuer` | The provider's issuer URL, no trailing slash. Must be HTTPS. |
| `client_id` | The client ID the provider gave you. |
| `redirect_uri` | Must exactly match what you registered. HTTPS except when `config/app.php`'s `debug_mode` is `true`. |
| `login_mode` | `password_and_oidc` or `oidc_only`. |
| `allowed_signing_algorithms` | `['RS256']` — the only algorithm this release supports. |
| `allow_private_network_endpoints` | `true` only for an operator-controlled internal provider whose endpoints resolve to a private network address. Leave `false` for any public provider. |

`config/oidc.local.php` (**never commit a real value**):

| Key | Meaning |
|---|---|
| `client_secret` | The secret the provider gave you. |

After filling these in and running the migration (below), set `enabled` to `true`.

## Migration

```
php ./vendor/bin/sculpt make:migrations
php ./vendor/bin/sculpt quel:migrate
```

This only adds the `oidc_identities` table. It never touches existing `users` rows or
passwords.

## First-login linking and registration

The package never creates a local account or links an identity just because a provider
callback succeeded, and it never links by email, username, or display name — only by the
exact `(issuer, subject)` pair the provider's ID token proved.

- **An identity that's already linked**: logs the user in directly.
- **An unlinked identity, combined mode**: lands on a completion page offering to sign in to
  an existing account (verifies the local password, then links) or register a new one (creates
  the account and the link together, in one transaction — a conflict rolls both back).
- **An unlinked identity, OIDC-only mode**: the completion page asks for explicit confirmation
  before creating a new local profile with an opaque username and an unguessable random
  password hash that's never shown or accepted as a credential. That account is usable only
  through its linked OIDC identity until you build a separate verified password-setup flow.
- **Linking a provider identity to the account you're already signed into**: `POST
  /auth/oidc/link/start` with the local password in the request body. Requires a logged-in,
  eligible session and that exact password — a provider's `amr=pwd` claim is never accepted as
  proof of it.

## Administrative provisioning

Before an OIDC-only cutover, or to link known pre-existing accounts without going through the
browser flow, use `Quellabs\CanvasAuthorization\Identity\OidcIdentityRepositoryInterface`
directly from a command or script:

```php
$identityRepository->link($issuer, $subject, $localUserId);
```

It throws `OidcIdentityConflictException` if that identity is already linked to anyone, or if
`$localUserId` already has a link for that issuer — it never reassigns an existing link.

## Application-owned stubs

`install:auth-oidc` copies these into your application; it never overwrites them on a later
run, so edit them freely:

- `src/Controllers/OidcAuthenticationController.php` — the six OIDC routes.
- `src/Services/OidcLocalAccountService.php` — the only place that reads or writes
  `UserEntity` for the OIDC flow. Adapt this if your user entity looks different; the
  controller never touches `UserEntity` directly.
- `src/Entities/OidcIdentityEntity.php` — the `(issuer, subject)` → local user link.
- `templates/oidc_complete.*`, `templates/oidc_error.*` (and, for `oidc_only`,
  `src/Controllers/OidcOnlyLoginController.php` and `templates/oidc_only_login.*`).

Everything else — `OidcClientInterface`, `OidcConfig`, `OidcIdentityRepositoryInterface`,
`OidcFlowStore`, `OidcLoginSession`, `ReturnPathValidator` — stays package-owned and is fixed
by `composer update`. An application that needs a different identity-storage backend can
register its own DI provider for `OidcIdentityRepositoryInterface`.

## Error handling and logging

Every failure — a provider error, a bad or expired state, an invalid ID token, an identity
conflict — renders the same generic page and logs only a correlation ID and a sanitized
reason. The authorization code, any token, `state`, `nonce`, and the pending identity are never
written to a log or reflected into a browser URL after the callback.

## Operational limits

- A pending authorization flow (the `state`/`nonce`/PKCE verifier) expires after 10 minutes and
  is consumed exactly once — a replayed callback finds nothing, whether or not the original
  attempt succeeded.
- At most 5 pending flows per session; starting a 6th evicts the oldest.
- A pending completion record (verified-but-unlinked identity, waiting on the completion page)
  expires after 10 minutes.
- A link-intent's local-password confirmation must be no more than 5 minutes old by the time
  the callback runs.
- The existing-account completion POST is rate-limited (5 attempts / 5 minutes / IP).

## Provider replacement

Changing the configured issuer or client is a configuration change, not a migration: rows for
the previous issuer are never deleted or touched automatically, and the new issuer's identities
are never populated from them by email or any other claim. In combined mode, users link the
new issuer the normal way, through local sign-in. For an `oidc_only` application, provision
known accounts ahead of the cutover (see **Administrative provisioning**). Restoring the
original issuer and client makes its preserved links resolve again immediately. A client
change at the same issuer can also change a provider's pairwise subject identifiers, which
needs the same re-linking. If the replacement is a security response (e.g. the old client
secret leaked), also invalidate existing application sessions through your session store as
part of the cutover — this package does not do that for you.

## Upgrading an existing `install:auth` application

Nothing about `install:auth-oidc` changes `AuthenticationController`'s behavior for an
application that already has one — it only adds new files and new routes. The one exception:
`install:auth` itself now injects `OidcConfig` into the generated `AuthenticationController`
and conditionally renders an OIDC button, but that only affects applications that run
`install:auth` *after* upgrading this package; an already-generated controller is never
rewritten. Copied stub files never update on `composer update` — re-run the relevant install
command's specific file, or apply the diff by hand, if you want a later release's version of a
stub you've already generated.
