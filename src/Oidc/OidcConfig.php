<?php

	namespace Quellabs\CanvasAuthorization\Oidc;

	use InvalidArgumentException;
	use Quellabs\Contracts\Configuration\ConfigProviderInterface;
	use RuntimeException;

	/**
	 * Validated application configuration for OIDC login, loaded from
	 * config/oidc.php (and config/oidc.local.php for the client secret).
	 * Validation runs at construction time so a misconfigured application
	 * fails at startup rather than partway through a login attempt.
	 *
	 * Only the application's own config values are checked here. Provider
	 * metadata returned by discovery — response modes, token-endpoint
	 * authentication methods, the issuer's advertised signing algorithms —
	 * isn't known yet at this point and is validated separately by
	 * OidcClient when it actually fetches that metadata.
	 *
	 * config/oidc.php keys:
	 *   enabled                        — defaults to false
	 *   issuer                         — required when enabled, must be an HTTPS URL
	 *   client_id                      — required when enabled
	 *   redirect_uri                   — required when enabled; must be HTTPS unless app.php's debug_mode is true
	 *   allowed_signing_algorithms     — defaults to ['RS256']; only RS256 is supported in this release
	 *   login_mode                     — defaults to 'password_and_oidc'; or 'oidc_only'
	 *   allow_private_network_endpoints — defaults to false; set true only for an operator-controlled internal provider
	 *
	 * config/oidc.local.php keys:
	 *   client_secret — required when enabled
	 */
	class OidcConfig {

		/**
		 * Signing algorithms this package can verify. Only RS256 is implemented
		 * in the first release; a config requesting anything else fails closed.
		 */
		private const array SUPPORTED_SIGNING_ALGORITHMS = ['RS256'];

		/**
		 * Login modes accepted by config/oidc.php's login_mode key.
		 */
		private const array SUPPORTED_LOGIN_MODES = ['password_and_oidc', 'oidc_only'];

		private bool $enabled;
		private string $issuer;
		private string $clientId;
		private string $clientSecret;
		private string $redirectUri;
		private array $allowedSigningAlgorithms;
		private string $loginMode;
		private bool $allowPrivateNetworkEndpoints;

		/**
		 * OidcConfig constructor
		 * @param ConfigProviderInterface $configLoader Used to load oidc.php/oidc.local.php and app.php (for debug_mode).
		 * @throws InvalidArgumentException On a malformed or unsupported configuration value.
		 * @throws RuntimeException When OIDC is enabled but a required value is missing.
		 */
		public function __construct(ConfigProviderInterface $configLoader) {
			$config = $configLoader->loadConfigFile('oidc.php');
			$appConfig = $configLoader->loadConfigFile('app.php');
			$debugMode = (bool)$appConfig->get('debug_mode', false);

			$this->enabled = (bool)$config->get('enabled', false);
			$this->issuer = (string)$config->get('issuer', '');
			$this->clientId = (string)$config->get('client_id', '');
			$this->clientSecret = (string)$config->get('client_secret', '');
			$this->redirectUri = (string)$config->get('redirect_uri', '');
			$this->loginMode = (string)$config->get('login_mode', 'password_and_oidc');
			$this->allowPrivateNetworkEndpoints = (bool)$config->get('allow_private_network_endpoints', false);

			$rawAlgorithms = $config->get('allowed_signing_algorithms', self::SUPPORTED_SIGNING_ALGORITHMS);

			if (!is_array($rawAlgorithms)) {
				throw new InvalidArgumentException("'allowed_signing_algorithms' must be an array.");
			}

			$this->allowedSigningAlgorithms = $rawAlgorithms;

			$this->assertValidLoginMode();
			$this->assertValidSigningAlgorithms();

			// A disabled feature doesn't need its provider details filled in —
			// only enforce the required fields once an operator actually turns it on
			if (!$this->enabled) {
				return;
			}

			$this->assertRequiredFieldsPresent();
			$this->assertValidHttpsUrl($this->issuer, 'issuer');

			if ($debugMode) {
				$this->assertValidUrl($this->redirectUri, 'redirect_uri');
			} else {
				$this->assertValidHttpsUrl($this->redirectUri, 'redirect_uri');
			}
		}

		/**
		 * @return bool
		 */
		public function isEnabled(): bool {
			return $this->enabled;
		}

		/**
		 * @return string
		 */
		public function getIssuer(): string {
			return $this->issuer;
		}

		/**
		 * @return string
		 */
		public function getClientId(): string {
			return $this->clientId;
		}

		/**
		 * @return string
		 */
		public function getClientSecret(): string {
			return $this->clientSecret;
		}

		/**
		 * @return string
		 */
		public function getRedirectUri(): string {
			return $this->redirectUri;
		}

		/**
		 * @return string[]
		 */
		public function getAllowedSigningAlgorithms(): array {
			return $this->allowedSigningAlgorithms;
		}

		/**
		 * @return string
		 */
		public function getLoginMode(): string {
			return $this->loginMode;
		}

		/**
		 * @return bool
		 */
		public function allowsPrivateNetworkEndpoints(): bool {
			return $this->allowPrivateNetworkEndpoints;
		}

		/**
		 * @throws InvalidArgumentException
		 */
		private function assertValidLoginMode(): void {
			if (!in_array($this->loginMode, self::SUPPORTED_LOGIN_MODES, true)) {
				throw new InvalidArgumentException(
					"Unsupported 'login_mode' value '{$this->loginMode}'. Expected one of: " .
					implode(', ', self::SUPPORTED_LOGIN_MODES) . '.'
				);
			}
		}

		/**
		 * @throws InvalidArgumentException
		 */
		private function assertValidSigningAlgorithms(): void {
			if ($this->allowedSigningAlgorithms === []) {
				throw new InvalidArgumentException("'allowed_signing_algorithms' must not be empty.");
			}

			foreach ($this->allowedSigningAlgorithms as $algorithm) {
				if (!is_string($algorithm) || !in_array($algorithm, self::SUPPORTED_SIGNING_ALGORITHMS, true)) {
					$label = is_string($algorithm) ? $algorithm : gettype($algorithm);

					throw new InvalidArgumentException(
						"Unsupported signing algorithm '{$label}'. Only " .
						implode(', ', self::SUPPORTED_SIGNING_ALGORITHMS) . ' is supported.'
					);
				}
			}
		}

		/**
		 * @throws RuntimeException
		 */
		private function assertRequiredFieldsPresent(): void {
			if ($this->issuer === '') {
				throw new RuntimeException("OIDC is enabled but 'issuer' is not configured in config/oidc.php.");
			}

			if ($this->clientId === '') {
				throw new RuntimeException("OIDC is enabled but 'client_id' is not configured in config/oidc.php.");
			}

			if ($this->clientSecret === '') {
				throw new RuntimeException("OIDC is enabled but 'client_secret' is not configured in config/oidc.local.php.");
			}

			if ($this->redirectUri === '') {
				throw new RuntimeException("OIDC is enabled but 'redirect_uri' is not configured in config/oidc.php.");
			}
		}

		/**
		 * @param string $url
		 * @param string $field
		 * @throws InvalidArgumentException
		 */
		private function assertValidUrl(string $url, string $field): void {
			if (filter_var($url, FILTER_VALIDATE_URL) === false) {
				throw new InvalidArgumentException("'{$field}' is not a valid URL: '{$url}'.");
			}
		}

		/**
		 * @param string $url
		 * @param string $field
		 * @throws InvalidArgumentException
		 */
		private function assertValidHttpsUrl(string $url, string $field): void {
			$this->assertValidUrl($url, $field);

			if (!str_starts_with($url, 'https://')) {
				throw new InvalidArgumentException("'{$field}' must use HTTPS: '{$url}'.");
			}
		}
	}
