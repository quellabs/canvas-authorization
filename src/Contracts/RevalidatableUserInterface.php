<?php

	namespace Quellabs\CanvasAuthorization\Contracts;

	/**
	 * Implemented by an application's user entity to make it usable with
	 * UserRevalidationAspect's periodic database re-check.
	 */
	interface RevalidatableUserInterface {

		/**
		 * Whether this user has been banned and should be logged out.
		 * @return bool
		 */
		public function isBanned(): bool;
	}
