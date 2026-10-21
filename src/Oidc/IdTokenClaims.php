<?php

	namespace Quellabs\CanvasAuthorization\Oidc;

	/**
	 * Validated claims from a provider's ID token, returned by
	 * OidcClientInterface::exchangeAuthorizationCode() only after signature
	 * verification and full claim validation succeed.
	 *
	 * Deliberately exposes only the claims the protocol itself needs (iss, sub,
	 * aud, azp, exp, iat, auth_time) and never email, name, or other profile
	 * claims. Identity linking is keyed on (issuer, subject) alone; exposing
	 * profile claims here would invite linking by email or display name, which
	 * this package never does.
	 */
	class IdTokenClaims {

		private string $issuer;
		private string $subject;
		private string $audience;
		private ?string $authorizedParty;
		private int $expiresAt;
		private int $issuedAt;
		private ?int $authTime;

		/**
		 * IdTokenClaims constructor
		 * @param string $issuer Validated 'iss' claim.
		 * @param string $subject Validated, non-empty 'sub' claim.
		 * @param string $audience Configured client ID the token was validated against.
		 * @param string|null $authorizedParty Validated 'azp' claim, or null when absent.
		 * @param int $expiresAt Unix timestamp from the 'exp' claim.
		 * @param int $issuedAt Unix timestamp from the 'iat' claim.
		 * @param int|null $authTime Unix timestamp from the optional 'auth_time' claim, or null when absent.
		 */
		public function __construct(
			string  $issuer,
			string  $subject,
			string  $audience,
			?string $authorizedParty,
			int     $expiresAt,
			int     $issuedAt,
			?int    $authTime
		) {
			$this->issuer = $issuer;
			$this->subject = $subject;
			$this->audience = $audience;
			$this->authorizedParty = $authorizedParty;
			$this->expiresAt = $expiresAt;
			$this->issuedAt = $issuedAt;
			$this->authTime = $authTime;
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
		 * @return string
		 */
		public function getAudience(): string {
			return $this->audience;
		}

		/**
		 * @return string|null
		 */
		public function getAuthorizedParty(): ?string {
			return $this->authorizedParty;
		}

		/**
		 * @return int
		 */
		public function getExpiresAt(): int {
			return $this->expiresAt;
		}

		/**
		 * @return int
		 */
		public function getIssuedAt(): int {
			return $this->issuedAt;
		}

		/**
		 * Unix timestamp of the provider's authentication event, from the ID
		 * token's optional 'auth_time' claim. Null when the provider omitted
		 * it — callers must not invent a value in that case, since writing a
		 * fabricated timestamp to the session's auth_time would let
		 * StepUpAuthenticationAspect mistake a silent provider session for a
		 * recent authentication.
		 * @return int|null
		 */
		public function getAuthTime(): ?int {
			return $this->authTime;
		}
	}
