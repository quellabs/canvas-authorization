<?php

	namespace Quellabs\CanvasAuthorization\Identity;

	/**
	 * Implemented by the application's generated OidcIdentityEntity so
	 * OidcIdentityRepository can read and write it without depending on a
	 * hardcoded App\... class name, the same way AccountEligibilityAspect
	 * depends on AccountEligibilityInterface instead of UserEntity directly.
	 */
	interface OidcIdentityRecordInterface {

		/**
		 * @return int|string
		 */
		public function getLocalUserId(): int|string;

		/**
		 * @param int|string $localUserId
		 * @return static
		 */
		public function setLocalUserId(int|string $localUserId): static;

		/**
		 * @return string
		 */
		public function getIssuer(): string;

		/**
		 * @param string $issuer
		 * @return static
		 */
		public function setIssuer(string $issuer): static;

		/**
		 * @return string
		 */
		public function getSubject(): string;

		/**
		 * @param string $subject
		 * @return static
		 */
		public function setSubject(string $subject): static;

		/**
		 * @return string
		 */
		public function getIssuerKey(): string;

		/**
		 * @param string $issuerKey
		 * @return static
		 */
		public function setIssuerKey(string $issuerKey): static;

		/**
		 * @return string
		 */
		public function getIdentityKey(): string;

		/**
		 * @param string $identityKey
		 * @return static
		 */
		public function setIdentityKey(string $identityKey): static;
	}
