<?php

	namespace App\Services;

	use App\Entities\UserEntity;
	use App\Exceptions\UserCreationException;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	use Throwable;

	/**
	 * Bridges the package's OIDC protocol layer to this application's own
	 * UserEntity. OidcAuthenticationController never touches UserEntity
	 * directly — every local-account read or write goes through here, so
	 * adapting this file is enough to support a differently-shaped user
	 * entity without touching the controller.
	 *
	 * createLocalAccount() and createOidcOnlyProfile() flush immediately,
	 * so the returned entity's generated ID is available right away —
	 * OidcIdentityRepositoryInterface::link() needs that real ID, not a
	 * pending one. To make the new user and its identity link succeed or
	 * fail together, wrap both calls in one transaction:
	 * $this->em()->getConnection()->beginTrans()/commitTrans()/rollbackTrans()
	 * (these nest correctly around the flush each call performs on its own).
	 */
	class OidcLocalAccountService {

		private EntityManager $entityManager;

		/**
		 * OidcLocalAccountService constructor
		 * @param EntityManager $entityManager
		 */
		public function __construct(EntityManager $entityManager) {
			$this->entityManager = $entityManager;
		}

		/**
		 * Verify a local username/password pair for the combined-mode
		 * existing-account completion flow. Returns null on any mismatch —
		 * wrong username, wrong password, or a banned account — without
		 * distinguishing which, the same way the generated password
		 * AuthenticationController's processLogin() does.
		 * @param string $username
		 * @param string $password
		 * @return UserEntity|null
		 */
		public function verifyLocalCredentials(string $username, string $password): ?UserEntity {
			$user = $this->findByUsername($username);

			if ($user === null || !password_verify($password, $user->getPassword())) {
				return null;
			}

			if ($user->isBanned()) {
				return null;
			}

			return $user;
		}

		/**
		 * Create a new local user for the combined-mode registration
		 * completion flow. Does not check for an existing username — the
		 * caller is expected to have done that already, the same way
		 * AuthenticationController::processRegistration() does.
		 * @param string $name
		 * @param string $username
		 * @param string $password
		 * @return UserEntity Flushed — getId() returns the real, database-assigned ID.
		 * @throws UserCreationException If the flush fails, e.g. a concurrent registration won the race on UserEntity's unique username index.
		 */
		public function createLocalAccount(string $name, string $username, string $password): UserEntity {
			$user = new UserEntity();
			$user->setName($name);
			$user->setUsername($username);
			$user->setPassword(password_hash($password, PASSWORD_DEFAULT));

			return $this->persistAndFlush($user);
		}

		/**
		 * Create an OIDC-only profile: an opaque, unique username and an
		 * unguessable random password hash that is never shown or accepted
		 * as a credential. The resulting account is usable only through its
		 * linked OIDC identity until a separate verified password-setup
		 * flow is completed.
		 * @return UserEntity Flushed — getId() returns the real, database-assigned ID.
		 * @throws UserCreationException If the flush fails, e.g. a colliding opaque username (astronomically unlikely, but handled the same way as createLocalAccount()).
		 */
		public function createOidcOnlyProfile(): UserEntity {
			$user = new UserEntity();
			$user->setName('OIDC user');
			$user->setUsername($this->generateOpaqueUsername());
			$user->setPassword(password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT));

			return $this->persistAndFlush($user);
		}

		/**
		 * Persist and flush a new user, converting a failed flush (most
		 * notably a concurrent request winning the race on UserEntity's
		 * unique username index) into a UserCreationException instead of
		 * letting the underlying ORM exception escape.
		 * @param UserEntity $user
		 * @return UserEntity Flushed — getId() returns the real, database-assigned ID.
		 * @throws UserCreationException
		 */
		private function persistAndFlush(UserEntity $user): UserEntity {
			try {
				$this->entityManager->persist($user);
				$this->entityManager->flush($user);
			} catch (Throwable $e) {
				// A failed flush leaves the entity scheduled in the unit of
				// work rather than clearing it — left alone, it would be
				// retried (and fail again) on the next unrelated flush() this
				// EntityManager performs anywhere in the application, the same
				// reasoning OidcIdentityRepository::link() applies to its record
				$this->detachQuietly($user);
				throw new UserCreationException('Failed to create user account.', 0, $e);
			}

			return $user;
		}

		/**
		 * @param UserEntity $user
		 * @return void
		 */
		private function detachQuietly(UserEntity $user): void {
			try {
				$this->entityManager->detach($user);
			} catch (Throwable) {
				// Best-effort cleanup only — the UserCreationException already
				// being thrown is what matters to the caller
			}
		}

		/**
		 * Look up a local user by id, for an eligibility (banned) check.
		 * @param int|string $localUserId
		 * @return UserEntity|null
		 */
		public function findById(int|string $localUserId): ?UserEntity {
			try {
				return $this->entityManager->find(UserEntity::class, $localUserId);
			} catch (QuelException) {
				return null;
			}
		}

		/**
		 * Whether $username is already taken.
		 * @param string $username
		 * @return bool
		 */
		public function usernameExists(string $username): bool {
			return $this->findByUsername($username) !== null;
		}

		/**
		 * @return string
		 */
		private function generateOpaqueUsername(): string {
			return 'oidc_' . bin2hex(random_bytes(16));
		}

		/**
		 * @param string $username
		 * @return UserEntity|null
		 */
		private function findByUsername(string $username): ?UserEntity {
			try {
				$users = $this->entityManager->findBy(UserEntity::class, ['username' => $username]);
				return $users[0] ?? null;
			} catch (QuelException) {
				return null;
			}
		}
	}
