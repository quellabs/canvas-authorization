<?php

	namespace Quellabs\CanvasAuthorization\Oidc;

	/**
	 * Resolves a hostname to the IP addresses it points at. OidcClient calls
	 * this before every metadata, JWKS, or token-endpoint request as part of
	 * its SSRF guard, then pins the chosen IP for that request via
	 * symfony/http-client's 'resolve' option.
	 *
	 * Kept separate from symfony/http-client's own DNS resolution so tests can
	 * substitute deterministic IPs instead of depending on real DNS.
	 */
	interface HostResolverInterface {

		/**
		 * Resolve a hostname to its IP addresses. Never called for a literal IP.
		 * @param string $host Hostname to resolve.
		 * @return string[] Resolved IPv4 and/or IPv6 addresses; empty when the host does not resolve.
		 */
		public function resolve(string $host): array;
	}
