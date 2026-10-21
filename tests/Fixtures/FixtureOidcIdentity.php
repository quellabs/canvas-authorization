<?php

	namespace Quellabs\CanvasAuthorization\Tests\Fixtures;

	use Quellabs\CanvasAuthorization\Identity\OidcIdentityRecordInterface;

	/**
	 * Minimal OidcIdentityRecordInterface implementation for
	 * OidcIdentityRepositoryTest — OidcIdentityRepository's constructor
	 * validates $identityEntityClass via is_subclass_of(), so a real class
	 * is needed rather than a mock of the interface alone.
	 */
	class FixtureOidcIdentity implements OidcIdentityRecordInterface {

		private int|string $localUserId;
		private string $issuer;
		private string $subject;
		private string $issuerKey;
		private string $identityKey;

		public function getLocalUserId(): int|string {
			return $this->localUserId;
		}

		public function setLocalUserId(int|string $localUserId): static {
			$this->localUserId = $localUserId;
			return $this;
		}

		public function getIssuer(): string {
			return $this->issuer;
		}

		public function setIssuer(string $issuer): static {
			$this->issuer = $issuer;
			return $this;
		}

		public function getSubject(): string {
			return $this->subject;
		}

		public function setSubject(string $subject): static {
			$this->subject = $subject;
			return $this;
		}

		public function getIssuerKey(): string {
			return $this->issuerKey;
		}

		public function setIssuerKey(string $issuerKey): static {
			$this->issuerKey = $issuerKey;
			return $this;
		}

		public function getIdentityKey(): string {
			return $this->identityKey;
		}

		public function setIdentityKey(string $identityKey): static {
			$this->identityKey = $identityKey;
			return $this;
		}
	}
