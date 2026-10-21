<?php

	namespace Quellabs\CanvasAuthorization\Tests\Oidc;

	use Jose\Component\Core\AlgorithmManager;
	use Jose\Component\Core\JWK;
	use Jose\Component\KeyManagement\JWKFactory;
	use Jose\Component\Signature\Algorithm\HS256;
	use Jose\Component\Signature\Algorithm\RS256;
	use Jose\Component\Signature\JWSBuilder;
	use Jose\Component\Signature\Serializer\CompactSerializer;
	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasAuthorization\Oidc\Exceptions\InvalidIdTokenException;
	use Quellabs\CanvasAuthorization\Oidc\Exceptions\OidcDiscoveryException;
	use Quellabs\CanvasAuthorization\Oidc\OidcClient;
	use Quellabs\CanvasAuthorization\Oidc\OidcConfig;
	use Quellabs\CanvasAuthorization\Tests\Fixtures\FakeConfigProvider;
	use Quellabs\CanvasAuthorization\Tests\Fixtures\FakeHostResolver;
	use Symfony\Component\HttpClient\MockHttpClient;
	use Symfony\Component\HttpClient\Response\MockResponse;

	class OidcClientTest extends TestCase {

		private const string ISSUER = 'https://idp.example.test';
		private const string CLIENT_ID = 'client-123';
		private const string CLIENT_SECRET = 'super-secret';
		private const string REDIRECT_URI = 'https://app.example.test/auth/oidc/callback';
		private const string KID = 'key-1';
		private const string ROTATED_KID = 'key-2';

		private static JWK $privateJwk;
		private static JWK $rotatedPrivateJwk;

		public static function setUpBeforeClass(): void {
			self::$privateJwk = JWKFactory::createRSAKey(2048, ['kid' => self::KID, 'use' => 'sig']);
			self::$rotatedPrivateJwk = JWKFactory::createRSAKey(2048, ['kid' => self::ROTATED_KID, 'use' => 'sig']);
		}

		/**
		 * @return array<string, mixed>
		 */
		private function validMetadata(array $overrides = []): array {
			return array_merge([
				'issuer'                                => self::ISSUER,
				'authorization_endpoint'                => self::ISSUER . '/authorize',
				'token_endpoint'                         => self::ISSUER . '/token',
				'jwks_uri'                               => self::ISSUER . '/jwks',
				'id_token_signing_alg_values_supported'  => ['RS256'],
				'response_modes_supported'               => ['query', 'fragment'],
				'token_endpoint_auth_methods_supported'  => ['client_secret_basic'],
			], $overrides);
		}

		/**
		 * @param JWK[] $publicKeys
		 * @return array<string, mixed>
		 */
		private function jwksDocument(array $publicKeys): array {
			return ['keys' => array_map(static fn (JWK $jwk): array => $jwk->jsonSerialize(), $publicKeys)];
		}

		/**
		 * @return array<string, mixed>
		 */
		private function validClaims(array $overrides = []): array {
			$now = time();

			return array_merge([
				'iss'       => self::ISSUER,
				'sub'       => 'subject-abc',
				'aud'       => self::CLIENT_ID,
				'exp'       => $now + 300,
				'iat'       => $now,
				'nonce'     => 'test-nonce',
				'auth_time' => $now,
			], $overrides);
		}

		/**
		 * @param array<string, mixed> $claims
		 */
		private function signRs256(JWK $privateKey, string $kid, array $claims): string {
			$algorithmManager = new AlgorithmManager([new RS256()]);
			$jwsBuilder = new JWSBuilder($algorithmManager);

			$jws = $jwsBuilder->create()
				->withPayload(json_encode($claims, JSON_THROW_ON_ERROR))
				->addSignature($privateKey, ['alg' => 'RS256', 'kid' => $kid])
				->build();

			return (new CompactSerializer())->serialize($jws);
		}

		/**
		 * @param array<string, mixed> $claims
		 */
		private function signHs256(array $claims): string {
			$octKey = new JWK(['kty' => 'oct', 'k' => rtrim(strtr(base64_encode('a-fixed-test-hmac-secret-key-of-32-bytes-or-more'), '+/', '-_'), '=')]);
			$algorithmManager = new AlgorithmManager([new HS256()]);
			$jwsBuilder = new JWSBuilder($algorithmManager);

			$jws = $jwsBuilder->create()
				->withPayload(json_encode($claims, JSON_THROW_ON_ERROR))
				->addSignature($octKey, ['alg' => 'HS256', 'kid' => self::KID])
				->build();

			return (new CompactSerializer())->serialize($jws);
		}

		private function makeConfig(array $overrides = []): OidcConfig {
			$configLoader = new FakeConfigProvider([
				'oidc.php' => array_merge([
					'enabled'                    => true,
					'issuer'                     => self::ISSUER,
					'client_id'                  => self::CLIENT_ID,
					'client_secret'              => self::CLIENT_SECRET,
					'redirect_uri'               => self::REDIRECT_URI,
					'allowed_signing_algorithms' => ['RS256'],
				], $overrides),
			]);

			return new OidcConfig($configLoader);
		}

		/**
		 * Builds a client whose discovery/jwks/token endpoints are served by a
		 * single MockHttpClient, routed by URL suffix.
		 */
		private function makeClient(
			array    $metadataOverrides = [],
			?array   $jwksDocument = null,
			?string  $idToken = null,
			?array   $configOverrides = [],
			?array   $hostIps = null,
			?MockHttpClient $httpClient = null
		): OidcClient {
			$metadata = $this->validMetadata($metadataOverrides);
			$jwks = $jwksDocument ?? $this->jwksDocument([self::$privateJwk->toPublic()]);

			if ($httpClient === null) {
				$httpClient = new MockHttpClient(function (string $method, string $url) use ($metadata, $jwks, $idToken) {
					if (str_contains($url, '/.well-known/openid-configuration')) {
						return new MockResponse(json_encode($metadata, JSON_THROW_ON_ERROR));
					}

					if (str_contains($url, '/jwks')) {
						return new MockResponse(json_encode($jwks, JSON_THROW_ON_ERROR));
					}

					if (str_contains($url, '/token')) {
						return new MockResponse(json_encode(['id_token' => $idToken], JSON_THROW_ON_ERROR));
					}

					throw new \RuntimeException("Unexpected request to '{$url}'.");
				});
			}

			$hostResolver = new FakeHostResolver($hostIps ?? ['idp.example.test' => ['8.8.8.8']]);

			return new OidcClient($this->makeConfig($configOverrides ?? []), $httpClient, $hostResolver);
		}

		public function testCreateAuthorizationRequestBuildsExpectedUrlAndVerifier(): void {
			$client = $this->makeClient();
			$request = $client->createAuthorizationRequest('state-abc', 'nonce-abc');

			$this->assertStringStartsWith(self::ISSUER . '/authorize?', $request->getUrl());

			parse_str((string)parse_url($request->getUrl(), PHP_URL_QUERY), $query);

			$this->assertSame('code', $query['response_type']);
			$this->assertSame(self::CLIENT_ID, $query['client_id']);
			$this->assertSame(self::REDIRECT_URI, $query['redirect_uri']);
			$this->assertSame('openid', $query['scope']);
			$this->assertSame('state-abc', $query['state']);
			$this->assertSame('nonce-abc', $query['nonce']);
			$this->assertSame('S256', $query['code_challenge_method']);
			$this->assertNotEmpty($query['code_challenge']);

			$expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $request->getCodeVerifier(), true)), '+/', '-_'), '=');
			$this->assertSame($expectedChallenge, $query['code_challenge']);
		}

		public function testExchangeAuthorizationCodeAcceptsAValidIdToken(): void {
			$claims = $this->validClaims();
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $claims);
			$client = $this->makeClient(idToken: $idToken);

			$result = $client->exchangeAuthorizationCode('auth-code', 'verifier-value', 'test-nonce');

			$this->assertSame(self::ISSUER, $result->getIssuer());
			$this->assertSame('subject-abc', $result->getSubject());
			$this->assertSame(self::CLIENT_ID, $result->getAudience());
			$this->assertSame($claims['exp'], $result->getExpiresAt());
			$this->assertSame($claims['iat'], $result->getIssuedAt());
			$this->assertSame($claims['auth_time'], $result->getAuthTime());
			$this->assertNull($result->getAuthorizedParty());
		}

		public function testExchangeAuthorizationCodeSendsClientSecretBasicAndFormBody(): void {
			$claims = $this->validClaims();
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $claims);
			$metadata = $this->validMetadata();
			$jwks = $this->jwksDocument([self::$privateJwk->toPublic()]);
			$capturedOptions = null;

			$httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($metadata, $jwks, $idToken, &$capturedOptions) {
				if (str_contains($url, '/.well-known/openid-configuration')) {
					return new MockResponse(json_encode($metadata, JSON_THROW_ON_ERROR));
				}

				if (str_contains($url, '/jwks')) {
					return new MockResponse(json_encode($jwks, JSON_THROW_ON_ERROR));
				}

				if (str_contains($url, '/token')) {
					$capturedOptions = $options;
					return new MockResponse(json_encode(['id_token' => $idToken], JSON_THROW_ON_ERROR));
				}

				throw new \RuntimeException("Unexpected request to '{$url}'.");
			});

			$client = $this->makeClient(httpClient: $httpClient);
			$client->exchangeAuthorizationCode('auth-code', 'verifier-value', 'test-nonce');

			$this->assertNotNull($capturedOptions);
			$expectedAuth = 'Authorization: Basic ' . base64_encode(self::CLIENT_ID . ':' . self::CLIENT_SECRET);
			$this->assertContains($expectedAuth, $capturedOptions['headers']);

			parse_str((string)$capturedOptions['body'], $bodyParams);

			$this->assertSame('auth-code', $bodyParams['code']);
			$this->assertSame('authorization_code', $bodyParams['grant_type']);
			$this->assertSame(self::REDIRECT_URI, $bodyParams['redirect_uri']);
			$this->assertSame('verifier-value', $bodyParams['code_verifier']);
		}

		public function testInvalidIssuerInDiscoveryMetadataIsRejected(): void {
			$client = $this->makeClient(['issuer' => 'https://evil.example.test']);

			$this->expectException(OidcDiscoveryException::class);
			$client->createAuthorizationRequest('state', 'nonce');
		}

		public function testNonHttpsEndpointInDiscoveryMetadataIsRejected(): void {
			$client = $this->makeClient(['jwks_uri' => 'http://idp.example.test/jwks']);

			$this->expectException(OidcDiscoveryException::class);
			$client->createAuthorizationRequest('state', 'nonce');
		}

		public function testPrivateNetworkEndpointIsRejected(): void {
			$claims = $this->validClaims();
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $claims);

			$client = $this->makeClient(
				metadataOverrides: ['jwks_uri' => 'https://keys.internal.test/jwks'],
				idToken: $idToken,
				hostIps: [
					'idp.example.test'  => ['8.8.8.8'],
					'keys.internal.test' => ['10.0.0.5'],
				]
			);

			$this->expectException(OidcDiscoveryException::class);
			$client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');
		}

		public function testNetworkTimeoutFetchingMetadataIsWrapped(): void {
			$httpClient = new MockHttpClient(function (string $method, string $url) {
				if (str_contains($url, '/.well-known/openid-configuration')) {
					return new MockResponse('', ['error' => 'Connection timed out']);
				}

				throw new \RuntimeException("Unexpected request to '{$url}'.");
			});

			$client = $this->makeClient(httpClient: $httpClient);

			$this->expectException(OidcDiscoveryException::class);
			$client->createAuthorizationRequest('state', 'nonce');
		}

		public function testUnknownSigningKeyIsRejectedAfterOneRefresh(): void {
			$claims = $this->validClaims();
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $claims);

			// JWKS never contains the kid the token was signed with, even after refresh
			$jwks = $this->jwksDocument([self::$rotatedPrivateJwk->toPublic()]);
			$client = $this->makeClient(jwksDocument: $jwks, idToken: $idToken);

			$this->expectException(InvalidIdTokenException::class);
			$client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');
		}

		public function testRotatedSigningKeyIsAcceptedAfterOneRefresh(): void {
			$claims = $this->validClaims();
			$idToken = $this->signRs256(self::$rotatedPrivateJwk, self::ROTATED_KID, $claims);

			$metadata = $this->validMetadata();
			$oldJwks = $this->jwksDocument([self::$privateJwk->toPublic()]);
			$newJwks = $this->jwksDocument([self::$privateJwk->toPublic(), self::$rotatedPrivateJwk->toPublic()]);
			$jwksCallCount = 0;

			$httpClient = new MockHttpClient(function (string $method, string $url) use ($metadata, &$jwksCallCount, $oldJwks, $newJwks, $idToken) {
				if (str_contains($url, '/.well-known/openid-configuration')) {
					return new MockResponse(json_encode($metadata, JSON_THROW_ON_ERROR));
				}

				if (str_contains($url, '/jwks')) {
					$jwksCallCount++;
					$document = $jwksCallCount === 1 ? $oldJwks : $newJwks;
					return new MockResponse(json_encode($document, JSON_THROW_ON_ERROR));
				}

				if (str_contains($url, '/token')) {
					return new MockResponse(json_encode(['id_token' => $idToken], JSON_THROW_ON_ERROR));
				}

				throw new \RuntimeException("Unexpected request to '{$url}'.");
			});

			$client = $this->makeClient(httpClient: $httpClient);
			$result = $client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');

			$this->assertSame('subject-abc', $result->getSubject());
			$this->assertSame(2, $jwksCallCount);
		}

		public function testMissingIdTokenInTokenResponseIsRejected(): void {
			$client = $this->makeClient(idToken: null);

			$this->expectException(InvalidIdTokenException::class);
			$client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');
		}

		public function testAlgorithmSubstitutionIsRejected(): void {
			$idToken = $this->signHs256($this->validClaims());
			$client = $this->makeClient(idToken: $idToken);

			$this->expectException(InvalidIdTokenException::class);
			$this->expectExceptionMessage('Unsupported or missing ID token signing algorithm');
			$client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');
		}

		public function testTamperedSignatureIsRejected(): void {
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $this->validClaims());

			$segments = explode('.', $idToken);
			$signature = $segments[2];
			$lastChar = substr($signature, -1);
			$replacement = $lastChar === 'A' ? 'B' : 'A';
			$segments[2] = substr($signature, 0, -1) . $replacement;
			$tamperedToken = implode('.', $segments);

			$client = $this->makeClient(idToken: $tamperedToken);

			$this->expectException(InvalidIdTokenException::class);
			$client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');
		}

		public function testWrongIssuerClaimIsRejected(): void {
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $this->validClaims(['iss' => 'https://other.example.test']));
			$client = $this->makeClient(idToken: $idToken);

			$this->expectException(InvalidIdTokenException::class);
			$client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');
		}

		public function testEmptySubjectClaimIsRejected(): void {
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $this->validClaims(['sub' => '']));
			$client = $this->makeClient(idToken: $idToken);

			$this->expectException(InvalidIdTokenException::class);
			$client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');
		}

		public function testWrongAudienceClaimIsRejected(): void {
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $this->validClaims(['aud' => 'some-other-client']));
			$client = $this->makeClient(idToken: $idToken);

			$this->expectException(InvalidIdTokenException::class);
			$client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');
		}

		public function testMultipleAudiencesWithoutAzpIsRejected(): void {
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $this->validClaims(['aud' => [self::CLIENT_ID, 'another-client']]));
			$client = $this->makeClient(idToken: $idToken);

			$this->expectException(InvalidIdTokenException::class);
			$client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');
		}

		public function testMultipleAudiencesWithMatchingAzpIsAccepted(): void {
			$claims = $this->validClaims(['aud' => [self::CLIENT_ID, 'another-client'], 'azp' => self::CLIENT_ID]);
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $claims);
			$client = $this->makeClient(idToken: $idToken);

			$result = $client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');

			$this->assertSame(self::CLIENT_ID, $result->getAuthorizedParty());
		}

		public function testMismatchedAzpIsRejected(): void {
			$claims = $this->validClaims(['azp' => 'some-other-client']);
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $claims);
			$client = $this->makeClient(idToken: $idToken);

			$this->expectException(InvalidIdTokenException::class);
			$client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');
		}

		public function testExpiredTokenIsRejected(): void {
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $this->validClaims(['exp' => time() - 1000]));
			$client = $this->makeClient(idToken: $idToken);

			$this->expectException(InvalidIdTokenException::class);
			$client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');
		}

		public function testFutureIssuedAtIsRejected(): void {
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $this->validClaims(['iat' => time() + 1000]));
			$client = $this->makeClient(idToken: $idToken);

			$this->expectException(InvalidIdTokenException::class);
			$client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');
		}

		/**
		 * A future provider authentication time must not satisfy step-up freshness.
		 * @return void
		 */
		public function testFutureAuthTimeIsRejected(): void {
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $this->validClaims(['auth_time' => time() + 3600]));
			$client = $this->makeClient(idToken: $idToken);

			$this->expectException(InvalidIdTokenException::class);
			$this->expectExceptionMessage('auth_time claim is in the future');
			$client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');
		}

		public function testMismatchedNonceIsRejected(): void {
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $this->validClaims(['nonce' => 'wrong-nonce']));
			$client = $this->makeClient(idToken: $idToken);

			$this->expectException(InvalidIdTokenException::class);
			$client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');
		}

		public function testMissingNonceClaimIsRejected(): void {
			$claims = $this->validClaims();
			unset($claims['nonce']);
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $claims);
			$client = $this->makeClient(idToken: $idToken);

			$this->expectException(InvalidIdTokenException::class);
			$client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');
		}

		public function testMissingAuthTimeClaimResultsInNullAuthTime(): void {
			$claims = $this->validClaims();
			unset($claims['auth_time']);
			$idToken = $this->signRs256(self::$privateJwk, self::KID, $claims);
			$client = $this->makeClient(idToken: $idToken);

			$result = $client->exchangeAuthorizationCode('auth-code', 'verifier', 'test-nonce');

			$this->assertNull($result->getAuthTime());
		}
	}
