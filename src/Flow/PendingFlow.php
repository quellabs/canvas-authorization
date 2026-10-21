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
		 * @param array<string, mixed> $data As produced by toArray().
		 * @return self
		 */
		public static function fromArray(array $data): self {
			return new self(
				(string)$data['state'],
				(string)$data['nonce'],
				(string)$data['code_verifier'],
				(int)$data['issued_at'],
				(string)$data['intent'],
				$data['initiating_local_user_id'] ?? null,
				isset($data['password_confirmed_at']) ? (int)$data['password_confirmed_at'] : null,
				isset($data['return_path']) ? (string)$data['return_path'] : null
			);
		}
	}
