<?php
	
	namespace App\Aspects;
	
	use App\Entities\UserEntity;
	use Quellabs\Contracts\AOP\BeforeAspect;
	use Quellabs\Contracts\AOP\MethodContext;
	use Quellabs\ObjectQuel\EntityManager;
	use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Response;
	
	/**
	 * Authentication aspect that intercepts method calls to check if user is authenticated.
	 *
	 * This aspect implements the BeforeAspect interface to run authentication checks
	 * before the target method is executed. It provides session-based authentication
	 * with periodic database validation to ensure users haven't been banned or deleted.
	 *
	 * The aspect uses a two-tier validation approach:
	 * 1. Quick session check for every request
	 * 2. Database validation at configured intervals to verify user status
	 */
	class AuthenticationAspect implements BeforeAspect {
		
		/**
		 * The URL to redirect unauthenticated users to
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
		 * Constructor to initialize the authentication aspect
		 * @param string $redirectTo The URL to redirect to when authentication fails (defaults to "/login")
		 * @param int $validationInterval Time in seconds between database validations (defaults to 300 = 5 minutes)
		 * @param EntityManager|null $entityManager The entity manager for database operations
		 */
		public function __construct(
			string $redirectTo = "/login",
			int $validationInterval = 300,
			EntityManager $entityManager = null
		) {
			$this->redirectTo = $redirectTo;
			$this->validationInterval = $validationInterval;
			$this->entityManager = $entityManager;
		}
		
		/**
		 * Execute authentication check before the target method runs
		 * @param MethodContext $context The context containing request and method information
		 * @return Response|null Returns RedirectResponse if authentication fails, null if authenticated
		 * @throws SessionNotFoundException When session cannot be retrieved from request
		 */
		public function before(MethodContext $context): ?Response {
			// Extract the HTTP request from the method context
			$request = $context->getRequest();
			
			// Get the session from the request to check authentication status
			// This may throw SessionNotFoundException if no session is available
			$session = $request->getSession();
			
			// STAGE 1: Quick session-based authentication check
			// Check if the user is logged in by verifying the presence and value of user_id in session
			if (!$session->has('user_id') || !$session->get('user_id')) {
				// User is not authenticated - redirect them to the login page
				return new RedirectResponse($this->redirectTo);
			}
			
			// STAGE 2: Periodic database validation to ensure user is still valid
			// Only hit the database if we haven't validated recently to improve performance
			$userId = $session->get('user_id');
			$lastValidated = $session->get('user_validated_at', 0); // Default to 0 if never validated
			$currentTime = time();
			
			// Check if enough time has passed since last validation
			if ($currentTime - $lastValidated > $this->validationInterval) {
				// Time to validate user status against the database
				$user = $this->entityManager->find(UserEntity::class, $userId);
				
				// Check if user still exists and is not banned
				if (!$user || $user->isBanned()) {
					// User no longer exists or has been banned - clear session and redirect
					$session->remove('user_id');
					$session->remove('user_validated_at');
					return new RedirectResponse($this->redirectTo);
				}
				
				// User is valid - update the validation timestamp to avoid immediate re-validation
				$session->set('user_validated_at', $currentTime);
			}
			
			// User is authenticated and valid - return null to allow the original method to proceed normally
			return null;
		}
	}