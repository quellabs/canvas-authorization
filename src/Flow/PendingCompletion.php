<?php

	namespace Quellabs\CanvasAuthorization\Flow;

	/**
	 * A single-use, session-bound record of a verified but still-unlinked
	 * (issuer, subject) pair, created at callback time and consumed by the
	 * completion page. Deliberately carries nothing but what the protocol
	 * itself verified — never an email, name, or other profile claim — so
	 * the completion flow can't be tempted to link by them.
	 */
	class PendingCompletion {

		private string $issuer;
		private string $subject;
		private int $issuedAt;
		private ?string $returnPath;

		/**
		 * PendingCompletion constructor
		 * @param string $issuer
		 * @param string $subject
		 * @param int $issuedAt Unix timestamp this record was created.
		 * @param string|null $returnPath Carried over from the originating PendingFlow; null for the default '/'.
		 */
		public function __construct(string $issuer, string $subject, int $issuedAt, ?string $returnPath = null) {
			$this->issuer = $issuer;
			$this->subject = $subject;
			$this->issuedAt = $issuedAt;
			$this->returnPath = $returnPath;
		}

		/**
		 * @return string
		 */
		public function getIssuer(): string {
			return $this->issuer;
		}

		/**
		 * @return string
		 */
		public function getSubject(): string {
			return $this->subject;
		}

		/**
		 * @return int
		 */
		public function getIssuedAt(): int {
			return $this->issuedAt;
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
				'issuer'      => $this->issuer,
				'subject'     => $this->subject,
				'issued_at'   => $this->issuedAt,
				'return_path' => $this->returnPath,
			];
		}

		/**
		 * @param array<array-key, mixed> $data As produced by toArray() and read back from the session.
		 * @return self
		 * @throws \InvalidArgumentException When $data is missing a required field or has the wrong type for one — the session-stored data this reads back is never assumed trustworthy.
		 */
		public static function fromArray(array $data): self {
			$issuer = $data['issuer'] ?? null;
			$subject = $data['subject'] ?? null;
			$issuedAt = $data['issued_at'] ?? null;
			$returnPath = $data['return_path'] ?? null;

			if (!is_string($issuer) || !is_string($subject) || !is_int($issuedAt)) {
				throw new \InvalidArgumentException('Malformed PendingCompletion data.');
			}

			if ($returnPath !== null && !is_string($returnPath)) {
				throw new \InvalidArgumentException('Malformed PendingCompletion data: return_path.');
			}

			return new self($issuer, $subject, $issuedAt, $returnPath);
		}
	}
