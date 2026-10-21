<?php

	namespace Quellabs\CanvasAuthorization\Tests\Identity;

	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasAuthorization\Identity\OidcLoginSession;
	use Symfony\Component\HttpFoundation\Session\Session;
	use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

	class OidcLoginSessionTest extends TestCase {

		public function testEstablishRegeneratesTheSessionId(): void {
			$storage = new MockArraySessionStorage();
			$session = new Session($storage);
			$session->start();
			$originalId = $session->getId();

			(new OidcLoginSession())->establish($session, 42, time(), []);

			$this->assertNotSame($originalId, $session->getId());
		}

		public function testEstablishWritesTheLocalUserId(): void {
			$session = new Session(new MockArraySessionStorage());

			(new OidcLoginSession())->establish($session, 42, time(), []);

			$this->assertSame(42, $session->get('auth_user_id'));
		}

		public function testEstablishWritesTheProviderVerifiedAuthTimeWhenPresent(): void {
			$session = new Session(new MockArraySessionStorage());
			$authTime = 1700000000;

			(new OidcLoginSession())->establish($session, 42, $authTime, []);

			$this->assertSame($authTime, $session->get('auth_time'));
		}

		public function testEstablishLeavesAuthTimeAbsentWhenTheProviderOmittedIt(): void {
			$session = new Session(new MockArraySessionStorage());
			$session->set('auth_time', 1234567890);

			(new OidcLoginSession())->establish($session, 42, null, []);

			$this->assertFalse($session->has('auth_time'));
		}

		public function testEstablishWritesTheGivenAuthMethods(): void {
			$session = new Session(new MockArraySessionStorage());

			(new OidcLoginSession())->establish($session, 42, time(), []);

			$this->assertSame([], $session->get('auth_methods'));
		}

		public function testEstablishPreservesNonEmptyAuthMethodsWhenGiven(): void {
			$session = new Session(new MockArraySessionStorage());

			(new OidcLoginSession())->establish($session, 42, time(), ['pwd']);

			$this->assertSame(['pwd'], $session->get('auth_methods'));
		}

		public function testEstablishClearsAStaleEligibilityTimestamp(): void {
			$session = new Session(new MockArraySessionStorage());
			$session->set('user_validated_at', time() - 10);

			(new OidcLoginSession())->establish($session, 42, time(), []);

			$this->assertFalse($session->has('user_validated_at'));
		}

		public function testEstablishAcceptsAStringLocalUserId(): void {
			$session = new Session(new MockArraySessionStorage());

			(new OidcLoginSession())->establish($session, 'uuid-abc-123', time(), []);

			$this->assertSame('uuid-abc-123', $session->get('auth_user_id'));
		}
	}
