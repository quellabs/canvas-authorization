<?php

	namespace Quellabs\CanvasAuthorization\Tests\Oidc;

	use InvalidArgumentException;
	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasAuthorization\Oidc\OidcConfig;
	use Quellabs\CanvasAuthorization\Tests\Fixtures\FakeConfigProvider;
	use RuntimeException;

	class OidcConfigTest extends TestCase {

		/**
		 * @return array<string, mixed>
		 */
		private function validOidcConfig(array $overrides = []): array {
			return array_merge([
				'enabled'                   => true,
				'issuer'                    => 'https://idp.example.test',
				'client_id'                 => 'client-123',
				'client_secret'             => 'super-secret',
				'redirect_uri'              => 'https://app.example.test/auth/oidc/callback',
				'allowed_signing_algorithms' => ['RS256'],
				'login_mode'                => 'password_and_oidc',
			], $overrides);
		}

		private function makeConfig(array $oidcOverrides = [], array $appConfig = []): OidcConfig {
			$configLoader = new FakeConfigProvider([
				'oidc.php' => $this->validOidcConfig($oidcOverrides),
				'app.php'  => $appConfig,
			]);

			return new OidcConfig($configLoader);
		}

		public function testDisabledConfigDoesNotRequireProviderDetails(): void {
			$configLoader = new FakeConfigProvider(['oidc.php' => ['enabled' => false]]);
			$config = new OidcConfig($configLoader);

			$this->assertFalse($config->isEnabled());
		}

		public function testValidEnabledConfigExposesAllValues(): void {
			$config = $this->makeConfig();

			$this->assertTrue($config->isEnabled());
			$this->assertSame('https://idp.example.test', $config->getIssuer());
			$this->assertSame('client-123', $config->getClientId());
			$this->assertSame('super-secret', $config->getClientSecret());
			$this->assertSame('https://app.example.test/auth/oidc/callback', $config->getRedirectUri());
			$this->assertSame(['RS256'], $config->getAllowedSigningAlgorithms());
			$this->assertSame('password_and_oidc', $config->getLoginMode());
			$this->assertFalse($config->allowsPrivateNetworkEndpoints());
		}

		public function testMissingIssuerThrowsWhenEnabled(): void {
			$this->expectException(RuntimeException::class);
			$this->makeConfig(['issuer' => '']);
		}

		public function testMissingClientIdThrowsWhenEnabled(): void {
			$this->expectException(RuntimeException::class);
			$this->makeConfig(['client_id' => '']);
		}

		public function testMissingClientSecretThrowsWhenEnabled(): void {
			$this->expectException(RuntimeException::class);
			$this->makeConfig(['client_secret' => '']);
		}

		public function testMissingRedirectUriThrowsWhenEnabled(): void {
			$this->expectException(RuntimeException::class);
			$this->makeConfig(['redirect_uri' => '']);
		}

		public function testNonHttpsIssuerThrows(): void {
			$this->expectException(InvalidArgumentException::class);
			$this->makeConfig(['issuer' => 'http://idp.example.test']);
		}

		public function testNonHttpsRedirectUriThrowsInProduction(): void {
			$this->expectException(InvalidArgumentException::class);
			$this->makeConfig(['redirect_uri' => 'http://app.example.test/callback'], ['debug_mode' => false]);
		}

		public function testNonHttpsRedirectUriIsAllowedInDebugMode(): void {
			$config = $this->makeConfig(['redirect_uri' => 'http://localhost/callback'], ['debug_mode' => true]);

			$this->assertSame('http://localhost/callback', $config->getRedirectUri());
		}

		public function testUnsupportedLoginModeThrows(): void {
			$this->expectException(InvalidArgumentException::class);
			$this->makeConfig(['login_mode' => 'oidc_and_sms']);
		}

		public function testUnsupportedSigningAlgorithmThrows(): void {
			$this->expectException(InvalidArgumentException::class);
			$this->makeConfig(['allowed_signing_algorithms' => ['HS256']]);
		}

		public function testEmptySigningAlgorithmListThrows(): void {
			$this->expectException(InvalidArgumentException::class);
			$this->makeConfig(['allowed_signing_algorithms' => []]);
		}

		public function testNonArraySigningAlgorithmsThrows(): void {
			$this->expectException(InvalidArgumentException::class);
			$this->makeConfig(['allowed_signing_algorithms' => 'RS256']);
		}

		public function testAllowPrivateNetworkEndpointsDefaultsToFalse(): void {
			$config = $this->makeConfig();

			$this->assertFalse($config->allowsPrivateNetworkEndpoints());
		}

		public function testAllowPrivateNetworkEndpointsCanBeEnabledExplicitly(): void {
			$configLoader = new FakeConfigProvider([
				'oidc.php' => array_merge($this->validOidcConfig(), ['allow_private_network_endpoints' => true]),
			]);

			$config = new OidcConfig($configLoader);

			$this->assertTrue($config->allowsPrivateNetworkEndpoints());
		}
	}
