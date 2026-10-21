<?php

	namespace App\Controllers;

	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Controllers\BaseController;
	use Quellabs\Canvas\Security\CsrfProtectionAspect;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	use Quellabs\Contracts\Templates\TemplateRenderException;

	/**
	 * Only generated for a fresh oidc_only install, which skips
	 * install:auth entirely — AuthenticationController (and its /login,
	 * /logout) is never generated in that case, so this is the only source
	 * of those two routes. A combined (password_and_oidc) install never
	 * gets this file: AuthenticationController owns /login and /logout
	 * there instead, and install:auth-oidc refuses an oidc_only install
	 * when that controller already exists, precisely to avoid two
	 * controllers both claiming the same routes.
	 */
	class OidcOnlyLoginController extends BaseController {

		/**
		 * Display a login page offering only the OIDC button.
		 * @Route("/login", methods={"GET"})
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function login(Request $request): Response {
			if (!empty($request->getSession()->get('auth_user_id'))) {
				return new RedirectResponse('/');
			}

			return $this->render('oidc_only_login.{{ template_ext }}', [
				'oidcStartUrl' => '/auth/oidc/start',
			]);
		}

		/**
		 * @Route("/logout", methods={"POST"})
		 * @InterceptWith(CsrfProtectionAspect::class, throwOnFailure=true)
		 * @param Request $request
		 * @return Response
		 */
		public function logout(Request $request): Response {
			$session = $request->getSession();

			// invalidate() no-ops without a started session, so a logout
			// with no prior session read would otherwise leave the old id live
			if (!$session->isStarted()) {
				$session->start();
			}

			$session->invalidate();
			return new RedirectResponse('/');
		}
	}
