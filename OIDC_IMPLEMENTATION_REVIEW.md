# OIDC implementation review

## Test coverage follow-up — 2026-10-21

Added regression tests for the gaps identified in the earlier passes:

- `OidcClientTest` rejects a future `auth_time` in a signed ID token.
- `MakeAuthOidcCommandTest` runs the installer in a temporary application for both login modes and all five supported template engines. It checks generated mode, resolved view names and files, OIDC-only files, and preservation of an existing controller when overwrite is declined.
- `OidcDisabledRoutesTest` invokes all six OIDC controller actions with the feature disabled and checks their 404 responses.
- `OidcCallbackTest` checks that a linked identity establishes the local session, clears prior eligibility, redirects to the requested local path, and consumes state. An unlinked identity creates a pending completion without authenticating or creating an account.
- The MySQL integration test added in the third pass covers duplicate-username handling and recovery after rollback.

Current verification: 124 unit tests (313 assertions), 9 MySQL integration tests (14 assertions), and PHPStan all pass; `git diff --check` reports no whitespace errors. The controller tests call actions directly, so they do not verify framework routing or AOP interception. A deployed browser flow with a real OIDC provider, generated migrations, and reverse-proxy cookie behavior remains unverified.

## Third pass — 2026-10-21

The concurrent-registration transaction finding from the second pass is resolved in commit `53f8f77`. `OidcLocalAccountService` now converts a failed user insert into `UserCreationException` and detaches the failed entity; `OidcAuthenticationController` catches that exception, rolls back the transaction, and shows a generic failure. Both installation modes supply the exception class. I added a MySQL integration test that forces a duplicate username during the insert and verifies that rollback leaves the entity manager usable for a later insert.

Current checks pass: 104 unit tests (175 assertions), 9 MySQL integration tests (14 assertions), PHPStan, PHP syntax for the new test, and `git diff --check`. I found no new confirmed defect in the changed code. The controller's complete browser route and installer are still not covered by end-to-end tests; the real-provider and deployment checks in Stage 5 of the plan remain unverified. The sections below record earlier findings and their resolution history.

## CSRF follow-up — 2026-10-21

Canvas provides `CsrfProtectionAspect`, but aspects are applied to specific controller actions. The generated OIDC-only
logout action had no interception annotation. It now uses
`@InterceptWith(CsrfProtectionAspect::class, throwOnFailure=true)`. New tests resolve the action's aspect through
Canvas's `AspectResolver` and check that a POST without a token is rejected while a POST with a valid session token is
accepted. The full unit suite passes (104 tests, 175 assertions), and PHPStan passes. The concurrent-registration
transaction finding below remains open.

## Second pass — 2026-10-21

The five findings from the first pass below have been addressed in the current working tree. `MakeAuthOidcCommand` now
replaces the template and login-mode tokens; a focused token-substitution check produced the expected output for both
modes. The ID-token validator rejects a far-future `auth_time`, all six OIDC controller actions check `enabled`, and
OIDC-only logout is POST-only. PHPStan's prior finding is also resolved.

The second pass found two issues in the affected flow:

1. **OIDC-only logout has no CSRF protection.** The generated logout route now accepts only POST, but
   `OidcOnlyLoginController::logout()` has no `CsrfProtectionAspect` and no token check. The plan requires CSRF
   protection for local POST actions. A cross-site form POST can therefore trigger logout when the application sends its
   session cookie with that request. See `stubs/Controllers/OidcOnlyLoginController.php:42`.

2. **A concurrent username claim can leave registration's transaction open.** `completeWithRegistration()` checks
   `usernameExists()` before beginning a transaction, then flushes the new user inside the transaction. `UserEntity` has
   a database unique index on `username`. If another request claims the username after the pre-check, the user flush
   throws a database exception. The controller catches only `OidcIdentityConflictException`, so it does not roll back
   this exception or return the plan's generic conflict response. See
   `stubs/Controllers/OidcAuthenticationController.php:321`, `:343`, `:348`, and `:356`, and
   `stubs/Entities/UserEntity.php:16`.

The changed behavior still has no dedicated regression tests: there are no installer or generated-controller tests, and
`OidcClientTest` does not test future `auth_time`. The first-pass findings are retained below as history; they no longer
describe the current working tree.

Second-pass verification: `php ./vendor/bin/phpunit` passed (102 tests, 170 assertions); the MySQL integration suite
passed (8 tests, 11 assertions); PHPStan and `composer validate --no-check-publish` passed; all PHP stubs passed
`php -l`. A focused reflection check confirmed both generated login modes and absence of unresolved template tokens, and
a focused claim-validation check rejected an `auth_time` one hour in the future. No real-provider or browser-route
end-to-end run was performed.

## First pass — 2026-10-21 (resolved)

Reviewed against `OIDC_IMPLEMENTATION_PLAN.md` on 2026-10-21. The implementation is not yet complete: the existing
automated suites pass, but the generated application flow has defects that those suites do not exercise.

## Findings

1. **Generated OIDC views cannot be resolved.** `MakeAuthOidcCommand` inherits `StubCommand::getTokens()`, which
   replaces only `{{ namespace }}` and `{{ date }}`. Unlike `MakeAuthCommand`, it does not replace `{{ template_ext }}`.
   The generated `OidcAuthenticationController` and `OidcOnlyLoginController` therefore contain template names such as
   `oidc_complete.{{ template_ext }}` and `oidc_only_login.{{ template_ext }}`. Rendering the completion, error, or
   OIDC-only login page will look for a nonexistent template. See `src/MakeAuthOidcCommand.php:100`,
   `src/MakeAuthCommand.php:60`, `vendor/quellabs/sculpt/src/Contracts/StubCommand.php:22`, and
   `stubs/Controllers/OidcAuthenticationController.php:199`.

2. **`--mode=oidc_only` installs the wrong login mode.** The command uses the option to select files, but copies
   `stubs/config/oidc.php` without changing its `login_mode` value of `password_and_oidc`. An application following the
   documented OIDC-only installation steps will therefore show the combined-mode completion flow and expect local
   registration fields instead of offering profile confirmation. See `src/MakeAuthOidcCommand.php:65`,
   `src/MakeAuthOidcCommand.php:100`, and `stubs/config/oidc.php:22`.

3. **A future provider `auth_time` can satisfy step-up freshness indefinitely until that time arrives.** `OidcClient`
   checks only that `auth_time` is numeric; it does not reject a value later than the current time. It writes that value
   to the session, while Canvas's `StepUpAuthenticationAspect` checks `(time() - auth_time) <= maxAge`. A future value
   makes the difference negative and passes that check. See `src/Oidc/OidcClient.php:444`,
   `src/Identity/OidcLoginSession.php`, and `vendor/quellabs/canvas/src/Security/StepUpAuthenticationAspect.php:90`.

4. **Disabling OIDC does not stop all OIDC routes.** Only the login-start action checks `OidcConfig::isEnabled()`. An
   already pending callback can still establish a session or create a completion record; completion and link-start
   actions also remain active. This conflicts with the plan's opt-in behavior and its rollback instruction to disable
   routes/config first. See `stubs/Controllers/OidcAuthenticationController.php:104`, `:128`, `:192`, `:217`, `:276`,
   and `:374`.

5. **OIDC-only logout also accepts GET.** The plan specifies POST logout, but `OidcOnlyLoginController` registers both
   POST and GET for `/logout`. See `stubs/Controllers/OidcOnlyLoginController.php:42`.

## Verification performed

- `php ./vendor/bin/phpunit` on PHP 8.3.14: **passed**, 102 tests and 170 assertions.
- `php ./vendor/bin/phpunit -c phpunit.integration.xml`: **passed**, 8 MySQL tests and 11 assertions.
- `composer validate --no-check-publish`: **passed**.
- `php ./vendor/bin/phpstan analyse --no-progress`: **failed** with one finding at
  `src/AccountEligibilityAspect.php:172` (`return.unusedType`).

The test suites contain no tests for `MakeAuthOidcCommand`, the generated OIDC controllers, or the browser routes. The
MySQL suite hand-creates the schema; it does not verify a generated migration. The plan's real-provider, reverse-proxy
cookie, and deployment checks were not performed in this repository review. The findings above are based on source
inspection and the stated test results; the browser flow was not exercised end to end.
