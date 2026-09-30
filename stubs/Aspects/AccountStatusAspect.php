<?php

	namespace App\Aspects;

	use App\Entities\UserEntity;
	use Quellabs\Canvas\AOP\Contracts\BeforeAspectInterface;
	use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
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
	 * after Quellabs\Canvas\Security\SessionAuthenticationAspect, which reads
	 * 'auth_user_id' from the session and publishes 'session_user_id' on the
	 * request. This aspect only adds the database-backed, app-specific part:
	 * confirming the user hasn't been banned or deleted since login, at most
	 * once per $validationInterval to avoid a database hit on every request.
	 *
	 * @InterceptWith(Quellabs\Canvas\Security\SessionAuthenticationAspect::class)
	 * @InterceptWith(App\Aspects\AccountStatusAspect::class)
	 */
	class AccountStatusAspect implements BeforeAspectInterface {

		/**
		 * The URL to redirect to when there is no session or the user is no longer valid
		 * @var string
		 */
		private string $redirectTo;

		/**
		 * Time interval (in seconds) between database validations of user status
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
		 * Constructor to initialize the account status aspect
		 * @param string $redirectTo The URL to redirect to when validation fails (defaults to "/login")
		 * @param int $validationInterval Time in seconds between database validations (defaults to 300 = 5 minutes)
		 * @param EntityManager|null $entityManager The entity manager for database operations
		 */
		public function __construct(
			string $redirectTo = "/login",
			int $validationInterval = 300,
			?EntityManager $entityManager = null
		) {
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
				$user = $this->entityManager->find(UserEntity::class, $userId);

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
