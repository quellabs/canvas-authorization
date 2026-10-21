<?php

	namespace Quellabs\CanvasAuthorization\Tests\Identity;

	use InvalidArgumentException;
	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasAuthorization\Identity\Exceptions\OidcIdentityConflictException;
	use Quellabs\CanvasAuthorization\Identity\OidcIdentityRepository;
	use Quellabs\CanvasAuthorization\Tests\Fixtures\FixtureOidcIdentity;
	use Quellabs\ObjectQuel\EntityManager;
	use Quellabs\ObjectQuel\OrmException;

	class OidcIdentityRepositoryTest extends TestCase {

		private function identityKey(string $issuer, string $subject): string {
			return hash('sha256', pack('N', strlen($issuer)) . $issuer . pack('N', strlen($subject)) . $subject);
		}

		private function issuerKey(string $issuer): string {
			return hash('sha256', $issuer);
		}

		public function testConstructorRejectsAnIdentityEntityClassThatDoesNotImplementTheInterface(): void {
			$this->expectException(InvalidArgumentException::class);

			new OidcIdentityRepository($this->createMock(EntityManager::class), \stdClass::class);
		}

		public function testFindLocalUserIdReturnsNullWhenNoRecordExists(): void {
			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->method('findOneBy')->willReturn(null);

			$repository = new OidcIdentityRepository($entityManager, FixtureOidcIdentity::class);

			$this->assertNull($repository->findLocalUserId('https://idp.example.test', 'subject-abc'));
		}

		public function testFindLocalUserIdQueriesByTheComputedIdentityKey(): void {
			$issuer = 'https://idp.example.test';
			$subject = 'subject-abc';

			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->expects($this->once())
				->method('findOneBy')
				->with(FixtureOidcIdentity::class, ['identityKey' => $this->identityKey($issuer, $subject)])
				->willReturn(null);

			$repository = new OidcIdentityRepository($entityManager, FixtureOidcIdentity::class);
			$repository->findLocalUserId($issuer, $subject);
		}

		public function testFindLocalUserIdReturnsTheLocalUserIdOnAnExactMatch(): void {
			$issuer = 'https://idp.example.test';
			$subject = 'subject-abc';

			$record = (new FixtureOidcIdentity())
				->setLocalUserId(42)
				->setIssuer($issuer)
				->setSubject($subject)
				->setIssuerKey($this->issuerKey($issuer))
				->setIdentityKey($this->identityKey($issuer, $subject));

			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->method('findOneBy')->willReturn($record);

			$repository = new OidcIdentityRepository($entityManager, FixtureOidcIdentity::class);

			$this->assertSame(42, $repository->findLocalUserId($issuer, $subject));
		}

		public function testFindLocalUserIdReturnsNullOnAHashCollisionWithAMismatchedIssuerOrSubject(): void {
			// identityKey only narrows the search; a candidate row whose
			// stored issuer/subject don't match exactly must never resolve
			$record = (new FixtureOidcIdentity())
				->setLocalUserId(42)
				->setIssuer('https://different-issuer.example.test')
				->setSubject('subject-abc')
				->setIssuerKey($this->issuerKey('https://different-issuer.example.test'))
				->setIdentityKey($this->identityKey('https://idp.example.test', 'subject-abc'));

			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->method('findOneBy')->willReturn($record);

			$repository = new OidcIdentityRepository($entityManager, FixtureOidcIdentity::class);

			$this->assertNull($repository->findLocalUserId('https://idp.example.test', 'subject-abc'));
		}

		public function testLinkPersistsANewRecordWithTheComputedKeys(): void {
			$issuer = 'https://idp.example.test';
			$subject = 'subject-abc';
			$capturedRecord = null;

			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->method('findOneBy')->willReturn(null);
			$entityManager->expects($this->once())
				->method('persist')
				->with($this->callback(function ($record) use (&$capturedRecord) {
					$capturedRecord = $record;
					return $record instanceof FixtureOidcIdentity;
				}))
				->willReturn(true);
			$entityManager->expects($this->once())->method('flush');

			$repository = new OidcIdentityRepository($entityManager, FixtureOidcIdentity::class);
			$repository->link($issuer, $subject, 42);

			$this->assertNotNull($capturedRecord);
			$this->assertSame(42, $capturedRecord->getLocalUserId());
			$this->assertSame($issuer, $capturedRecord->getIssuer());
			$this->assertSame($subject, $capturedRecord->getSubject());
			$this->assertSame($this->issuerKey($issuer), $capturedRecord->getIssuerKey());
			$this->assertSame($this->identityKey($issuer, $subject), $capturedRecord->getIdentityKey());
		}

		public function testLinkRejectsWhenTheIdentityIsAlreadyLinked(): void {
			$issuer = 'https://idp.example.test';
			$subject = 'subject-abc';

			$existing = (new FixtureOidcIdentity())
				->setLocalUserId(1)
				->setIssuer($issuer)
				->setSubject($subject)
				->setIssuerKey($this->issuerKey($issuer))
				->setIdentityKey($this->identityKey($issuer, $subject));

			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->method('findOneBy')->willReturn($existing);
			$entityManager->expects($this->never())->method('persist');

			$repository = new OidcIdentityRepository($entityManager, FixtureOidcIdentity::class);

			$this->expectException(OidcIdentityConflictException::class);
			$repository->link($issuer, $subject, 2);
		}

		public function testLinkNeverReassignsAnExistingLinkEvenToTheSameLocalUserId(): void {
			$issuer = 'https://idp.example.test';
			$subject = 'subject-abc';

			$existing = (new FixtureOidcIdentity())
				->setLocalUserId(42)
				->setIssuer($issuer)
				->setSubject($subject)
				->setIssuerKey($this->issuerKey($issuer))
				->setIdentityKey($this->identityKey($issuer, $subject));

			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->method('findOneBy')->willReturn($existing);
			$entityManager->expects($this->never())->method('persist');

			$repository = new OidcIdentityRepository($entityManager, FixtureOidcIdentity::class);

			$this->expectException(OidcIdentityConflictException::class);
			$repository->link($issuer, $subject, 42);
		}

		public function testLinkWrapsAConcurrentInsertConflictFromFlush(): void {
			$entityManager = $this->createMock(EntityManager::class);
			$entityManager->method('findOneBy')->willReturn(null);
			$entityManager->method('persist')->willReturn(true);
			$entityManager->method('flush')->willThrowException(new OrmException('Duplicate entry'));

			$repository = new OidcIdentityRepository($entityManager, FixtureOidcIdentity::class);

			$this->expectException(OidcIdentityConflictException::class);
			$repository->link('https://idp.example.test', 'subject-abc', 42);
		}

		public function testDifferentIssuerAndSubjectLengthsProduceDifferentIdentityKeys(): void {
			// Length-prefixing: naive concatenation would make these collide
			$this->assertNotSame(
				$this->identityKey('ab', 'c'),
				$this->identityKey('a', 'bc')
			);
		}
	}
