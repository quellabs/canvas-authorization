<?php

	namespace Quellabs\CanvasAuthorization\Oidc;

	/**
	 * Return value of OidcClientInterface::createAuthorizationRequest(): the URL
	 * to send the browser to, and the PKCE code verifier the caller must persist
	 * alongside state and nonce in order to complete the exchange on callback.
	 */
	class AuthorizationRequest {

		private string $url;
		private string $codeVerifier;

		/**
		 * AuthorizationRequest constructor
		 * @param string $url Provider authorization URL to redirect the browser to.
		 * @param string $codeVerifier PKCE code verifier paired with the challenge sent in $url.
		 */
		public function __construct(string $url, string $codeVerifier) {
			$this->url = $url;
			$this->codeVerifier = $codeVerifier;
		}

		/**
		 * @return string
		 */
		public function getUrl(): string {
			return $this->url;
		}

		/**
		 * @return string
		 */
		public function getCodeVerifier(): string {
			return $this->codeVerifier;
		}
	}
