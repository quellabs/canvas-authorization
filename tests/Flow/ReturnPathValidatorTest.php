<?php

	namespace Quellabs\CanvasAuthorization\Tests\Flow;

	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasAuthorization\Flow\ReturnPathValidator;

	class ReturnPathValidatorTest extends TestCase {

		/**
		 * @return array<string, array{0: string, 1: bool}>
		 */
		public static function pathProvider(): array {
			return [
				'simple path'                     => ['/dashboard', true],
				'nested path'                      => ['/account/settings', true],
				'path with query string'           => ['/dashboard?tab=billing', true],
				'root path'                         => ['/', true],
				'empty string'                      => ['', false],
				'relative path without leading slash' => ['dashboard', false],
				'protocol-relative URL'             => ['//evil.example.test/phish', false],
				'absolute URL with https scheme'    => ['https://evil.example.test/phish', false],
				'absolute URL with http scheme'     => ['http://evil.example.test/phish', false],
				'backslash-prefixed host bypass'    => ['/\\evil.example.test', false],
				'scheme-relative with userinfo'     => ['/@evil.example.test', true],
				'javascript scheme'                 => ['javascript:alert(1)', false],
			];
		}

		#[DataProvider('pathProvider')]
		public function testIsValid(string $path, bool $expected): void {
			$this->assertSame($expected, (new ReturnPathValidator())->isValid($path));
		}
	}
