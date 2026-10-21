<?php

	namespace Quellabs\CanvasAuthorization\Tests\Fixtures;

	use Quellabs\CanvasAuthorization\Oidc\HostResolverInterface;

	/**
	 * Deterministic HostResolverInterface for tests: returns pre-seeded IPs per
	 * host instead of performing a real DNS lookup.
	 */
	class FakeHostResolver implements HostResolverInterface {

		/** @var array<string, string[]> */
		private array $ipsByHost;

		/**
		 * @param array<string, string[]> $ipsByHost Host => resolved IPs. An unlisted host resolves to [].
		 */
		public function __construct(array $ipsByHost) {
			$this->ipsByHost = $ipsByHost;
		}

		/**
		 * @param string $host
		 * @return string[]
		 */
		public function resolve(string $host): array {
			return $this->ipsByHost[$host] ?? [];
		}
	}
