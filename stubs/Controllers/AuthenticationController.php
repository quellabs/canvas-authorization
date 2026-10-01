<?php
	
	namespace App\Controllers;
	
	use App\Entities\PasswordResetTokenEntity;
	use App\Entities\UserEntity;
	use App\Exceptions\UserCreationException;
	use App\Notifiers\LogPasswordResetNotifier;
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
		 * How long a password reset token remains valid after issuance.
		 */
		private const RESET_TOKEN_TTL_SECONDS = 3600;

		/**
		 * Display the login form
		 * @Route("/login", methods={"GET"})
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function login(Request $request): Response {
			if (!empty($request->getSession()->get('auth_user_id'))) {
				return new RedirectResponse('/');
			}
			
			return $this->render('login.{{ template_ext }}', [
				'errors' => []
			]);
		}
		
		/**
		 * Log out the current user
		 * @Route("/logout", methods={"POST", "GET"})
		 * @param Request $request
		 * @return Response
		 */
		public function logout(Request $request): Response {
			$session = $request->getSession();

			// invalidate() regenerates the session id, but silently no-ops
			// if no session has been started yet — explicitly start one
			// first so a logout with no earlier session read still rotates
			// the id rather than leaving the old one live.
			if (!$session->isStarted()) {
				$session->start();
			}

			$session->invalidate();
			return new RedirectResponse('/');
		}
		
		/**
		 * Display the registration form
		 * @Route("/register", methods={"GET"})
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function registration(): Response {
			return $this->render('registration_form.{{ template_ext }}');
		}
		
		/**
		 * Process login form submission
		 * @Route("/login", methods={"POST"})
		 * @InterceptWith(Quellabs\Canvas\Validation\ValidateAspect::class, validator=App\Validation\LoginFormValidator::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function processLogin(Request $request): Response {
			// Check if form validation passed - if not, return to login form with validation errors
			if (!$request->attributes->get('validation_passed', true)) {
				return $this->render('login.{{ template_ext }}', [
					'errors' => $request->attributes->get('validation_errors', [])
				]);
			}
			
			// Extract login credentials from the request
			$username = $request->request->get('username');
			$password = $request->request->get('password');
			
			// Look up the user by username
			$user = $this->findUser($username);
			
			// Verify user exists and password is correct
			if (!$user || !$this->checkPassword($password, $user)) {
				// Return to login form with generic error message (avoid revealing whether username or password was wrong)
				return $this->render('login.{{ template_ext }}', [
					'errors' => [
						'general' => ['Invalid username or password.']
					]
				]);
			}
			
			// Authentication successful - store user ID in session.
			// Regenerate the session id first (session fixation): a session
			// id an attacker fixed before login must not still be valid
			// once this request authenticates it.
			$session = $request->getSession();
			$session->migrate(true);
			$session->set('auth_user_id', $user->getId());

			// Record when and how this credential was proven, so StepUpAuthenticationAspect
			// can require a recent login for sensitive actions elsewhere in the app
			$session->set('auth_time', time());
			$session->set('auth_methods', ['pwd']);

			// Redirect to home page after successful login
			return new RedirectResponse('/');
		}
		
		/**
		 * Process registration form submission
		 * @Route("/register", methods={"POST"})
		 * @InterceptWith(Quellabs\Canvas\Validation\ValidateAspect::class, validator=App\Validation\RegistrationFormValidator::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException|OrmException
		 */
		public function processRegistration(Request $request): Response {
			// Check if validation passed from the interceptor
			// If validation failed, return to form with validation errors
			if (!$request->attributes->get('validation_passed', true)) {
				return $this->render('registration_form.{{ template_ext }}', [
					'errors' => $request->attributes->get('validation_errors', [])
				]);
			}
			
			// Extract form data from the request
			$username = $request->request->get('username');
			$password = $request->request->get('password');
			$confirmPassword = $request->request->get('confirm_password');
			
			// Server-side password confirmation check
			// Ensure both password fields match
			if ($password !== $confirmPassword) {
				return $this->render('registration_form.{{ template_ext }}', [
					'errors' => [
						'general' => ['Passwords do not match.']
					]
				]);
			}
			
			// Check if username is already taken
			// Query database to see if user exists
			//
			// Unlike processLogin()'s generic "Invalid username or password"
			// (which deliberately never confirms whether an account exists),
			// this does confirm it, by design: a registration form has to
			// tell a real user their email is already taken so they can go
			// log in instead, and username/email enumeration via the
			// register endpoint is a much lower-value attack than via login
			// (it doesn't yield a working credential). If your application's
			// threat model needs enumeration resistance on registration too,
			// replace this with a generic message and rely on the
			// confirmation email/forgot-password flow to tell the real owner.
			$user = $this->findUser($username);

			if ($user) {
				// Return error if username already exists
				return $this->render('registration_form.{{ template_ext }}', [
					'errors' => [
						'general' => ['User already exists.']
					]
				]);
			}
			
			try {
				// Create new user account
				// This likely handles password hashing and database insertion
				$user = $this->createUser($username, $password);
				
				// Log the user in automatically after successful registration.
				// Store user ID in session for authentication. Regenerate the
				// session id first, same session-fixation reasoning as processLogin().
				$session = $request->getSession();
				$session->migrate(true);
				$session->set('auth_user_id', $user->getId());

				// Registration includes setting a password, so it's a real credential
				// proof — same auth_time/auth_methods contract as processLogin()
				$session->set('auth_time', time());
				$session->set('auth_methods', ['pwd']);

				// Redirect to home page after successful registration
				return new RedirectResponse('/');
			} catch (UserCreationException $e) {
				return $this->render('registration_form.{{ template_ext }}', [
					'errors' => [
						'general' => ['Registration failed. Please try again.']
					]
				]);
			}
		}

		/**
		 * Display the forgot-password form
		 * @Route("/forgot-password", methods={"GET"})
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function forgotPassword(): Response {
			return $this->render('forgot_password.{{ template_ext }}', [
				'errors'    => [],
				'submitted' => false,
			]);
		}

		/**
		 * Process a forgot-password form submission: issue a reset token
		 * for the account, if one matches, and hand it to the configured
		 * notifier.
		 *
		 * Always renders the same "submitted" response whether or not
		 * $username matched an account — same anti-enumeration reasoning
		 * as processLogin(), but applied here instead of at registration:
		 * telling those apart would let a caller enumerate registered
		 * accounts through this endpoint even if registration itself
		 * doesn't try to hide that.
		 * @Route("/forgot-password", methods={"POST"})
		 * @InterceptWith(Quellabs\Canvas\Validation\ValidateAspect::class, validator=App\Validation\ForgotPasswordFormValidator::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function processForgotPassword(Request $request): Response {
			if (!$request->attributes->get('validation_passed', true)) {
				return $this->render('forgot_password.{{ template_ext }}', [
					'errors'    => $request->attributes->get('validation_errors', []),
					'submitted' => false,
				]);
			}

			$username = $request->request->get('username');
			$user = $this->findUser($username);

			if ($user !== null) {
				$rawToken = bin2hex(random_bytes(32));

				$token = new PasswordResetTokenEntity();
				$token
					->setUserId($user->getId())
					->setTokenHash($this->hashResetToken($rawToken))
					->setExpiresAt(new \DateTime('+' . self::RESET_TOKEN_TTL_SECONDS . ' seconds'));

				$this->em()->persist($token);
				$this->em()->flush();

				// Swap this for a real email-sending implementation of
				// PasswordResetNotifierInterface before going to production —
				// see App\Notifiers\LogPasswordResetNotifier's own docblock.
				(new LogPasswordResetNotifier())->send($user->getUsername(), $rawToken);
			}

			return $this->render('forgot_password.{{ template_ext }}', [
				'errors'    => [],
				'submitted' => true,
			]);
		}

		/**
		 * Display the reset-password form for the token in the query string.
		 * Does not validate the token here — processResetPassword() does
		 * that on submit, so a token that expires between viewing and
		 * submitting the form still gets a clear error instead of a
		 * confusing "page you can't reach".
		 * @Route("/reset-password", methods={"GET"})
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function resetPassword(Request $request): Response {
			return $this->render('reset_password.{{ template_ext }}', [
				'token'  => (string)$request->query->get('token', ''),
				'errors' => [],
			]);
		}

		/**
		 * Process a reset-password form submission: consume the token and,
		 * if it's still valid, set the new password.
		 *
		 * On success, also marks every other outstanding token for the
		 * same account as used — a successful reset ends every reset link
		 * that was in flight, not just the one that was clicked.
		 * @Route("/reset-password", methods={"POST"})
		 * @InterceptWith(Quellabs\Canvas\Validation\ValidateAspect::class, validator=App\Validation\ResetPasswordFormValidator::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function processResetPassword(Request $request): Response {
			$rawToken = (string)$request->request->get('token', '');

			if (!$request->attributes->get('validation_passed', true)) {
				return $this->render('reset_password.{{ template_ext }}', [
					'token'  => $rawToken,
					'errors' => $request->attributes->get('validation_errors', []),
				]);
			}

			$password = $request->request->get('password');
			$confirmPassword = $request->request->get('confirm_password');

			if ($password !== $confirmPassword) {
				return $this->render('reset_password.{{ template_ext }}', [
					'token'  => $rawToken,
					'errors' => ['general' => ['Passwords do not match.']],
				]);
			}

			$resetToken = $this->findValidResetToken($rawToken);

			if ($resetToken === null) {
				return $this->render('reset_password.{{ template_ext }}', [
					'token'  => $rawToken,
					'errors' => ['general' => ['This password reset link is invalid or has expired.']],
				]);
			}

			try {
				$user = $this->em()->find(UserEntity::class, $resetToken->getUserId());
			} catch (OrmException $e) {
				$user = null;
			}

			if ($user === null) {
				return $this->render('reset_password.{{ template_ext }}', [
					'token'  => $rawToken,
					'errors' => ['general' => ['This password reset link is invalid or has expired.']],
				]);
			}

			$user->setPassword(password_hash($password, PASSWORD_DEFAULT));

			$now = new \DateTime();
			$resetToken->setUsedAt($now);

			try {
				$otherTokens = $this->em()->findBy(PasswordResetTokenEntity::class, ['userId' => $user->getId()]);
			} catch (QuelException $e) {
				$otherTokens = [];
			}

			foreach ($otherTokens as $otherToken) {
				if ($otherToken->getUsedAt() === null) {
					$otherToken->setUsedAt($now);
				}
			}

			$this->em()->flush();

			return new RedirectResponse('/login');
		}

		/**
		 * Looks up a password reset token by its raw value and returns it
		 * only when still usable: known, unused, and unexpired.
		 * @param string $rawToken As handed to PasswordResetNotifierInterface::send().
		 * @return PasswordResetTokenEntity|null
		 */
		private function findValidResetToken(string $rawToken): ?PasswordResetTokenEntity {
			try {
				$tokens = $this->em()->findBy(PasswordResetTokenEntity::class, ['tokenHash' => $this->hashResetToken($rawToken)]);
			} catch (QuelException $e) {
				return null;
			}

			$token = $tokens[0] ?? null;

			if ($token === null || $token->getUsedAt() !== null || $token->getExpiresAt() < new \DateTime()) {
				return null;
			}

			return $token;
		}

		/**
		 * @param string $rawToken
		 * @return string
		 */
		private function hashResetToken(string $rawToken): string {
			return hash('sha256', $rawToken);
		}

		/**
		 * Find user by username in database
		 * @param string $username
		 * @return UserEntity|null
		 */
		private function findUser(string $username): ?UserEntity {
			try {
				$users = $this->em()->findBy(UserEntity::class, ['username' => $username]);
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
		 * @return UserEntity
		 * @throws UserCreationException
		 */
		private function createUser(string $username, string $password): UserEntity {
			try {
				$user = new UserEntity();
				$user->setUsername($username);
				$user->setPassword(password_hash($password, PASSWORD_DEFAULT));
				
				$this->em()->persist($user);
				$this->em()->flush();
				
				return $user;
			} catch (OrmException $e) {
				// Log the actual database error for debugging
				error_log("User creation failed: " . $e->getMessage());
				
				// Throw a more specific exception
				throw new UserCreationException("Failed to create user account", 0, $e);
			}
		}
	}