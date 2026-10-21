<?php

	namespace Quellabs\CanvasAuthorization\Identity;

	use Symfony\Component\HttpFoundation\Session\SessionInterface;

	/**
	 * Writes the same session keys SessionAuthenticationAspect reads
	 * (auth_user_id, auth_time, auth_methods), the way the generated
	 * password AuthenticationController's processLogin()/processRegistration()
	 * already do. Factored out so the OIDC callback flow and password login
	 * establish a session identically, down to the session-fixation defense.
	 */
	class OidcLoginSession {

		/**
		 * Regenerate the session ID, clear timestamps left over from any
		 * earlier login on this session, and write the given identity.
		 *
		 * $authTime must be the provider's own verified authentication time
		 * (the ID token's optional 'auth_time' claim) — pass null when the
		 * provider omitted it. Inventing a fallback such as time() would let
		 * StepUpAuthenticationAspect mistake a silent provider session for a
		 * recent authentication.
		 * @param SessionInterface $session
		 * @param int|string $localUserId
		 * @param int|null $authTime Unix timestamp, or null when the provider didn't supply one.
		 * @param string[] $authMethods
		 * @return void
		 */
		public function establish(SessionInterface $session, int|string $localUserId, ?int $authTime, array $authMethods): void {
			// Regenerate the session id before authenticating it, so a
			// pre-login session id fixed by an attacker can't be reused
			$session->migrate(true);

			// Clears AccountEligibilityAspect's re-check clock, so a value
			// left over from a session that predates this login doesn't
			// suppress the first post-login eligibility check
			$session->remove('user_validated_at');

			$session->set('auth_user_id', $localUserId);

			if ($authTime !== null) {
				$session->set('auth_time', $authTime);
			} else {
				$session->remove('auth_time');
			}

			$session->set('auth_methods', $authMethods);
		}
	}
