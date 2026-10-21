<?php

	namespace Quellabs\CanvasAuthorization\Flow;

	use Symfony\Component\HttpFoundation\Session\SessionInterface;
	use Throwable;

	/**
	 * Session-bound storage for the two kinds of single-use OIDC flow state:
	 *
	 * - A PendingFlow per in-flight authorization attempt, keyed by its own
	 *   `state` value so several can be pending at once (e.g. two browser
	 *   tabs). Capped at MAX_PENDING_FLOWS, oldest evicted first, and
	 *   consumed — removed from the session — the moment a callback looks
	 *   one up, whether or not that callback goes on to succeed.
	 *
	 * - A single PendingCompletion describing a verified-but-unlinked
	 *   (issuer, subject) pair, created at callback time for the
	 *   completion page. Only one can be pending at a time; a later
	 *   callback simply overwrites it.
	 *
	 * Both kinds expire after their respective TTL. Nothing here is ever
	 * persisted outside the session.
	 */
	class OidcFlowStore {

		private const string FLOWS_SESSION_KEY = 'oidc_pending_flows';
		private const string COMPLETION_SESSION_KEY = 'oidc_pending_completion';
		private const int FLOW_TTL_SECONDS = 600;
		private const int COMPLETION_TTL_SECONDS = 600;
		private const int MAX_PENDING_FLOWS = 5;

		/**
		 * Cryptographically random, hex-encoded opaque token — used for both
		 * `state` and `nonce`, which have identical entropy requirements.
		 * @return string
		 */
		public function generateToken(): string {
			return bin2hex(random_bytes(32));
		}

		/**
		 * Store a new pending authorization flow, keyed by its own state.
		 * Evicts the oldest pending flow(s) first when over MAX_PENDING_FLOWS,
		 * so repeatedly hitting the start route without ever completing a
		 * flow can't be used to exhaust session storage.
		 * @param SessionInterface $session
		 * @param PendingFlow $flow
		 * @return void
		 */
		public function storeFlow(SessionInterface $session, PendingFlow $flow): void {
			$flows = $this->readFlows($session);
			$flows[$flow->getState()] = $flow->toArray();

			if (count($flows) > self::MAX_PENDING_FLOWS) {
				$flows = array_slice($flows, -self::MAX_PENDING_FLOWS, null, true);
			}

			$session->set(self::FLOWS_SESSION_KEY, $flows);
		}

		/**
		 * Look up and remove the pending flow for $state, so a replay of the
		 * same state — whether this call ends up returning a valid flow or
		 * not — can never find a record to act on again. Returns null for
		 * an unknown, already-consumed, or expired state; the caller cannot
		 * distinguish those cases, which is intentional.
		 * @param SessionInterface $session
		 * @param string $state
		 * @return PendingFlow|null
		 */
		public function consumeFlow(SessionInterface $session, string $state): ?PendingFlow {
			$flows = $this->readFlows($session);

			if (!isset($flows[$state]) || !is_array($flows[$state])) {
				return null;
			}

			$data = $flows[$state];
			unset($flows[$state]);
			$session->set(self::FLOWS_SESSION_KEY, $flows);

			try {
				$flow = PendingFlow::fromArray($data);
			} catch (Throwable) {
				return null;
			}

			if ((time() - $flow->getIssuedAt()) > self::FLOW_TTL_SECONDS) {
				return null;
			}

			return $flow;
		}

		/**
		 * Store the pending completion record, overwriting any previous one.
		 * @param SessionInterface $session
		 * @param string $issuer
		 * @param string $subject
		 * @param string|null $returnPath Carried over from the originating PendingFlow, if any.
		 * @return void
		 */
		public function storeCompletion(SessionInterface $session, string $issuer, string $subject, ?string $returnPath = null): void {
			$session->set(self::COMPLETION_SESSION_KEY, (new PendingCompletion($issuer, $subject, time(), $returnPath))->toArray());
		}

		/**
		 * Read the pending completion record without consuming it, so an
		 * invalid form submission can be corrected and resubmitted until the
		 * record expires. Returns null when absent, malformed, or expired.
		 * @param SessionInterface $session
		 * @return PendingCompletion|null
		 */
		public function peekCompletion(SessionInterface $session): ?PendingCompletion {
			return $this->readValidCompletion($session);
		}

		/**
		 * Read and remove the pending completion record. Call this only on
		 * successful completion or an identity conflict — never on a
		 * recoverable validation failure, which should use peekCompletion()
		 * instead so the user can retry.
		 * @param SessionInterface $session
		 * @return PendingCompletion|null
		 */
		public function consumeCompletion(SessionInterface $session): ?PendingCompletion {
			$completion = $this->readValidCompletion($session);
			$session->remove(self::COMPLETION_SESSION_KEY);
			return $completion;
		}

		/**
		 * Returns whatever array the session held, with no claim about its
		 * inner shape — consumeFlow() and storeFlow() each already check
		 * is_array() on an individual entry before trusting it further.
		 * @param SessionInterface $session
		 * @return array<array-key, mixed>
		 */
		private function readFlows(SessionInterface $session): array {
			$flows = $session->get(self::FLOWS_SESSION_KEY, []);
			return is_array($flows) ? $flows : [];
		}

		/**
		 * @param SessionInterface $session
		 * @return PendingCompletion|null
		 */
		private function readValidCompletion(SessionInterface $session): ?PendingCompletion {
			$data = $session->get(self::COMPLETION_SESSION_KEY);

			if (!is_array($data)) {
				return null;
			}

			try {
				$completion = PendingCompletion::fromArray($data);
			} catch (Throwable) {
				return null;
			}

			if ((time() - $completion->getIssuedAt()) > self::COMPLETION_TTL_SECONDS) {
				return null;
			}

			return $completion;
		}
	}
