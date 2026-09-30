<?php

	namespace Quellabs\CanvasAuthorization\Contracts;

	/**
	 * Implemented by an application's user entity to make it usable with
	 * AccountStatusAspect's periodic database re-check.
	 */
	interface AccountStatusInterface {

		/**
		 * Whether this user has been banned and should be logged out.
		 * @return bool
		 */
		public function isBanned(): bool;
	}
