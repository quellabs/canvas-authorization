<?php

	namespace App\Entities;

	use Quellabs\ObjectQuel\Annotations\Orm\Table;
	use Quellabs\ObjectQuel\Annotations\Orm\Column;
	use Quellabs\ObjectQuel\Annotations\Orm\UniqueIndex;
	use Quellabs\ObjectQuel\Annotations\Orm\PrimaryKeyStrategy;
	use Quellabs\ObjectQuel\Annotations\Orm\LifecycleAware;
	use Quellabs\ObjectQuel\Annotations\Orm\PrePersist;
	use Quellabs\CanvasAuthorization\Identity\OidcIdentityRecordInterface;

	/**
	 * Links a local user to an OIDC (issuer, subject) pair. issuer and
	 * subject are stored exactly and compared exactly on lookup, but the
	 * uniqueness constraints below are enforced on fixed-length SHA-256
	 * hashes of them instead — see OidcIdentityRepository, which is the only
	 * code that computes issuerKey/identityKey or writes this entity.
	 *
	 * uidx_oidc_identities_identity_key is the actual link-uniqueness
	 * constraint: one row per (issuer, subject) pair, system-wide.
	 * uidx_oidc_identities_user_issuer additionally stops two identities
	 * from the same provider linking to one local account, while still
	 * allowing a second link for a *different* issuer — e.g. after a
	 * provider change.
	 *
	 * @Orm\Table(name="oidc_identities")
	 * @Orm\UniqueIndex(name="uidx_oidc_identities_identity_key", columns={"identityKey"})
	 * @Orm\UniqueIndex(name="uidx_oidc_identities_user_issuer", columns={"localUserId", "issuerKey"})
	 * @Orm\LifecycleAware
	 */
	class OidcIdentityEntity implements OidcIdentityRecordInterface {

		/**
		 * @Orm\Column(name="id", type="integer", unsigned=true, primary_key=true)
		 * @Orm\PrimaryKeyStrategy(strategy="identity")
		 */
		protected ?int $id = null;

		/**
		 * The App\Entities\UserEntity this identity resolves to.
		 * @Orm\Column(name="local_user_id", type="integer", unsigned=true, nullable=false)
		 */
		protected int $localUserId;

		/**
		 * Exact issuer URL, from discovery metadata and the ID token's 'iss' claim.
		 * @Orm\Column(name="issuer", type="string", limit=512, nullable=false)
		 */
		protected string $issuer;

		/**
		 * Exact subject ('sub' claim).
		 * @Orm\Column(name="subject", type="string", limit=255, nullable=false)
		 */
		protected string $subject;

		/**
		 * sha256(issuer). Used only in the (local_user_id, issuer_key) index —
		 * never compared on its own; the exact $issuer comparison after
		 * lookup is what actually proves the match.
		 * @Orm\Column(name="issuer_key", type="string", limit=64, nullable=false)
		 */
		protected string $issuerKey;

		/**
		 * sha256(length-prefixed issuer . length-prefixed subject) — the
		 * actual link-uniqueness key.
		 * @Orm\Column(name="identity_key", type="string", limit=64, nullable=false)
		 */
		protected string $identityKey;

		/**
		 * @Orm\Column(name="created_at", type="datetime", nullable=false)
		 */
		protected \DateTime $createdAt;

		/**
		 * Stamps created_at right before the first INSERT.
		 * @Orm\PrePersist
		 */
		public function onPrePersist(): void {
			$this->createdAt = new \DateTime();
		}

		/**
		 * @return int|null
		 */
		public function getId(): ?int {
			return $this->id;
		}

		/**
		 * Gets the localUserId value
		 * @return int|string
		 */
		public function getLocalUserId(): int|string {
			return $this->localUserId;
		}

		/**
		 * Sets the localUserId value
		 * @param int|string $localUserId
		 * @return $this
		 */
		public function setLocalUserId(int|string $localUserId): static {
			$this->localUserId = (int)$localUserId;
			return $this;
		}

		/**
		 * Gets the issuer value
		 * @return string
		 */
		public function getIssuer(): string {
			return $this->issuer;
		}

		/**
		 * Sets the issuer value
		 * @param string $issuer
		 * @return $this
		 */
		public function setIssuer(string $issuer): static {
			$this->issuer = $issuer;
			return $this;
		}

		/**
		 * Gets the subject value
		 * @return string
		 */
		public function getSubject(): string {
			return $this->subject;
		}

		/**
		 * Sets the subject value
		 * @param string $subject
		 * @return $this
		 */
		public function setSubject(string $subject): static {
			$this->subject = $subject;
			return $this;
		}

		/**
		 * Gets the issuerKey value
		 * @return string
		 */
		public function getIssuerKey(): string {
			return $this->issuerKey;
		}

		/**
		 * Sets the issuerKey value
		 * @param string $issuerKey
		 * @return $this
		 */
		public function setIssuerKey(string $issuerKey): static {
			$this->issuerKey = $issuerKey;
			return $this;
		}

		/**
		 * Gets the identityKey value
		 * @return string
		 */
		public function getIdentityKey(): string {
			return $this->identityKey;
		}

		/**
		 * Sets the identityKey value
		 * @param string $identityKey
		 * @return $this
		 */
		public function setIdentityKey(string $identityKey): static {
			$this->identityKey = $identityKey;
			return $this;
		}

		/**
		 * @return \DateTime
		 */
		public function getCreatedAt(): \DateTime {
			return $this->createdAt;
		}
	}
