<?php
	
	namespace App\Controllers;
	
	use App\Entities\UserEntity;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\ObjectQuel\ObjectQuel\QuelException;
	use Quellabs\ObjectQuel\OrmException;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Controllers\BaseController;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Quellabs\Contracts\Templates\TemplateRenderException;
	
	class AuthenticationController extends BaseController {
		
		/**
		 * Display the login form
		 * @Route("/login", methods={"GET"})
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function login(Request $request): Response {
			if (!empty($request->getSession()->get('user_id'))) {
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
			// Check if form validation passed - if not, return to login form with validation errors
			if (!$request->attributes->get('validation_passed', true)) {
				return $this->render('login.tpl', ['errors' => $request->attributes->get('validation_errors', [])]);
			}
			
			// Extract login credentials from the request
			$username = $request->get('username');
			$password = $request->get('password');
			
			// Look up the user by username
			$user = $this->findUser($username);
			
			// Verify user exists and password is correct
			if (!$user || !$this->checkPassword($password, $user)) {
				// Return to login form with generic error message (avoid revealing whether username or password was wrong)
				return $this->render('login.tpl', ['errors' => ['general' => ['Invalid username or password.']]]);
			}
			
			// Authentication successful - store user ID in session
			$request->getSession()->set('user_id', $user->getId());
			
			// Redirect to home page after successful login
			return new RedirectResponse('/');
		}
		
		/**
		 * Display the registration form
		 * @Route("/register", methods={"GET"})
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function registration(): Response {
			return $this->render('registration_form.tpl');
		}
		
		/**
		 * Process registration form submission
		 * @Route("/register", methods={"POST"})
		 * @InterceptWith(Quellabs\CanvasValidation\ValidateAspect::class, validate=App\Validation\RegistrationFormValidator::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException|OrmException
		 */
		public function processRegistration(Request $request): Response {
			// Check if validation passed from the interceptor
			// If validation failed, return to form with validation errors
			if (!$request->attributes->get('validation_passed', true)) {
				return $this->render('registration_form.tpl', ['errors' => $request->attributes->get('validation_errors', [])]);
			}
			
			// Extract form data from the request
			$username = $request->request->get('username');
			$password = $request->request->get('password');
			$confirmPassword = $request->request->get('confirm_password');
			
			// Server-side password confirmation check
			// Ensure both password fields match
			if ($password !== $confirmPassword) {
				return $this->render('registration_form.tpl', ['errors' => ['general' => ['Passwords do not match.']]]);
			}
			
			// Check if username is already taken
			// Query database to see if user exists
			$user = $this->findUser($username);
			if ($user) {
				// Return error if username already exists
				return $this->render('registration_form.tpl', ['errors' => ['general' => ['User already exists.']]]);
			}
			
			// Create new user account
			// This likely handles password hashing and database insertion
			$user = $this->createUser($username, $password);
			
			// Log the user in automatically after successful registration
			// Store user ID in session for authentication
			$request->getSession()->set('user_id', $user->getId());
			
			// Redirect to home page after successful registration
			return new RedirectResponse('/');
		}
		
		/**
		 * Log out the current user
		 * @Route("/logout", methods={"POST"})
		 * @param Request $request
		 * @return Response
		 */
		public function logout(Request $request): Response {
			$request->getSession()->clear();
			return new RedirectResponse('/');
		}
		
		/**
		 * Find user by username in database
		 * @param string $username
		 * @return UserEntity|null
		 */
		private function findUser(string $username): ?UserEntity {
			try {
				$users = $this->em->findBy(UserEntity::class, ['username' => $username]);
				return empty($users) ? null : $users[0];
			} catch (QuelException $e) {
				return null;
			}
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
		 * Create a new user and persist to database
		 * @param string $username
		 * @param string $password
		 * @return UserEntity|null
		 */
		private function createUser(string $username, string $password): ?UserEntity {
			try {
				$user = new UserEntity();
				$user->setUsername($username);
				$user->setPassword(password_hash($password, PASSWORD_DEFAULT));
				
				$this->em->persist($user);
				$this->em->flush();
				
				return $user;
			} catch (OrmException $e) {
				return null;
			}
		}
	}