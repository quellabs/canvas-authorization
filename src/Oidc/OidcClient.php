<?php

	namespace Quellabs\CanvasAuthorization\Oidc;

	use JsonException;
	use Jose\Component\Core\AlgorithmManager;
	use Jose\Component\Core\JWK;
	use Jose\Component\Core\JWKSet;
	use Jose\Component\Signature\Algorithm\RS256;
	use Jose\Component\Signature\JWSVerifier;
	use Jose\Component\Signature\Serializer\CompactSerializer;
	use Quellabs\CanvasAuthorization\Oidc\Exceptions\InvalidIdTokenException;
	use Quellabs\CanvasAuthorization\Oidc\Exceptions\OidcDiscoveryException;
	use Quellabs\CanvasAuthorization\Oidc\Exceptions\OidcTokenExchangeException;
	use Symfony\Component\HttpClient\HttpClient;
	use Symfony\Component\HttpFoundation\IpUtils;
	use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
	use Symfony\Contracts\HttpClient\HttpClientInterface;
	use Symfony\Contracts\HttpClient\ResponseInterface;
	use Throwable;

	/**
	 * OidcClientInterface implementation. Talks to the provider directly over
	 * symfony/http-client (no PSR-18 bridge) and verifies ID tokens with
	 * web-token/jwt-library; both are the only third-party dependencies this
	 * class relies on.
	 *
	 * Discovery metadata and the JWKS are cached in-memory for the lifetime of
	 * this instance. Every request this class makes — for metadata, for the
	 * JWKS, and for the token endpoint — goes through performSafeRequest(),
	 * which enforces HTTPS, blocks private/reserved-network destinations
	 * unless the configuration explicitly allows them, pins the resolved IP to
	 * prevent a DNS change between the check and the request, and disables
	 * redirects outright.
	 */
	class OidcClient implements OidcClientInterface {

		/**
		 * Bounded timeouts applied to every request this class makes.
		 */
		private const int HTTP_TIMEOUT_SECONDS = 10;
		private const int HTTP_MAX_DURATION_SECONDS = 15;

		/**
		 * How long discovery metadata and the JWKS are cached in-memory before
		 * being refetched on next use. A rotated signing key is handled
		 * separately in getJwkForKid(), which refreshes once on an unknown kid
		 * regardless of this TTL.
		 */
		private const int DISCOVERY_CACHE_TTL_SECONDS = 3600;
		private const int JWKS_CACHE_TTL_SECONDS = 3600;

		/**
		 * Seconds of clock skew tolerated when validating exp/iat.
		 */
		private const int CLOCK_SKEW_SECONDS = 60;

		private OidcConfig $config;
		private HttpClientInterface $httpClient;
		private HostResolverInterface $hostResolver;

		/** @var array<string, mixed>|null */
		private ?array $cachedMetadata = null;
		private ?int $metadataFetchedAt = null;
		private ?JWKSet $cachedJwkSet = null;
		private ?int $jwkSetFetchedAt = null;

		/**
		 * OidcClient constructor
		 * @param OidcConfig $config Validated application OIDC configuration.
		 * @param HttpClientInterface|null $httpClient Transport used for every discovery/JWKS/token request; defaults to Symfony's default client. Override in tests with MockHttpClient.
		 * @param HostResolverInterface|null $hostResolver Defaults to DNS-backed resolution; override in tests for deterministic IPs.
		 */
		public function __construct(OidcConfig $config, ?HttpClientInterface $httpClient = null, ?HostResolverInterface $hostResolver = null) {
			$this->config = $config;
			$this->httpClient = $httpClient ?? HttpClient::create();
			$this->hostResolver = $hostResolver ?? new DnsHostResolver();
		}

		/**
		 * Build the provider authorization URL for a fresh login or link attempt.
		 * @param string $state
		 * @param string $nonce
		 * @return AuthorizationRequest
		 * @throws OidcDiscoveryException On invalid, inconsistent, or unreachable issuer metadata.
		 */
		public function createAuthorizationRequest(string $state, string $nonce): AuthorizationRequest {
			$metadata = $this->getDiscoveryMetadata(false);

			$codeVerifier = $this->generateCodeVerifier();
			$codeChallenge = $this->base64UrlEncode(hash('sha256', $codeVerifier, true));

			$query = [
				'response_type'         => 'code',
				'client_id'             => $this->config->getClientId(),
				'redirect_uri'          => $this->config->getRedirectUri(),
				'scope'                 => 'openid',
				'state'                 => $state,
				'nonce'                 => $nonce,
				'code_challenge'        => $codeChallenge,
				'code_challenge_method' => 'S256',
			];

			$authorizationEndpoint = $this->requireStringField($metadata, 'authorization_endpoint');
			$separator = str_contains($authorizationEndpoint, '?') ? '&' : '?';
			$url = $authorizationEndpoint . $separator . http_build_query($query);

			return new AuthorizationRequest($url, $codeVerifier);
		}

		/**
		 * Exchange an authorization code for a validated ID token.
		 * @param string $code
		 * @param string $codeVerifier
		 * @param string $expectedNonce
		 * @return IdTokenClaims
		 * @throws OidcDiscoveryException On invalid or unreachable issuer metadata/JWKS.
		 * @throws OidcTokenExchangeException On a token-endpoint failure.
		 * @throws InvalidIdTokenException When the ID token is missing, malformed, or fails any validation.
		 */
		public function exchangeAuthorizationCode(string $code, string $codeVerifier, string $expectedNonce): IdTokenClaims {
			$metadata = $this->getDiscoveryMetadata(false);

			$tokenEndpoint = $this->requireStringField($metadata, 'token_endpoint');
			$tokenResponse = $this->postTokenRequest($tokenEndpoint, [
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $this->config->getRedirectUri(),
				'code_verifier' => $codeVerifier,
			]);

			$idToken = $tokenResponse['id_token'] ?? null;

			if (!is_string($idToken) || $idToken === '') {
				throw new InvalidIdTokenException('Token endpoint response did not include an ID token.');
			}

			return $this->validateIdToken($idToken, $expectedNonce);
		}

		/**
		 * Fetch issuer discovery metadata, validating it on first fetch or on
		 * cache expiry. Cached for DISCOVERY_CACHE_TTL_SECONDS.
		 * @param bool $forceRefresh Bypass the cache and refetch unconditionally.
		 * @return array<string, mixed>
		 * @throws OidcDiscoveryException
		 */
		private function getDiscoveryMetadata(bool $forceRefresh): array {
			$now = time();

			if (
				!$forceRefresh
				&& $this->cachedMetadata !== null
				&& $this->metadataFetchedAt !== null
				&& ($now - $this->metadataFetchedAt) < self::DISCOVERY_CACHE_TTL_SECONDS
			) {
				return $this->cachedMetadata;
			}

			$issuer = rtrim($this->config->getIssuer(), '/');
			$discoveryUrl = $issuer . '/.well-known/openid-configuration';
			$metadata = $this->fetchJson($discoveryUrl);

			$this->assertValidDiscoveryMetadata($metadata);

			$this->cachedMetadata = $metadata;
			$this->metadataFetchedAt = $now;

			return $metadata;
		}

		/**
		 * Validate discovery metadata against the configured issuer and the
		 * protocol constraints this package enforces: an exact issuer match,
		 * HTTPS endpoints, support for the 'query' response mode (when the
		 * field is present; its absence defaults to including 'query' per the
		 * Discovery spec), support for 'client_secret_basic' token-endpoint
		 * authentication (when the field is present; its absence defaults to
		 * that method per the spec), and advertised support for every
		 * algorithm this application is configured to accept.
		 * @param array<string, mixed> $metadata
		 * @throws OidcDiscoveryException
		 */
		private function assertValidDiscoveryMetadata(array $metadata): void {
			foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri', 'id_token_signing_alg_values_supported'] as $field) {
				if (!isset($metadata[$field])) {
					throw new OidcDiscoveryException("Discovery metadata is missing required field '{$field}'.");
				}
			}

			if (!is_string($metadata['issuer']) || $metadata['issuer'] !== $this->config->getIssuer()) {
				$actualIssuer = is_string($metadata['issuer']) ? $metadata['issuer'] : gettype($metadata['issuer']);

				throw new OidcDiscoveryException(
					"Discovery metadata issuer '{$actualIssuer}' does not match the configured issuer '{$this->config->getIssuer()}'."
				);
			}

			foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
				if (!is_string($metadata[$field])) {
					throw new OidcDiscoveryException("Discovery metadata field '{$field}' must be a string.");
				}

				$this->assertHttpsUrl($metadata[$field]);
			}

			if (isset($metadata['response_modes_supported'])) {
				if (!is_array($metadata['response_modes_supported']) || !in_array('query', $metadata['response_modes_supported'], true)) {
					throw new OidcDiscoveryException("Discovery metadata does not support the 'query' response mode.");
				}
			}

			if (isset($metadata['token_endpoint_auth_methods_supported'])) {
				if (!is_array($metadata['token_endpoint_auth_methods_supported']) || !in_array('client_secret_basic', $metadata['token_endpoint_auth_methods_supported'], true)) {
					throw new OidcDiscoveryException("Discovery metadata does not support the 'client_secret_basic' token-endpoint authentication method.");
				}
			}

			$supportedAlgorithms = $metadata['id_token_signing_alg_values_supported'];

			if (!is_array($supportedAlgorithms)) {
				throw new OidcDiscoveryException("Discovery metadata field 'id_token_signing_alg_values_supported' must be an array.");
			}

			foreach ($this->config->getAllowedSigningAlgorithms() as $algorithm) {
				if (!in_array($algorithm, $supportedAlgorithms, true)) {
					throw new OidcDiscoveryException("Issuer does not advertise support for the required signing algorithm '{$algorithm}'.");
				}
			}
		}

		/**
		 * Fetch the issuer's JWKS, caching it for JWKS_CACHE_TTL_SECONDS.
		 * @param bool $forceRefresh Bypass the cache and refetch unconditionally.
		 * @return JWKSet
		 * @throws OidcDiscoveryException
		 */
		private function getJwkSet(bool $forceRefresh): JWKSet {
			$now = time();

			if (
				!$forceRefresh
				&& $this->cachedJwkSet !== null
				&& $this->jwkSetFetchedAt !== null
				&& ($now - $this->jwkSetFetchedAt) < self::JWKS_CACHE_TTL_SECONDS
			) {
				return $this->cachedJwkSet;
			}

			$metadata = $this->getDiscoveryMetadata(false);
			$data = $this->fetchJson($this->requireStringField($metadata, 'jwks_uri'));

			try {
				$jwkSet = JWKSet::createFromKeyData($data);
			} catch (Throwable $e) {
				throw new OidcDiscoveryException('Invalid JWKS document.', 0, $e);
			}

			$this->cachedJwkSet = $jwkSet;
			$this->jwkSetFetchedAt = $now;

			return $jwkSet;
		}

		/**
		 * Resolve the JWK for a given 'kid', refreshing the JWKS once if it
		 * isn't found in the cached set — this is what makes key rotation
		 * work. If the key still isn't found after that refresh, fail closed.
		 * @param string $kid
		 * @return JWK
		 * @throws OidcDiscoveryException
		 * @throws InvalidIdTokenException
		 */
		private function getJwkForKid(string $kid): JWK {
			$jwkSet = $this->getJwkSet(false);

			if ($jwkSet->has($kid)) {
				return $jwkSet->get($kid);
			}

			$jwkSet = $this->getJwkSet(true);

			if ($jwkSet->has($kid)) {
				return $jwkSet->get($kid);
			}

			throw new InvalidIdTokenException("Unknown ID token signing key 'kid={$kid}'.");
		}

		/**
		 * Verify an ID token's signature against the issuer's JWKS and
		 * validate every required claim.
		 * @param string $idToken Compact-serialized ID token.
		 * @param string $expectedNonce Nonce to validate against the token's 'nonce' claim.
		 * @return IdTokenClaims
		 * @throws OidcDiscoveryException
		 * @throws InvalidIdTokenException
		 */
		private function validateIdToken(string $idToken, string $expectedNonce): IdTokenClaims {
			$serializer = new CompactSerializer();

			try {
				$jws = $serializer->unserialize($idToken);
			} catch (Throwable $e) {
				throw new InvalidIdTokenException('Malformed ID token.', 0, $e);
			}

			$header = $jws->getSignature(0)->getProtectedHeader();
			$algorithm = $header['alg'] ?? null;

			// Reject up front, before any key lookup or verification attempt: accepting
			// a header that names 'none' or an algorithm this application wasn't
			// configured to trust would allow an attacker to choose their own
			// verification path (algorithm substitution)
			if (!is_string($algorithm) || !in_array($algorithm, $this->config->getAllowedSigningAlgorithms(), true)) {
				throw new InvalidIdTokenException('Unsupported or missing ID token signing algorithm.');
			}

			$kid = $header['kid'] ?? null;

			if (!is_string($kid) || $kid === '') {
				throw new InvalidIdTokenException('ID token header is missing a key identifier (kid).');
			}

			$jwk = $this->getJwkForKid($kid);
			$algorithmManager = new AlgorithmManager([new RS256()]);
			$verifier = new JWSVerifier($algorithmManager);

			try {
				$verified = $verifier->verifyWithKeySet($jws, new JWKSet([$jwk]), 0);
			} catch (Throwable $e) {
				throw new InvalidIdTokenException('ID token signature verification failed.', 0, $e);
			}

			if ($verified !== true) {
				throw new InvalidIdTokenException('ID token signature is invalid.');
			}

			try {
				$claims = json_decode($jws->getPayload() ?? '', true, 512, JSON_THROW_ON_ERROR);
			} catch (JsonException $e) {
				throw new InvalidIdTokenException('ID token payload is not valid JSON.', 0, $e);
			}

			if (!is_array($claims)) {
				throw new InvalidIdTokenException('ID token payload is not a JSON object.');
			}

			return $this->validateClaims($claims, $expectedNonce);
		}

		/**
		 * Validate every required ID-token claim and build the result object.
		 * Only reached after the signature has already been verified.
		 *
		 * $claims is keyed int|string, not just string: json_decode(assoc:
		 * true) turns a JSON object key that looks like an integer (e.g.
		 * "123") into an int array key, not a string one.
		 * @param array<int|string, mixed> $claims
		 * @param string $expectedNonce
		 * @return IdTokenClaims
		 * @throws InvalidIdTokenException
		 */
		private function validateClaims(array $claims, string $expectedNonce): IdTokenClaims {
			$issuer = $claims['iss'] ?? null;

			if (!is_string($issuer) || $issuer !== $this->config->getIssuer()) {
				throw new InvalidIdTokenException('ID token issuer does not match the configured issuer.');
			}

			$subject = $claims['sub'] ?? null;

			if (!is_string($subject) || $subject === '') {
				throw new InvalidIdTokenException('ID token is missing a non-empty subject (sub) claim.');
			}

			$clientId = $this->config->getClientId();
			$audience = $claims['aud'] ?? null;
			$audienceIsMultiValued = false;

			if (is_string($audience)) {
				$audienceMatches = $audience === $clientId;
			} elseif (is_array($audience)) {
				foreach ($audience as $entry) {
					if (!is_string($entry)) {
						throw new InvalidIdTokenException('ID token audience (aud) claim is invalid.');
					}
				}

				$audienceMatches = in_array($clientId, $audience, true);
				$audienceIsMultiValued = count($audience) > 1;
			} else {
				throw new InvalidIdTokenException('ID token is missing an audience (aud) claim.');
			}

			if (!$audienceMatches) {
				throw new InvalidIdTokenException('ID token audience does not include the configured client ID.');
			}

			$authorizedParty = $claims['azp'] ?? null;

			if ($authorizedParty !== null && !is_string($authorizedParty)) {
				throw new InvalidIdTokenException('ID token authorized party (azp) claim is invalid.');
			}

			// Per OIDC Core 3.1.3.7: an ID token with multiple audiences must carry
			// azp, and when azp is present (regardless of audience count) it must
			// identify this application's own client
			if ($audienceIsMultiValued && $authorizedParty === null) {
				throw new InvalidIdTokenException('ID token has multiple audiences but is missing the authorized party (azp) claim.');
			}

			if ($authorizedParty !== null && $authorizedParty !== $clientId) {
				throw new InvalidIdTokenException('ID token authorized party (azp) does not match the configured client ID.');
			}

			$now = time();
			$expiresAt = $claims['exp'] ?? null;

			if (!is_int($expiresAt) && !is_float($expiresAt)) {
				throw new InvalidIdTokenException('ID token is missing a valid expiration (exp) claim.');
			}

			if ((int)$expiresAt < ($now - self::CLOCK_SKEW_SECONDS)) {
				throw new InvalidIdTokenException('ID token has expired.');
			}

			$issuedAt = $claims['iat'] ?? null;

			if (!is_int($issuedAt) && !is_float($issuedAt)) {
				throw new InvalidIdTokenException('ID token is missing a valid issued-at (iat) claim.');
			}

			if ((int)$issuedAt > ($now + self::CLOCK_SKEW_SECONDS)) {
				throw new InvalidIdTokenException('ID token issued-at (iat) claim is in the future.');
			}

			$nonce = $claims['nonce'] ?? null;

			if (!is_string($nonce) || $nonce === '' || !hash_equals($expectedNonce, $nonce)) {
				throw new InvalidIdTokenException('ID token nonce does not match the expected value.');
			}

			$authTime = $claims['auth_time'] ?? null;

			if ($authTime !== null && !is_int($authTime) && !is_float($authTime)) {
				throw new InvalidIdTokenException('ID token auth_time claim is invalid.');
			}

			// A future auth_time would let StepUpAuthenticationAspect's
			// (time() - auth_time) <= maxAge check pass indefinitely once that
			// time arrives, so it's rejected the same way a future iat is above
			if ($authTime !== null && (int)$authTime > ($now + self::CLOCK_SKEW_SECONDS)) {
				throw new InvalidIdTokenException('ID token auth_time claim is in the future.');
			}

			return new IdTokenClaims(
				$issuer,
				$subject,
				$clientId,
				$authorizedParty,
				(int)$expiresAt,
				(int)$issuedAt,
				$authTime !== null ? (int)$authTime : null
			);
		}

		/**
		 * Read a required string field out of already-validated discovery
		 * metadata. assertValidDiscoveryMetadata() checked this field's type
		 * when the metadata was first fetched, but that guarantee doesn't
		 * carry across the cache (or a static analyzer's view of a plain
		 * array) back to callers — this re-asserts it at the point of use.
		 * @param array<string, mixed> $metadata
		 * @param string $field
		 * @return string
		 * @throws OidcDiscoveryException
		 */
		private function requireStringField(array $metadata, string $field): string {
			$value = $metadata[$field] ?? null;

			if (!is_string($value) || $value === '') {
				throw new OidcDiscoveryException("Discovery metadata field '{$field}' must be a non-empty string.");
			}

			return $value;
		}

		/**
		 * GET a URL through performSafeRequest() and decode its JSON body.
		 * @param string $url
		 * @return array<string, mixed>
		 * @throws OidcDiscoveryException
		 */
		private function fetchJson(string $url): array {
			try {
				$response = $this->performSafeRequest('GET', $url);
				$data = $response->toArray();
			} catch (ExceptionInterface $e) {
				throw new OidcDiscoveryException("Request to '{$url}' failed: {$e->getMessage()}", 0, $e);
			}

			return $data;
		}

		/**
		 * POST a form-encoded request to the token endpoint through
		 * performSafeRequest(), authenticating with client_secret_basic.
		 * @param string $url
		 * @param array<string, string> $formParams
		 * @return array<string, mixed>
		 * @throws OidcTokenExchangeException
		 */
		private function postTokenRequest(string $url, array $formParams): array {
			$authHeader = 'Basic ' . base64_encode($this->config->getClientId() . ':' . $this->config->getClientSecret());

			try {
				$response = $this->performSafeRequest('POST', $url, [
					'headers' => ['Authorization' => $authHeader],
					'body'    => $formParams,
				]);

				$data = $response->toArray();
			} catch (ExceptionInterface $e) {
				throw new OidcTokenExchangeException("Token request to '{$url}' failed: {$e->getMessage()}", 0, $e);
			}

			return $data;
		}

		/**
		 * Perform an HTTP request after validating the target URL is HTTPS and
		 * does not resolve to a private or reserved network address (unless
		 * explicitly allowed), pinning the resolved IP so the actual request
		 * cannot be redirected to a different address by a DNS change between
		 * the check and the request. Redirects are disabled outright.
		 * @param string $method
		 * @param string $url
		 * @param array<string, mixed> $options
		 * @return ResponseInterface
		 * @throws OidcDiscoveryException On an unsafe or malformed URL.
		 * @throws ExceptionInterface On a transport-level failure; left for the caller to classify.
		 */
		private function performSafeRequest(string $method, string $url, array $options = []): ResponseInterface {
			$this->assertHttpsUrl($url);

			$host = parse_url($url, PHP_URL_HOST);

			if (!is_string($host) || $host === '') {
				throw new OidcDiscoveryException("Malformed endpoint URL: '{$url}'.");
			}

			$ip = $this->resolveAndValidateHost($host);

			$safeOptions = array_merge($options, [
				'timeout'       => self::HTTP_TIMEOUT_SECONDS,
				'max_duration'  => self::HTTP_MAX_DURATION_SECONDS,
				'max_redirects' => 0,
				'resolve'       => [$host => $ip],
			]);

			return $this->httpClient->request($method, $url, $safeOptions);
		}

		/**
		 * @param string $url
		 * @throws OidcDiscoveryException
		 */
		private function assertHttpsUrl(string $url): void {
			$parts = parse_url($url);

			if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
				throw new OidcDiscoveryException("Malformed endpoint URL: '{$url}'.");
			}

			if ($parts['scheme'] !== 'https') {
				throw new OidcDiscoveryException("Endpoint URL must use HTTPS: '{$url}'.");
			}
		}

		/**
		 * Resolve a host to an IP address, validating that it is not a
		 * private/reserved network destination (unless explicitly allowed).
		 * @param string $host
		 * @return string The resolved (or literal) IP address to pin for the request.
		 * @throws OidcDiscoveryException
		 */
		private function resolveAndValidateHost(string $host): string {
			if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
				$this->assertPublicIp($host, $host);
				return $host;
			}

			$ips = $this->hostResolver->resolve($host);

			if ($ips === []) {
				throw new OidcDiscoveryException("Could not resolve host '{$host}'.");
			}

			foreach ($ips as $ip) {
				$this->assertPublicIp($ip, $host);
			}

			return $ips[0];
		}

		/**
		 * Rejects a private or reserved-range IP (loopback, RFC1918, link-local,
		 * documentation ranges, etc.). Delegates the actual range matching to
		 * IpUtils::isPrivateIp() rather than filter_var()'s FILTER_FLAG_NO_PRIV_RANGE/
		 * FILTER_FLAG_NO_RES_RANGE flags, which symfony/http-client's own
		 * NoPrivateNetworkHttpClient notes are not reliable enough for this purpose.
		 * @param string $ip
		 * @param string $host Original hostname, used only for the error message.
		 * @throws OidcDiscoveryException
		 */
		private function assertPublicIp(string $ip, string $host): void {
			if ($this->config->allowsPrivateNetworkEndpoints()) {
				return;
			}

			if (IpUtils::isPrivateIp($ip)) {
				throw new OidcDiscoveryException("Endpoint host '{$host}' resolves to a private or reserved network address.");
			}
		}

		/**
		 * @return string RFC 7636 code_verifier: 43 characters of base64url-encoded randomness.
		 */
		private function generateCodeVerifier(): string {
			return $this->base64UrlEncode(random_bytes(32));
		}

		/**
		 * @param string $bytes
		 * @return string
		 */
		private function base64UrlEncode(string $bytes): string {
			return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
		}
	}
