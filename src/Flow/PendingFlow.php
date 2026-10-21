<?php

	namespace Quellabs\CanvasAuthorization\Flow;

	/**
	 * A single-use authorization-flow record: the state/nonce/PKCE verifier
	 * OidcClientInterface::createAuthorizationRequest() generated, plus
	 * enough context to validate and act on the callback. Stored and
	 * consumed by OidcFlowStore; never persisted outside the session.
	 */
	class PendingFlow {

		private string $state;
		private string $nonce;
		private string $codeVerifier;
		private int $issuedAt;
		private string $intent;
		private int|string|null $initiatingLocalUserId;
		private ?int $passwordConfirmedAt;
		private ?string $returnPath;

		/**
		 * PendingFlow constructor
		 * @param string $state
		 * @param string $nonce
		 * @param string $codeVerifier
		 * @param int $issuedAt Unix timestamp this flow was started.
		 * @param string $intent 'login' or 'link'.
		 * @param int|string|null $initiatingLocalUserId The session user who started a 'link' flow; null for 'login'.
		 * @param int|null $passwordConfirmedAt Unix timestamp the local password was confirmed; null for 'login'.
		 * @param string|null $returnPath Pre-validated (ReturnPathValidator) relative path to redirect to on success; null for the default '/'.
		 */
		public function __construct(
			string          $state,
			string          $nonce,
			string          $codeVerifier,
			int             $issuedAt,
			string          $intent,
			int|string|null $initiatingLocalUserId = null,
			?int            $passwordConfirmedAt = null,
			?string         $returnPath = null
		) {
			$this->state = $state;
			$this->nonce = $nonce;
			$this->codeVerifier = $codeVerifier;
			$this->issuedAt = $issuedAt;
			$this->intent = $intent;
			$this->initiatingLocalUserId = $initiatingLocalUserId;
			$this->passwordConfirmedAt = $passwordConfirmedAt;
			$this->returnPath = $returnPath;
		}

		/**
		 * @return string
		 */
		public function getState(): string {
			return $this->state;
		}

		/**
		 * @return string
		 */
		public function getNonce(): string {
			return $this->nonce;
		}

		/**
		 * @return string
		 */
		public function getCodeVerifier(): string {
			return $this->codeVerifier;
		}

		/**
		 * @return int
		 */
		public function getIssuedAt(): int {
			return $this->issuedAt;
		}

		/**
		 * @return string
		 */
		public function getIntent(): string {
			return $this->intent;
		}

		/**
		 * @return bool
		 */
		public function isLinkIntent(): bool {
			return $this->intent === 'link';
		}

		/**
		 * @return int|string|null
		 */
		public function getInitiatingLocalUserId(): int|string|null {
			return $this->initiatingLocalUserId;
		}

		/**
		 * @return int|null
		 */
		public function getPasswordConfirmedAt(): ?int {
			return $this->passwordConfirmedAt;
		}

		/**
		 * @return string|null
		 */
		public function getReturnPath(): ?string {
			return $this->returnPath;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function toArray(): array {
			return [
				'state'                     => $this->state,
				'nonce'                     => $this->nonce,
				'code_verifier'             => $this->codeVerifier,
				'issued_at'                 => $this->issuedAt,
				'intent'                    => $this->intent,
				'initiating_local_user_id'  => $this->initiatingLocalUserId,
				'password_confirmed_at'     => $this->passwordConfirmedAt,
				'return_path'               => $this->returnPath,
			];
		}

		/**
		 * @param array<array-key, mixed> $data As produced by toArray() and read back from the session.
		 * @return self
		 * @throws \InvalidArgumentException When $data is missing a required field or has the wrong type for one — the session-stored data this reads back is never assumed trustworthy.
		 */
		public static function fromArray(array $data): self {
			$state = $data['state'] ?? null;
			$nonce = $data['nonce'] ?? null;
			$codeVerifier = $data['code_verifier'] ?? null;
			$issuedAt = $data['issued_at'] ?? null;
			$intent = $data['intent'] ?? null;
			$initiatingLocalUserId = $data['initiating_local_user_id'] ?? null;
			$passwordConfirmedAt = $data['password_confirmed_at'] ?? null;
			$returnPath = $data['return_path'] ?? null;

			if (!is_string($state) || !is_string($nonce) || !is_string($codeVerifier) || !is_int($issuedAt) || !is_string($intent)) {
				throw new \InvalidArgumentException('Malformed PendingFlow data.');
			}

			if ($initiatingLocalUserId !== null && !is_int($initiatingLocalUserId) && !is_string($initiatingLocalUserId)) {
				throw new \InvalidArgumentException('Malformed PendingFlow data: initiating_local_user_id.');
			}

			if ($passwordConfirmedAt !== null && !is_int($passwordConfirmedAt)) {
				throw new \InvalidArgumentException('Malformed PendingFlow data: password_confirmed_at.');
			}

			if ($returnPath !== null && !is_string($returnPath)) {
				throw new \InvalidArgumentException('Malformed PendingFlow data: return_path.');
			}

			return new self($state, $nonce, $codeVerifier, $issuedAt, $intent, $initiatingLocalUserId, $passwordConfirmedAt, $returnPath);
		}
	}
