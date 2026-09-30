<?php

	namespace App\Controllers;

	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Controllers\BaseController;

	/**
	 * Base controller for pages that require a logged-in, non-banned user.
	 *
	 * Extend this instead of BaseController to protect a controller's actions.
	 * InterceptWith annotations on a class apply to every method in it and in
	 * every subclass, so individual controllers never need to declare these
	 * two aspects themselves. A genuinely sensitive action (e.g. deleting the
	 * account) can still add a further, stricter check at the method level —
	 * see Quellabs\Canvas\Security\RecentAuthenticationAspect.
	 *
	 * throwOnFailure=true on both: neither aspect builds a Response itself —
	 * install:auth also scaffolds AuthErrorHandler (src/Errors/AuthErrorHandler.php),
	 * which is what actually turns a failure into a redirect to the login page.
	 *
	 * @InterceptWith(Quellabs\CanvasAuthorization\SessionAuthenticationAspect::class, throwOnFailure=true)
	 * @InterceptWith(Quellabs\CanvasAuthorization\AccountEligibilityAspect::class, userEntityClass=App\Entities\UserEntity::class, throwOnFailure=true)
	 */
	abstract class AuthenticatedController extends BaseController {
	}
