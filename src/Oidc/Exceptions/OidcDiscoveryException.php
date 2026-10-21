<?php

	namespace Quellabs\CanvasAuthorization\Oidc\Exceptions;

	use RuntimeException;

	/**
	 * Thrown when fetching or validating the issuer's discovery metadata or
	 * JWKS fails: a network error, malformed metadata, an issuer mismatch, a
	 * non-HTTPS or blocked (private/reserved network) endpoint, or an
	 * untrusted redirect encountered during the fetch.
	 */
	class OidcDiscoveryException extends RuntimeException {
	}
