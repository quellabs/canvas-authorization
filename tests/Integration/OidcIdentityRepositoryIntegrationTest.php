<?php

	namespace Quellabs\CanvasAuthorization\Tests\Integration;

	use App\Entities\OidcIdentityEntity;
	use App\Entities\UserEntity;
	use Quellabs\CanvasAuthorization\Identity\Exceptions\OidcIdentityConflictException;
	use Quellabs\CanvasAuthorization\Identity\OidcIdentityRepository;

	/**
	 * Exercises OidcIdentityRepository against a real MySQL connection and
	 * the shipped OidcIdentityEntity/UserEntity stubs — see
	 * tests/integration-bootstrap.php and phpunit.integration.xml. Run with:
	 *   php ./vendor/bin/phpunit -c phpunit.integration.xml
	 *
	 * This suite is what caught two real bugs neither unit test (mocked
	 * EntityManager) nor code review found: link()'s flush(), when changed
	 * to flush the whole pending unit of work so a caller's separately
	 * persisted user would land in the same transaction, instead left a
	 * failed insert's entity stuck in the unit of work, where an unrelated
	 * later flush() anywhere in the app would retry (and fail) it again;
	 * and the registration flow was calling link() with a brand-new user's
	 * getId() before that user had ever been flushed, which is null/0 for
	 * an identity-strategy primary key until the insert actually runs.
	 */
	class OidcIdentityRepositoryIntegrationTest extends ObjectQuelIntegrationTestCase {

		protected array $truncateTables = ['oidc_identities', 'users'];

		private function repository(): OidcIdentityRepository {
			return new OidcIdentityRepository($this->em, OidcIdentityEntity::class);
		}

		private function createUser(string $username): UserEntity {
			$user = new UserEntity();
			$user->setName('Test User');
			$user->setUsername($username);
			$user->setPassword('irrelevant-hash');

			$this->em->persist($user);
			$this->em->flush($user);

			return $user;
		}

		public function testLinkPersistsARealRowAndFindLocalUserIdResolvesIt(): void {
			$repository = $this->repository();

			$repository->link('https://idp.example.test', 'subject-abc', 42);

			$this->assertSame(42, $repository->findLocalUserId('https://idp.example.test', 'subject-abc'));
		}

		public function testFindLocalUserIdReturnsNullForAnUnknownIdentity(): void {
			$this->assertNull($this->repository()->findLocalUserId('https://idp.example.test', 'subject-unknown'));
		}

		public function testLinkRejectsADuplicateIdentityAgainstTheRealUniqueConstraint(): void {
			$repository = $this->repository();
			$repository->link('https://idp.example.test', 'subject-abc', 42);

			$this->expectException(OidcIdentityConflictException::class);
			$repository->link('https://idp.example.test', 'subject-abc', 99);
		}

		public function testLinkRejectsASecondIdentityFromTheSameIssuerForTheSameUser(): void {
			$repository = $this->repository();
			$repository->link('https://idp.example.test', 'subject-abc', 42);

			$this->expectException(OidcIdentityConflictException::class);
			$repository->link('https://idp.example.test', 'subject-different', 42);
		}

		public function testLinkAllowsTheSameUserToLinkADifferentIssuer(): void {
			$repository = $this->repository();
			$repository->link('https://idp.example.test', 'subject-abc', 42);

			// A provider swap: the same local user links a new issuer
			// without needing the old link removed first
			$repository->link('https://other-idp.example.test', 'subject-xyz', 42);

			$this->assertSame(42, $repository->findLocalUserId('https://idp.example.test', 'subject-abc'));
			$this->assertSame(42, $repository->findLocalUserId('https://other-idp.example.test', 'subject-xyz'));
		}

		public function testAFailedLinkDoesNotLeaveAStaleEntityAffectingAnUnrelatedLaterLink(): void {
			$repository = $this->repository();
			$repository->link('https://idp.example.test', 'subject-abc', 42);

			try {
				// Violates the (local_user_id, issuer_key) constraint
				$repository->link('https://idp.example.test', 'subject-different', 42);
				$this->fail('Expected OidcIdentityConflictException');
			} catch (OidcIdentityConflictException) {
				// expected
			}

			// A completely unrelated link() call must not be affected by
			// the failed entity from the call above
			$repository->link('https://other-idp.example.test', 'subject-unrelated', 7);

			$this->assertSame(7, $repository->findLocalUserId('https://other-idp.example.test', 'subject-unrelated'));
		}

		public function testCreatingANewUserAndLinkingItSucceedsAndCommitsBoth(): void {
			$connection = $this->em->getConnection();
			$connection->beginTrans();

			$user = $this->createUser('new-user@example.test');
			$this->repository()->link('https://idp.example.test', 'subject-abc', $user->getId());

			$connection->commitTrans();

			$this->assertSame($user->getId(), $this->repository()->findLocalUserId('https://idp.example.test', 'subject-abc'));
			$this->assertNotNull($this->em->find(UserEntity::class, $user->getId()));
		}

		public function testCreatingANewUserAndLinkingItRollsBackBothOnConflict(): void {
			$repository = $this->repository();
			$repository->link('https://idp.example.test', 'subject-abc', 42);

			$connection = $this->em->getConnection();
			$connection->beginTrans();

			$user = $this->createUser('conflicting-user@example.test');
			$userId = $user->getId();

			try {
				// Same (issuer, subject) as the existing link above — must fail
				$repository->link('https://idp.example.test', 'subject-abc', $userId);
				$this->fail('Expected OidcIdentityConflictException');
			} catch (OidcIdentityConflictException) {
				$connection->rollbackTrans();

				// Rolling back the DB transaction doesn't by itself clear
				// EntityManager's identity map — without detach(), find()
				// below would return the stale in-memory object instead of
				// reflecting that the row never really exists
				$this->em->detach($user);
			}

			// The new user row must not have survived the rollback, even
			// though its own flush() succeeded before the conflict. Checked
			// both through the identity map (via find(), now that it's been
			// detached) and directly against the database.
			$this->assertNull($this->em->find(UserEntity::class, $userId));

			$row = $connection->getConnection()->execute(
				'SELECT COUNT(*) AS c FROM users WHERE id = :id',
				['id' => $userId]
			)->fetchAssoc();

			$this->assertSame(0, (int)$row['c']);
		}
	}
