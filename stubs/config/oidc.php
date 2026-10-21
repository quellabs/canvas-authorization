<?php

	return [
		// Set to true once issuer, client_id, client_secret (in oidc.local.php),
		// and redirect_uri below are all filled in and the provider is registered.
		'enabled' => false,

		// The provider's issuer URL, no trailing slash, e.g.
		// 'https://accounts.example.com'. Must support OIDC discovery at
		// {issuer}/.well-known/openid-configuration over HTTPS.
		'issuer' => '',

		// The client ID registered with the provider for this application.
		'client_id' => '',

		// Exact callback URL registered with the provider:
		// https://your-domain.example/auth/oidc/callback
		'redirect_uri' => '',

		// 'password_and_oidc' offers local password login and OIDC side by
		// side; 'oidc_only' offers only OIDC login.
		'login_mode' => 'password_and_oidc',

		// ID-token signing algorithms this application accepts. Only RS256
		// is supported in this release.
		'allowed_signing_algorithms' => ['RS256'],

		// Set true only for an operator-controlled internal provider whose
		// discovery/JWKS/token endpoints resolve to a private network
		// address. Leave false for any public provider.
		'allow_private_network_endpoints' => false,
	];
