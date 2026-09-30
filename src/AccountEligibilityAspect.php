<?php

	namespace Quellabs\CanvasAuthorization;

	use Quellabs\Canvas\AOP\Contracts\BeforeAspectInterface;
	use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
	use Quellabs\CanvasAuthorization\Contracts\AccountEligibilityInterface;
	use Quellabs\ObjectQuel\EntityManager;
	use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Response;

	/**
	 * Periodically re-validates the logged-in user against the database, and
	 * redirects to the login page when there is no session or the user is no
	 * longer valid.
	 *
	 * This aspect does not perform the base session check itself — chain it
	 * after Quellabs\CanvasAuthorization\SessionAuthenticationAspect, which reads
	 * 'auth_user_id' from the session and publishes 'session_user_id' on the
	 * request. This aspect only adds the database-backed, app-specific part:
	 * confirming the user hasn't been banned or deleted since login, at most
	 * once per $validationInterval to avoid a database hit on every request.
	 *
	 * Unlike a scaffolded stub, this class is used directly from the package —
	 * it is not copied into the application, so it receives fixes via
	 * `composer update` rather than being frozen at generation time. Only the
	 * user entity needs to be application-owned, via $userEntityClass and
	 * AccountEligibilityInterface. An application that needs a fundamentally
	 * different revalidation strategy than "banned via an interface method"
	 * should eject a copy with `sculpt make:auth-aspect` and edit it directly.
	 *
	 * @InterceptWith(Quellabs\CanvasAuthorization\SessionAuthenticationAspect::class)
	 * @InterceptWith(Quellabs\CanvasAuthorization\AccountEligibilityAspect::class, userEntityClass=App\Entities\UserEntity::class)
	 */
	class AccountEligibilityAspect implements BeforeAspectInterface {

		/**
		 * Fully qualified class name of the application's user entity.
		 * Must implement AccountEligibilityInterface.
		 * @var class-string<AccountEligibilityInterface>
		 */
		private string $userEntityClass;

		/**
		 * The URL to redirect to when there is no session or the user is no longer valid
		 * @var string
		 */
		private string $redirectTo;

		/**
		 * Time interval (in seconds) between database re-checks of account eligibility
		 * This prevents hitting the database on every request while still ensuring
		 * banned or deleted users are eventually logged out
		 * @var int
		 */
		private int $validationInterval;

		/**
		 * ObjectQuel EntityManager for database operations
		 * Used to fetch and validate user entities from the database
		 * @var EntityManager|null
		 */
		private ?EntityManager $entityManager;

		/**
		 * Constructor to initialize the account eligibility aspect
		 * @param class-string<AccountEligibilityInterface> $userEntityClass The application's user entity class, must implement AccountEligibilityInterface
		 * @param string $redirectTo The URL to redirect to when validation fails (defaults to "/login")
		 * @param int $validationInterval Time in seconds between database validations (defaults to 300 = 5 minutes)
		 * @param EntityManager|null $entityManager The entity manager for database operations
		 */
		public function __construct(
			string $userEntityClass,
			string $redirectTo = "/login",
			int $validationInterval = 300,
			?EntityManager $entityManager = null
		) {
			// Fail at construction rather than on first request — a class that doesn't
			// implement the interface would only surface as an unexplained fatal error
			// deep inside before() the first time a user is actually re-validated
			if (!is_subclass_of($userEntityClass, AccountEligibilityInterface::class)) {
				throw new \InvalidArgumentException(
					"userEntityClass '{$userEntityClass}' must implement " . AccountEligibilityInterface::class . "."
				);
			}

			$this->userEntityClass = $userEntityClass;
			$this->redirectTo = $redirectTo;
			$this->validationInterval = $validationInterval;
			$this->entityManager = $entityManager;
		}

		/**
		 * Redirect if there's no session, otherwise periodically re-validate the user in the database.
		 * @param MethodContextInterface $context The context containing request and method information
		 * @return Response|null Returns RedirectResponse when there is no session or the user is no longer valid, null otherwise
		 * @throws SessionNotFoundException When session cannot be retrieved from request
		 */
		public function before(MethodContextInterface $context): ?Response {
			$request = $context->getRequest();

			// SessionAuthenticationAspect (run earlier in the chain) publishes this
			// attribute only when 'auth_user_id' is present in the session; its
			// absence means there is no logged-in session at all
			$userId = $request->attributes->get('session_user_id');

			if ($userId === null) {
				return new RedirectResponse($this->redirectTo);
			}

			// Only hit the database if we haven't validated recently, to avoid a
			// query on every single request from an already-checked-out user
			$session = $request->getSession();
			$lastValidated = $session->get('user_validated_at', 0);
			$currentTime = time();

			if ($currentTime - $lastValidated > $this->validationInterval) {
				/** @var AccountEligibilityInterface|null $user */
				$user = $this->entityManager->find($this->userEntityClass, $userId);

				if (!$user || $user->isBanned()) {
					// User no longer exists or has been banned - clear the session and redirect
					$session->remove('auth_user_id');
					$session->remove('auth_time');
					$session->remove('auth_methods');
					$session->remove('user_validated_at');
					return new RedirectResponse($this->redirectTo);
				}

				// User is valid - update the validation timestamp to avoid immediate re-validation
				$session->set('user_validated_at', $currentTime);
			}

			return null;
		}
	}
