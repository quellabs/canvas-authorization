<?php

	namespace Quellabs\CanvasAuthorization\Identity;

	use InvalidArgumentException;
	use Quellabs\CanvasAuthorization\Identity\Exceptions\OidcIdentityConflictException;
	use Quellabs\ObjectQuel\EntityManager;
	use Throwable;

	/**
	 * ObjectQuel-backed OidcIdentityRepositoryInterface implementation.
	 *
	 * Reads and writes the application's generated identity entity
	 * (identityEntityClass, which must implement OidcIdentityRecordInterface)
	 * through a database-enforced unique index on identityKey — a fixed-length
	 * SHA-256 hash of the length-prefixed issuer and subject, computed in
	 * computeIdentityKey(). Hashing keeps the uniqueness index a fixed byte
	 * width regardless of how long a real issuer URL is; length-prefixing
	 * keeps two different (issuer, subject) pairs from ever hashing to the
	 * same value through naive concatenation.
	 *
	 * identityKey only narrows the search to a candidate row — the exact
	 * issuer and subject stored on that row are always compared afterward,
	 * so an astronomically unlikely hash collision can never resolve to the
	 * wrong user.
	 *
	 * The identity table is additive: generate its migration with
	 * `sculpt make:migrations` and apply it with `sculpt quel:migrate`
	 * before setting config/oidc.php's 'enabled' to true. Existing `users`
	 * rows and passwords are untouched either way.
	 *
	 * Replacing the configured issuer or client is a config change plus new
	 * link()s for the new issuer — rows for the old issuer are never
	 * deleted or migrated automatically, and the new issuer's rows are
	 * never populated from them by email, username, or any other claim.
	 * Restoring the original issuer and client makes its preserved links
	 * resolve again immediately, since this repository never deletes a row
	 * on its own.
	 */
	class OidcIdentityRepository implements OidcIdentityRepositoryInterface {

		private EntityManager $entityManager;

		/**
		 * @var class-string<OidcIdentityRecordInterface>
		 */
		private string $identityEntityClass;

		/**
		 * OidcIdentityRepository constructor
		 * @param EntityManager $entityManager
		 * @param class-string<OidcIdentityRecordInterface> $identityEntityClass The application's generated identity entity class. Defaults to the fixed location install:auth-oidc generates it at.
		 * @throws InvalidArgumentException When $identityEntityClass does not implement OidcIdentityRecordInterface.
		 */
		public function __construct(EntityManager $entityManager, string $identityEntityClass = 'App\\Entities\\OidcIdentityEntity') {
			if (!is_subclass_of($identityEntityClass, OidcIdentityRecordInterface::class)) {
				throw new InvalidArgumentException(
					"identityEntityClass '{$identityEntityClass}' must implement " . OidcIdentityRecordInterface::class . "."
				);
			}

			$this->entityManager = $entityManager;
			$this->identityEntityClass = $identityEntityClass;
		}

		/**
		 * @inheritDoc
		 */
		public function findLocalUserId(string $issuer, string $subject): int|string|null {
			$record = $this->findRecord($issuer, $subject);
			return $record?->getLocalUserId();
		}

		/**
		 * @inheritDoc
		 */
		public function link(string $issuer, string $subject, int|string $localUserId): void {
			if ($this->findRecord($issuer, $subject) !== null) {
				throw new OidcIdentityConflictException('This identity is already linked to an account.');
			}

			$entityClass = $this->identityEntityClass;

			/** @var OidcIdentityRecordInterface $record */
			$record = new $entityClass();
			$record
				->setLocalUserId($localUserId)
				->setIssuer($issuer)
				->setSubject($subject)
				->setIssuerKey($this->computeIssuerKey($issuer))
				->setIdentityKey($this->computeIdentityKey($issuer, $subject));

			try {
				$this->entityManager->persist($record);

				// Flush the whole pending unit of work, not just $record: a
				// caller building a new local user alongside this link (see
				// OidcLocalAccountService) persists that entity first without
				// flushing it, so both inserts land in the one transaction
				// UnitOfWork::commit() wraps a flush in. link() called on its
				// own has nothing else pending, so this is equivalent to
				// flushing just $record.
				$this->entityManager->flush();
			} catch (Throwable $e) {
				// Covers both a concurrent insert for the same identity (the
				// identityKey pre-check above lost the race) and a second
				// identity from the same issuer for this local user (the
				// separate (local_user_id, issuer_key) constraint) — either
				// way, the caller gets the same generic, non-leaking message
				throw new OidcIdentityConflictException('This identity could not be linked.', 0, $e);
			}
		}

		/**
		 * Look up the candidate row by identityKey, then require an exact
		 * match on the stored issuer and subject before trusting it.
		 * @param string $issuer
		 * @param string $subject
		 * @return OidcIdentityRecordInterface|null
		 */
		private function findRecord(string $issuer, string $subject): ?OidcIdentityRecordInterface {
			/** @var OidcIdentityRecordInterface|null $record */
			$record = $this->entityManager->findOneBy($this->identityEntityClass, [
				'identityKey' => $this->computeIdentityKey($issuer, $subject),
			]);

			if ($record === null) {
				return null;
			}

			if ($record->getIssuer() !== $issuer || $record->getSubject() !== $subject) {
				return null;
			}

			return $record;
		}

		/**
		 * @param string $issuer
		 * @return string 64-character hexadecimal SHA-256 digest.
		 */
		private function computeIssuerKey(string $issuer): string {
			return hash('sha256', $issuer);
		}

		/**
		 * Length-prefixed so that two different (issuer, subject) pairs can
		 * never hash to the same value through naive concatenation (e.g.
		 * issuer="ab", subject="c" vs. issuer="a", subject="bc").
		 * @param string $issuer
		 * @param string $subject
		 * @return string 64-character hexadecimal SHA-256 digest.
		 */
		private function computeIdentityKey(string $issuer, string $subject): string {
			return hash('sha256', pack('N', strlen($issuer)) . $issuer . pack('N', strlen($subject)) . $subject);
		}
	}
