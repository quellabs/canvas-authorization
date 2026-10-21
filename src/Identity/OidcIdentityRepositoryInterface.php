<?php

	namespace Quellabs\CanvasAuthorization\Identity;

	use Quellabs\CanvasAuthorization\Identity\Exceptions\OidcIdentityConflictException;

	/**
	 * Resolves and creates the link between a local user and an OIDC
	 * (issuer, subject) pair. Identity links are keyed on the exact issuer
	 * and subject alone — never on email, username, or display name.
	 *
	 * An application may replace the default ObjectQuel-backed
	 * implementation through DI; the only requirement is that findLocalUserId()
	 * and link() agree on what counts as the same identity.
	 */
	interface OidcIdentityRepositoryInterface {

		/**
		 * Resolve the local user ID linked to the given (issuer, subject)
		 * pair, or null when no link exists yet.
		 * @param string $issuer
		 * @param string $subject
		 * @return int|string|null
		 */
		public function findLocalUserId(string $issuer, string $subject): int|string|null;

		/**
		 * Create a new link between the given (issuer, subject) pair and a
		 * local user. Never reassigns an existing link — an (issuer, subject)
		 * pair that already resolves to a user (the same one or a different
		 * one) always fails. $localUserId must already be the user's real,
		 * database-assigned ID; a caller creating both a new user and this
		 * link in one transaction must flush the user first.
		 * @param string $issuer
		 * @param string $subject
		 * @param int|string $localUserId
		 * @return void
		 * @throws OidcIdentityConflictException When the identity is already linked, or a database
		 *         uniqueness constraint rejects the insert (including a concurrent insert for the
		 *         same identity, or a second identity from the same issuer for this local user).
		 */
		public function link(string $issuer, string $subject, int|string $localUserId): void;
	}
