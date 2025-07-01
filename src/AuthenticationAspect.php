<?php
	
	namespace Quellabs\CanvasAuthorization;
	
	use Quellabs\Contracts\AOP\BeforeAspect;
	use Quellabs\Contracts\AOP\MethodContext;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Response;
	
	/**
	 * Authentication aspect that intercepts method calls to check if user is authenticated.
	 * This aspect implements the BeforeAspect interface to run authentication checks
	 * before the target method is executed.
	 */
	class AuthenticationAspect implements BeforeAspect {
		
		/**
		 * The URL to redirect unauthenticated users to
		 * @var string
		 */
		private string $redirectTo;
		
		/**
		 * Constructor to initialize the redirect URL
		 * @param string $redirectTo The URL to redirect to when authentication fails (defaults to "/login")
		 */
		public function __construct(string $redirectTo = "/login") {
			$this->redirectTo = $redirectTo;
		}
		
		/**
		 * Execute authentication check before the target method runs
		 * @param MethodContext $context The context containing request and method information
		 * @return Response|null Returns RedirectResponse if authentication fails, null if authenticated
		 */
		public function before(MethodContext $context): ?Response {
			// Extract the HTTP request from the method context
			$request = $context->getRequest();
			
			// Get the session from the request to check authentication status
			$session = $request->getSession();
			
			// Check if the user is logged in by verifying the presence and value of user_id in session
			if (!$session->has('user_id') || !$session->get('user_id')) {
				// User is not authenticated - redirect them to the login page
				return new RedirectResponse($this->redirectTo);
			}
			
			// User is authenticated - return null to allow the original method to proceed normally
			return null;
		}
	}