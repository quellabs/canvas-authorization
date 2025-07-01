<?php
	
	namespace App\Controllers;
	
	use App\Entities\UserEntity;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Controllers\BaseController;
	use Quellabs\Contracts\Templates\TemplateRenderException;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	
	class AuthenticationController extends BaseController {
		
		/**
		 * Display the login form
		 * @Route("/login", methods={"GET"})
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function login(Request $request): Response {
			// If user is already logged in, redirect to home
			if ($this->isLoggedIn($request)) {
				return new RedirectResponse('/');
			}
			
			return $this->render('login.tpl');
		}
		
		/**
		 * Process login form submission
		 * @Route("/login", methods={"POST"})
		 * @InterceptWith(Quellabs\CanvasValidation\ValidateAspect::class, validate=App\Validation\LoginFormValidator::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function processLogin(Request $request): Response {
			// Check if form validation passed
			if (!$request->attributes->get('validation_passed', true)) {
				return $this->showLoginErrors($request->attributes->get('validation_errors', []));
			}
			
			// Find user by username
			$user = $this->findUser($request->get('username'));
			
			// Verify user exists and password is correct
			if (!$user || !$this->checkPassword($request->get('password'), $user)) {
				return $this->showLoginError('Invalid username or password.');
			}
			
			// Set user session
			$this->loginUser($request, $user);
			
			// Redirect to home
			return new RedirectResponse('/');
		}
		
		/**
		 * Log out the current user
		 * @Route("/logout", methods={"POST"})
		 * @param Request $request
		 * @return Response
		 */
		public function logout(Request $request): Response {
			// Clear all session data
			$request->getSession()->clear();
			return new RedirectResponse('/');
		}
		
		/**
		 * Check if user is currently logged in
		 * @param Request $request
		 * @return bool
		 */
		private function isLoggedIn(Request $request): bool {
			return !empty($request->getSession()->get('user_id'));
		}
		
		/**
		 * Find user by username in database
		 * @param string $username
		 * @return UserEntity|null
		 */
		private function findUser(string $username): ?UserEntity {
			$users = $this->em->findBy(UserEntity::class, ['username' => $username]);
			return empty($users) ? null : $users[0];
		}
		
		/**
		 * Verify password against user's stored hash
		 * @param string $password
		 * @param UserEntity $user
		 * @return bool
		 */
		private function checkPassword(string $password, UserEntity $user): bool {
			return password_verify($password, $user->getPassword());
		}
		
		/**
		 * Store user ID in session to log them in
		 * @param Request $request
		 * @param UserEntity $user
		 * @return void
		 */
		private function loginUser(Request $request, UserEntity $user): void {
			$request->getSession()->set('user_id', $user->getId());
		}
		
		/**
		 * Render login template with a single error message
		 * @param string $message
		 * @return Response
		 * @throws TemplateRenderException
		 */
		private function showLoginError(string $message): Response {
			return $this->render('login.tpl', [
				'errors' => ['general' => [[$message]]]
			]);
		}
		
		/**
		 * Render login template with validation errors
		 * @param array $errors
		 * @return Response
		 * @throws TemplateRenderException
		 */
		private function showLoginErrors(array $errors): Response {
			return $this->render('login.tpl', ['errors' => $errors]);
		}
	}