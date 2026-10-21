<?php

	namespace Quellabs\CanvasAuthorization\Oidc\Exceptions;

	use RuntimeException;

	/**
	 * Thrown when the token-endpoint request fails: a network error, a
	 * non-JSON or malformed response, or a response missing the required
	 * id_token field.
	 */
	class OidcTokenExchangeException extends RuntimeException {
	}
