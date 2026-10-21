<?php

	namespace Quellabs\CanvasAuthorization\Oidc\Exceptions;

	use RuntimeException;

	/**
	 * Thrown when an ID token fails signature or claim validation: a
	 * malformed structure, an unsupported or substituted algorithm, an
	 * unknown signing key, a bad signature, or an invalid iss/sub/aud/azp/
	 * exp/iat/nonce claim. A missing or invalid ID token can never
	 * authenticate a user.
	 */
	class InvalidIdTokenException extends RuntimeException {
	}
