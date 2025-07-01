<?php
	
	namespace App\Controllers;
	
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Controllers\BaseController;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class AuthenticationController extends BaseController {
		
		/**
		 * @Route("/login", methods={"GET"})
		 * @return Response
		 */
		public function login(): Response {
			return $this->render("login.tpl");
		}
		
		/**
		 * @Route("/login", methods={"POST"})
		 * @InterceptWith(Quellabs\CanvasValidation\ValidateAspect::class, validate=App\Validation\LoginFormValidator::class)
		 * @return Response
		 */
		public function processLogin(Request $request): Response {
			if (!$request->attributes->get('validation_passed', true)) {
				$errors = $request->attributes->get('validation_errors', []);
				return $this->render('login.tpl', ['errors' => $errors]);
			}
			
			$request->getSession()->set('user_id', 100);
			
			return new RedirectResponse("/");
		}
	}