# OIDC browser test

This suite installs the current package into a disposable Canvas application,
generates and applies its ObjectQuel migrations, starts a local OpenID Connect
provider, and drives the OIDC-only login in Chromium. It checks first-login
account creation and a later login that reuses the same local user. A fresh
MySQL database is created for each run and dropped afterward.

Requirements: PHP 8.3 with `pdo_mysql` and OpenSSL, Composer, Node.js 20.11+, and a
running MySQL server. The MySQL account must be able to create and drop test
databases. The suite uses ports 8000 and 8443 on `127.0.0.1`; keep those ports
free. From this directory, run:

```sh
npm ci
npx playwright install chromium
npm test
```

The MySQL defaults are `127.0.0.1:3306`, user `root`, and an empty password.
Override them with `E2E_DB_HOST`, `E2E_DB_PORT`, `E2E_DB_USER`, and
`E2E_DB_PASS`. The runner only creates or drops database names with its
`canvas_authorization_e2e_` prefix and a random suffix.

The local provider uses a throwaway self-signed certificate. The test app trusts
that certificate only for this run and permits HTTP callback URLs through
Canvas debug mode. The provider's built-in development login page and in-memory
state are test fixtures, not deployment settings. Logs and browser traces from
the latest run are under `.runtime/` and `test-results/`.
