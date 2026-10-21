<?php

	namespace Quellabs\CanvasAuthorization\Tests\Flow;

	use PHPUnit\Framework\TestCase;
	use Quellabs\CanvasAuthorization\Flow\OidcFlowStore;
	use Quellabs\CanvasAuthorization\Flow\PendingFlow;
	use Symfony\Component\HttpFoundation\Session\Session;
	use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

	class OidcFlowStoreTest extends TestCase {

		private function session(): Session {
			return new Session(new MockArraySessionStorage());
		}

		public function testGenerateTokenProducesDistinctValuesEachCall(): void {
			$store = new OidcFlowStore();
			$this->assertNotSame($store->generateToken(), $store->generateToken());
		}

		public function testConsumeFlowReturnsNullForAnUnknownState(): void {
			$store = new OidcFlowStore();
			$this->assertNull($store->consumeFlow($this->session(), 'unknown-state'));
		}

		public function testStoreThenConsumeFlowReturnsTheSameFlow(): void {
			$store = new OidcFlowStore();
			$session = $this->session();
			$flow = new PendingFlow('state-1', 'nonce-1', 'verifier-1', time(), 'login');

			$store->storeFlow($session, $flow);
			$consumed = $store->consumeFlow($session, 'state-1');

			$this->assertNotNull($consumed);
			$this->assertSame('nonce-1', $consumed->getNonce());
			$this->assertSame('verifier-1', $consumed->getCodeVerifier());
			$this->assertSame('login', $consumed->getIntent());
		}

		public function testConsumeFlowRemovesTheRecordSoAReplayFindsNothing(): void {
			$store = new OidcFlowStore();
			$session = $this->session();
			$store->storeFlow($session, new PendingFlow('state-1', 'nonce-1', 'verifier-1', time(), 'login'));

			$store->consumeFlow($session, 'state-1');
			$replayed = $store->consumeFlow($session, 'state-1');

			$this->assertNull($replayed);
		}

		public function testConsumeFlowReturnsNullForAnExpiredRecordAndStillConsumesIt(): void {
			$store = new OidcFlowStore();
			$session = $this->session();
			$staleFlow = new PendingFlow('state-1', 'nonce-1', 'verifier-1', time() - 601, 'login');
			$store->storeFlow($session, $staleFlow);

			$this->assertNull($store->consumeFlow($session, 'state-1'));
			// Consumed on first touch even though expired - a second call also finds nothing
			$this->assertNull($store->consumeFlow($session, 'state-1'));
		}

		public function testStoreFlowCapsPendingRecordsEvictingTheOldestFirst(): void {
			$store = new OidcFlowStore();
			$session = $this->session();

			for ($i = 1; $i <= 6; $i++) {
				$store->storeFlow($session, new PendingFlow("state-{$i}", "nonce-{$i}", "verifier-{$i}", time(), 'login'));
			}

			// Cap is 5 - the first (oldest) of the 6 stored must have been evicted
			$this->assertNull($store->consumeFlow($session, 'state-1'));
			$this->assertNotNull($store->consumeFlow($session, 'state-6'));
		}

		public function testLinkIntentPreservesInitiatingLocalUserIdAndPasswordConfirmedAt(): void {
			$store = new OidcFlowStore();
			$session = $this->session();
			$flow = new PendingFlow('state-1', 'nonce-1', 'verifier-1', time(), 'link', 42, time());

			$store->storeFlow($session, $flow);
			$consumed = $store->consumeFlow($session, 'state-1');

			$this->assertTrue($consumed->isLinkIntent());
			$this->assertSame(42, $consumed->getInitiatingLocalUserId());
			$this->assertNotNull($consumed->getPasswordConfirmedAt());
		}

		public function testReturnPathRoundTripsThroughAFlowRecord(): void {
			$store = new OidcFlowStore();
			$session = $this->session();
			$flow = new PendingFlow('state-1', 'nonce-1', 'verifier-1', time(), 'login', null, null, '/dashboard');

			$store->storeFlow($session, $flow);
			$consumed = $store->consumeFlow($session, 'state-1');

			$this->assertSame('/dashboard', $consumed->getReturnPath());
		}

		public function testReturnPathRoundTripsThroughACompletionRecord(): void {
			$store = new OidcFlowStore();
			$session = $this->session();
			$store->storeCompletion($session, 'https://idp.example.test', 'subject-abc', '/dashboard');

			$completion = $store->peekCompletion($session);

			$this->assertSame('/dashboard', $completion->getReturnPath());
		}

		public function testPeekCompletionReturnsNullWhenNoneStored(): void {
			$store = new OidcFlowStore();
			$this->assertNull($store->peekCompletion($this->session()));
		}

		public function testStoreThenPeekCompletionReturnsItWithoutConsumingIt(): void {
			$store = new OidcFlowStore();
			$session = $this->session();
			$store->storeCompletion($session, 'https://idp.example.test', 'subject-abc');

			$first = $store->peekCompletion($session);
			$second = $store->peekCompletion($session);

			$this->assertNotNull($first);
			$this->assertSame('https://idp.example.test', $first->getIssuer());
			$this->assertSame('subject-abc', $first->getSubject());
			$this->assertNotNull($second);
		}

		public function testConsumeCompletionRemovesIt(): void {
			$store = new OidcFlowStore();
			$session = $this->session();
			$store->storeCompletion($session, 'https://idp.example.test', 'subject-abc');

			$store->consumeCompletion($session);

			$this->assertNull($store->peekCompletion($session));
		}

		public function testExpiredCompletionRecordReturnsNull(): void {
			$store = new OidcFlowStore();
			$session = $this->session();

			// Seed an expired record directly - storeCompletion() always stamps "now"
			$session->set('oidc_pending_completion', [
				'issuer'    => 'https://idp.example.test',
				'subject'   => 'subject-abc',
				'issued_at' => time() - 601,
			]);

			$this->assertNull($store->peekCompletion($session));
		}

		public function testNewCompletionOverwritesAPreviousUnconsumedOne(): void {
			$store = new OidcFlowStore();
			$session = $this->session();
			$store->storeCompletion($session, 'https://idp.example.test', 'subject-old');
			$store->storeCompletion($session, 'https://idp.example.test', 'subject-new');

			$completion = $store->peekCompletion($session);

			$this->assertSame('subject-new', $completion->getSubject());
		}
	}
