<?php

	// Environment-specific override, merged over config/oidc.php. Never
	// commit this file with a real secret — keep it out of version control
	// the same way database credentials are.
	return [
		// The client secret registered with the provider.
		'client_secret' => '',
	];
