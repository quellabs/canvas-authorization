<?php

	namespace Quellabs\CanvasAuthorization\Oidc;

	use Quellabs\CanvasAuthorization\Oidc\Exceptions\InvalidIdTokenException;
	use Quellabs\CanvasAuthorization\Oidc\Exceptions\OidcDiscoveryException;
	use Quellabs\CanvasAuthorization\Oidc\Exceptions\OidcTokenExchangeException;

	/**
	 * Package-owned boundary around the OIDC Authorization Code flow. Callers
	 * never touch HTTP transport, JOSE verification, or discovery metadata
	 * directly — everything needed to start a login and validate its result
	 * goes through this interface. Implementations do not depend on any
	 * third-party OIDC client library.
	 */
	interface OidcClientInterface {

		/**
		 * Build the provider authorization URL for a fresh login or link attempt.
		 *
		 * Fetches (and caches) the issuer's discovery metadata, validates it,
		 * and generates a fresh PKCE code_verifier/code_challenge pair. The
		 * caller is responsible for generating and persisting $state and
		 * $nonce — together with the returned code verifier — in a single-use,
		 * session-bound flow record, and for sending the browser to the
		 * returned URL.
		 * @param string $state Caller-generated CSRF state value, echoed back by the provider.
		 * @param string $nonce Caller-generated OIDC nonce, validated against the ID token on exchange.
		 * @return AuthorizationRequest
		 * @throws OidcDiscoveryException On invalid, inconsistent, or unreachable issuer metadata.
		 */
		public function createAuthorizationRequest(string $state, string $nonce): AuthorizationRequest;

		/**
		 * Exchange an authorization code for a validated ID token.
		 *
		 * Performs the token-endpoint request using client_secret_basic, then
		 * verifies the returned ID token's signature against the issuer's
		 * JWKS and validates every required claim, including the given
		 * nonce. Any access or refresh token in the provider's response is
		 * discarded; only validated ID-token claims are returned.
		 * @param string $code Authorization code received at the callback.
		 * @param string $codeVerifier PKCE verifier returned by createAuthorizationRequest().
		 * @param string $expectedNonce Nonce passed to createAuthorizationRequest(), validated against the ID token.
		 * @return IdTokenClaims
		 * @throws OidcDiscoveryException On invalid or unreachable issuer metadata/JWKS.
		 * @throws OidcTokenExchangeException On a token-endpoint failure.
		 * @throws InvalidIdTokenException When the ID token is missing, malformed, or fails any validation.
		 */
		public function exchangeAuthorizationCode(string $code, string $codeVerifier, string $expectedNonce): IdTokenClaims;
	}
