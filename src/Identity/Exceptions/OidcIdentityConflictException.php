<?php

	namespace Quellabs\CanvasAuthorization\Identity\Exceptions;

	use RuntimeException;

	/**
	 * Thrown by OidcIdentityRepositoryInterface::link() when the identity is
	 * already linked, or a database uniqueness constraint rejects the
	 * insert. Always generic by design — the caller renders it as a single
	 * retry/support message, never a detail that could help an attacker
	 * enumerate which specific constraint was hit.
	 */
	class OidcIdentityConflictException extends RuntimeException {
	}
