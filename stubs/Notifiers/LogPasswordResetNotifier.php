<?php

	namespace App\Notifiers;

	use App\Contracts\PasswordResetNotifierInterface;

	/**
	 * Default PasswordResetNotifierInterface implementation: writes the
	 * reset link to the PHP error log instead of sending an email.
	 *
	 * This is a placeholder so install:auth produces a working forgot-
	 * password flow out of the box — it is not meant to reach production.
	 * Replace the body below with a real mailer call, and swap the
	 * `new LogPasswordResetNotifier()` call in
	 * AuthenticationController::processForgotPassword() for your
	 * replacement, before deploying.
	 */
	class LogPasswordResetNotifier implements PasswordResetNotifierInterface {

		/**
		 * @param string $username
		 * @param string $rawToken
		 */
		public function send(string $username, string $rawToken): void {
			$resetUrl = '/reset-password?token=' . urlencode($rawToken);

			error_log("Password reset requested for {$username}: {$resetUrl}");
		}
	}
