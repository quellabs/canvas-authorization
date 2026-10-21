<?php

	namespace App\Controllers;

	use App\Exceptions\UserCreationException;
	use App\Services\OidcLocalAccountService;
	use Quellabs\Canvas\Annotations\InterceptWith;
	use Quellabs\Canvas\Annotations\Route;
	use Quellabs\Canvas\Controllers\BaseController;
	use Quellabs\Canvas\Security\CsrfProtectionAspect;
	use Quellabs\Canvas\Security\RateLimitAspect;
	use Quellabs\CanvasAuthorization\AccountEligibilityAspect;
	use Quellabs\CanvasAuthorization\Flow\OidcFlowStore;
	use Quellabs\CanvasAuthorization\Flow\PendingFlow;
	use Quellabs\CanvasAuthorization\Flow\ReturnPathValidator;
	use Quellabs\CanvasAuthorization\Identity\Exceptions\OidcIdentityConflictException;
	use Quellabs\CanvasAuthorization\Identity\OidcIdentityRepository;
	use Quellabs\CanvasAuthorization\Identity\OidcIdentityRepositoryInterface;
	use Quellabs\CanvasAuthorization\Identity\OidcLoginSession;
	use Quellabs\CanvasAuthorization\Oidc\Exceptions\InvalidIdTokenException;
	use Quellabs\CanvasAuthorization\Oidc\Exceptions\OidcDiscoveryException;
	use Quellabs\CanvasAuthorization\Oidc\Exceptions\OidcTokenExchangeException;
	use Quellabs\CanvasAuthorization\Oidc\OidcClient;
	use Quellabs\CanvasAuthorization\Oidc\OidcClientInterface;
	use Quellabs\CanvasAuthorization\Oidc\OidcConfig;
	use Quellabs\CanvasAuthorization\SessionAuthenticationAspect;
	use Quellabs\Contracts\Templates\TemplateRenderException;
	use Quellabs\DependencyInjection\Container;
	use Symfony\Component\HttpFoundation\RedirectResponse;
	use Symfony\Component\HttpFoundation\Request;
	use Symfony\Component\HttpFoundation\Response;
	use Symfony\Component\HttpFoundation\Session\SessionInterface;

	/**
	 * Browser-facing OIDC Authorization Code flow. Kept separate from
	 * AuthenticationController so an application can install this without
	 * overwriting local edits to its password login.
	 *
	 * Every method here is thin orchestration over package-owned,
	 * independently-tested services (OidcClientInterface, OidcFlowStore,
	 * OidcIdentityRepositoryInterface, OidcLoginSession) and the
	 * application-owned OidcLocalAccountService — the branching logic that
	 * matters (claim validation, SSRF guarding, flow-record expiry, the
	 * link-intent checks) lives there, not here.
	 *
	 * No code, token, state, nonce, or pending identity is ever written to
	 * a log or reflected into a browser URL after the callback — failures
	 * log only a correlation id and a sanitized reason.
	 */
	class OidcAuthenticationController extends BaseController {

		/**
		 * How long after link/start's password confirmation the callback
		 * still accepts it as fresh, in seconds.
		 */
		private const int LINK_PASSWORD_CONFIRMATION_TTL_SECONDS = 300;

		private OidcClientInterface $oidcClient;
		private OidcConfig $oidcConfig;
		private OidcIdentityRepositoryInterface $identityRepository;
		private OidcLocalAccountService $localAccountService;
		private OidcFlowStore $flowStore;
		private OidcLoginSession $oidcLoginSession;
		private ReturnPathValidator $returnPathValidator;

		/**
		 * OidcAuthenticationController constructor
		 * @param Container $container
		 * @param OidcClient $oidcClient
		 * @param OidcConfig $oidcConfig
		 * @param OidcIdentityRepository $identityRepository
		 * @param OidcLocalAccountService $localAccountService
		 * @param OidcFlowStore|null $flowStore Stateless; defaults to a new instance when DI doesn't supply one.
		 * @param OidcLoginSession|null $oidcLoginSession Stateless; defaults to a new instance when DI doesn't supply one.
		 * @param ReturnPathValidator|null $returnPathValidator Stateless; defaults to a new instance when DI doesn't supply one.
		 */
		public function __construct(
			Container                       $container,
			OidcClient                      $oidcClient,
			OidcConfig                      $oidcConfig,
			OidcIdentityRepository          $identityRepository,
			OidcLocalAccountService         $localAccountService,
			?OidcFlowStore                  $flowStore = null,
			?OidcLoginSession               $oidcLoginSession = null,
			?ReturnPathValidator            $returnPathValidator = null
		) {
			parent::__construct($container);
			$this->oidcClient = $oidcClient;
			$this->oidcConfig = $oidcConfig;
			$this->identityRepository = $identityRepository;
			$this->localAccountService = $localAccountService;
			$this->flowStore = $flowStore ?? new OidcFlowStore();
			$this->oidcLoginSession = $oidcLoginSession ?? new OidcLoginSession();
			$this->returnPathValidator = $returnPathValidator ?? new ReturnPathValidator();
		}

		/**
		 * Start a login attempt: build the provider authorization URL and
		 * redirect the browser to it. Already-authenticated sessions are
		 * sent home instead — use linkStart() to add a provider identity to
		 * an existing session.
		 * @Route("/auth/oidc/start", methods={"GET"})
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function start(Request $request): Response {
			if (($response = $this->disabledResponse()) !== null) {
				return $response;
			}

			$session = $request->getSession();

			if (!empty($session->get('auth_user_id'))) {
				return new RedirectResponse('/');
			}

			return $this->beginAuthorizationRequest($session, 'login', $this->validatedReturnPath($request), null, null);
		}

		/**
		 * Handle the provider's redirect back: validate state, exchange the
		 * code, validate the ID token, then either log the user in (already
		 * linked), create the identity link (explicit link intent), or
		 * hand off to the completion page (unlinked login).
		 * @Route("/auth/oidc/callback", methods={"GET"})
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function callback(Request $request): Response {
			if (($response = $this->disabledResponse()) !== null) {
				return $response;
			}

			$session = $request->getSession();
			$state = (string)$request->query->get('state', '');

			if ($state === '') {
				return $this->renderGenericFailure('missing_state');
			}

			// Consume unconditionally and first: a replay of this state must
			// never find a record, whether the original attempt succeeded,
			// failed, or the provider reported an error
			$flow = $this->flowStore->consumeFlow($session, $state);

			if ($flow === null) {
				return $this->renderGenericFailure('missing_or_expired_state');
			}

			if ($request->query->has('error')) {
				return $this->renderGenericFailure('provider_error: ' . (string)$request->query->get('error', ''));
			}

			$code = (string)$request->query->get('code', '');

			if ($code === '') {
				return $this->renderGenericFailure('missing_code');
			}

			try {
				$claims = $this->oidcClient->exchangeAuthorizationCode($code, $flow->getCodeVerifier(), $flow->getNonce());
			} catch (OidcDiscoveryException|OidcTokenExchangeException|InvalidIdTokenException $e) {
				return $this->renderGenericFailure('token_exchange_failed: ' . $e->getMessage());
			}

			if ($flow->isLinkIntent()) {
				return $this->completeLinkIntent($session, $flow, $claims->getIssuer(), $claims->getSubject(), $claims->getAuthTime());
			}

			$localUserId = $this->identityRepository->findLocalUserId($claims->getIssuer(), $claims->getSubject());

			if ($localUserId !== null) {
				$user = $this->localAccountService->findById($localUserId);

				if ($user === null || $user->isBanned()) {
					return $this->renderGenericFailure('account_not_eligible');
				}

				$this->oidcLoginSession->establish($session, $localUserId, $claims->getAuthTime(), []);
				return $this->redirectToReturnPath($flow->getReturnPath());
			}

			$this->flowStore->storeCompletion($session, $claims->getIssuer(), $claims->getSubject(), $flow->getReturnPath());
			return new RedirectResponse('/auth/oidc/complete');
		}

		/**
		 * Display the completion page: in combined mode, sign in to an
		 * existing local account or register a new one; in OIDC-only mode,
		 * confirm creation of a new local profile.
		 * @Route("/auth/oidc/complete", methods={"GET"})
		 * @InterceptWith(CsrfProtectionAspect::class)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function complete(Request $request): Response {
			if (($response = $this->disabledResponse()) !== null) {
				return $response;
			}

			$completion = $this->flowStore->peekCompletion($request->getSession());

			if ($completion === null) {
				return $this->renderGenericFailure('missing_or_expired_completion');
			}

			return $this->render('oidc_complete.{{ template_ext }}', [
				'errors'    => [],
				'oidcOnly'  => $this->oidcConfig->getLoginMode() === 'oidc_only',
				'csrfToken' => $request->attributes->get('csrf_token'),
			]);
		}

		/**
		 * Combined mode only: sign in to an existing local account and link
		 * the pending identity to it. Rate-limited, since this is a
		 * password check driven by an unauthenticated POST.
		 * @Route("/auth/oidc/complete/existing", methods={"POST"})
		 * @InterceptWith(CsrfProtectionAspect::class, throwOnFailure=true)
		 * @InterceptWith(RateLimitAspect::class, limit=5, window=300, scope="ip", throwOnFailure=true)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function completeWithExistingAccount(Request $request): Response {
			if (($response = $this->disabledResponse()) !== null) {
				return $response;
			}

			if ($this->oidcConfig->getLoginMode() === 'oidc_only') {
				return new Response('Not Found', 404);
			}

			$session = $request->getSession();
			$completion = $this->flowStore->peekCompletion($session);

			if ($completion === null) {
				return $this->renderGenericFailure('missing_or_expired_completion');
			}

			$username = (string)$request->request->get('username', '');
			$password = (string)$request->request->get('password', '');
			$user = $this->localAccountService->verifyLocalCredentials($username, $password);

			if ($user === null) {
				// Generic message: don't reveal whether the username, the
				// password, or account eligibility was the problem. The
				// completion record is only peeked above, never consumed
				// here, so the user can retry until it expires.
				return $this->render('oidc_complete.{{ template_ext }}', [
					'errors'    => ['general' => ['Invalid username or password.']],
					'oidcOnly'  => false,
					'csrfToken' => $request->attributes->get('csrf_token'),
				]);
			}

			// Consume now that credentials are confirmed — this attempt either
			// succeeds from here or fails on a genuine identity conflict, never
			// on something the user could usefully retry
			$this->flowStore->consumeCompletion($session);

			try {
				$this->identityRepository->link($completion->getIssuer(), $completion->getSubject(), $user->getId());
			} catch (OidcIdentityConflictException) {
				return $this->renderGenericFailure('identity_conflict');
			}

			// The local password, just verified above, is the strongest proof
			// of identity in this request — the session reflects that, not
			// whatever auth_time the provider's ID token happened to carry
			$this->oidcLoginSession->establish($session, $user->getId(), time(), ['pwd']);
			return $this->redirectToReturnPath($completion->getReturnPath());
		}

		/**
		 * Combined mode: register a new local account using the same rules
		 * as AuthenticationController::processRegistration(), then link the
		 * pending identity to it. OIDC-only mode: create an opaque profile
		 * on explicit confirmation, no form fields involved. Either way,
		 * the new user and the identity link are created in one transaction
		 * — see OidcLocalAccountService's docblock. The pre-check below is
		 * only a fast path: a concurrent request can still win the race on
		 * UserEntity's unique username index, so the transaction itself must
		 * also handle that failure, not just an identity-link conflict.
		 * @Route("/auth/oidc/complete/register", methods={"POST"})
		 * @InterceptWith(CsrfProtectionAspect::class, throwOnFailure=true)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function completeWithRegistration(Request $request): Response {
			if (($response = $this->disabledResponse()) !== null) {
				return $response;
			}

			$session = $request->getSession();
			$completion = $this->flowStore->peekCompletion($session);

			if ($completion === null) {
				return $this->renderGenericFailure('missing_or_expired_completion');
			}

			$oidcOnly = $this->oidcConfig->getLoginMode() === 'oidc_only';
			$username = '';

			if (!$oidcOnly) {
				// The shared route also serves OIDC-only's no-fields confirmation
				// below, so this uses the same manual field checks
				// AuthenticationController::processRegistration() applies via
				// RegistrationFormValidator/ValidateAspect, rather than that
				// declarative aspect, which can't be conditioned on login_mode
				$errors = $this->validateRegistrationInput($request);

				if ($errors !== []) {
					return $this->render('oidc_complete.{{ template_ext }}', [
						'errors'    => $errors,
						'oidcOnly'  => false,
						'csrfToken' => $request->attributes->get('csrf_token'),
					]);
				}

				$username = (string)$request->request->get('username', '');

				if ($this->localAccountService->usernameExists($username)) {
					return $this->render('oidc_complete.{{ template_ext }}', [
						'errors'    => ['general' => ['User already exists.']],
						'oidcOnly'  => false,
						'csrfToken' => $request->attributes->get('csrf_token'),
					]);
				}
			}

			// Past this point we're committed to either success or a
			// conflict — both consume the completion record per the plan;
			// only the validation failures above, which already returned,
			// leave it for the user to retry
			$this->flowStore->consumeCompletion($session);

			// createLocalAccount()/createOidcOnlyProfile() each flush
			// immediately (their own, independent transaction) so the new
			// user's generated ID is available for link() below. Wrapping
			// both in one explicit transaction here is what actually makes
			// them succeed or fail together — beginTrans()/rollbackTrans()/
			// commitTrans() nest correctly around each call's own flush.
			$connection = $this->em()->getConnection();
			$connection->beginTrans();

			try {
				$user = $oidcOnly
					? $this->localAccountService->createOidcOnlyProfile()
					: $this->localAccountService->createLocalAccount(
						(string)$request->request->get('name', ''),
						$username,
						(string)$request->request->get('password', '')
					);

				$this->identityRepository->link($completion->getIssuer(), $completion->getSubject(), $user->getId());
				$connection->commitTrans();
			} catch (OidcIdentityConflictException) {
				// The identity link lost the race (the user row above was
				// flushed successfully). Rolling back the DB transaction
				// doesn't by itself clear EntityManager's identity map —
				// detach() avoids a request later in the same process (a
				// persistent worker) resolving this user ID to a "ghost"
				// entity that no longer exists in the database.
				$connection->rollbackTrans();

				if (isset($user)) {
					$this->em()->detach($user);
				}

				return $this->renderGenericFailure('identity_conflict');
			} catch (UserCreationException) {
				// The user row itself lost the race (a concurrent request
				// claimed $username first) — createLocalAccount()/
				// createOidcOnlyProfile() already detached their own failed
				// entity, so $user was never assigned here.
				$connection->rollbackTrans();
				return $this->renderGenericFailure('registration_conflict');
			}

			$this->oidcLoginSession->establish($session, $user->getId(), time(), $oidcOnly ? [] : ['pwd']);
			return $this->redirectToReturnPath($completion->getReturnPath());
		}

		/**
		 * Authenticated: start a link attempt that adds a provider identity
		 * to the current session's local account. Requires the local
		 * password to be re-entered in this same request — a provider's
		 * amr=pwd claim is never accepted as proof of it.
		 * @Route("/auth/oidc/link/start", methods={"POST"})
		 * @InterceptWith(SessionAuthenticationAspect::class, throwOnFailure=true)
		 * @InterceptWith(AccountEligibilityAspect::class, userEntityClass=App\Entities\UserEntity::class, throwOnFailure=true)
		 * @InterceptWith(CsrfProtectionAspect::class, throwOnFailure=true)
		 * @InterceptWith(RateLimitAspect::class, limit=5, window=300, scope="ip", throwOnFailure=true)
		 * @param Request $request
		 * @return Response
		 * @throws TemplateRenderException
		 */
		public function linkStart(Request $request): Response {
			if (($response = $this->disabledResponse()) !== null) {
				return $response;
			}

			$session = $request->getSession();
			$userId = $request->attributes->get('session_user_id');
			$user = $this->localAccountService->findById($userId);

			if ($user === null || !password_verify((string)$request->request->get('password', ''), $user->getPassword())) {
				return $this->renderGenericFailure('link_password_confirmation_failed');
			}

			return $this->beginAuthorizationRequest($session, 'link', $this->validatedReturnPath($request), $userId, time());
		}

		/**
		 * Shared by start() and linkStart(): generate state/nonce, build the
		 * provider authorization URL, store the flow record, and redirect.
		 * @param SessionInterface $session
		 * @param string $intent 'login' or 'link'.
		 * @param string|null $returnPath
		 * @param int|string|null $initiatingLocalUserId
		 * @param int|null $passwordConfirmedAt
		 * @return Response
		 * @throws TemplateRenderException
		 */
		private function beginAuthorizationRequest(
			SessionInterface $session,
			string           $intent,
			?string          $returnPath,
			int|string|null  $initiatingLocalUserId,
			?int             $passwordConfirmedAt
		): Response {
			$state = $this->flowStore->generateToken();
			$nonce = $this->flowStore->generateToken();

			try {
				$authorizationRequest = $this->oidcClient->createAuthorizationRequest($state, $nonce);
			} catch (OidcDiscoveryException $e) {
				return $this->renderGenericFailure('discovery_failed: ' . $e->getMessage());
			}

			$this->flowStore->storeFlow($session, new PendingFlow(
				$state,
				$nonce,
				$authorizationRequest->getCodeVerifier(),
				time(),
				$intent,
				$initiatingLocalUserId,
				$passwordConfirmedAt,
				$returnPath
			));

			return new RedirectResponse($authorizationRequest->getUrl());
		}

		/**
		 * The explicit-link branch of callback(): the same local session
		 * user who started the flow must still be the one completing it,
		 * with a still-fresh password confirmation, before a link is ever
		 * created.
		 * @param SessionInterface $session
		 * @param PendingFlow $flow
		 * @param string $issuer
		 * @param string $subject
		 * @param int|null $authTime
		 * @return Response
		 * @throws TemplateRenderException
		 */
		private function completeLinkIntent(SessionInterface $session, PendingFlow $flow, string $issuer, string $subject, ?int $authTime): Response {
			$currentUserId = $session->get('auth_user_id');

			if ($currentUserId === null || (string)$currentUserId !== (string)$flow->getInitiatingLocalUserId()) {
				return $this->renderGenericFailure('link_user_changed');
			}

			$passwordConfirmedAt = $flow->getPasswordConfirmedAt();

			if ($passwordConfirmedAt === null || (time() - $passwordConfirmedAt) > self::LINK_PASSWORD_CONFIRMATION_TTL_SECONDS) {
				return $this->renderGenericFailure('link_password_confirmation_expired');
			}

			$user = $this->localAccountService->findById($currentUserId);

			if ($user === null || $user->isBanned()) {
				return $this->renderGenericFailure('account_not_eligible');
			}

			try {
				$this->identityRepository->link($issuer, $subject, $currentUserId);
			} catch (OidcIdentityConflictException) {
				return $this->renderGenericFailure('identity_conflict');
			}

			// Re-establish rather than switch: $currentUserId is unchanged,
			// this only refreshes the session's freshness bookkeeping
			$this->oidcLoginSession->establish($session, $currentUserId, $authTime, []);
			return $this->redirectToReturnPath($flow->getReturnPath());
		}

		/**
		 * Manual equivalent of RegistrationFormValidator's rules — see
		 * completeWithRegistration()'s docblock for why this can't use the
		 * declarative ValidateAspect here.
		 * @param Request $request
		 * @return array<string, array<int, string>>
		 */
		private function validateRegistrationInput(Request $request): array {
			$errors = [];
			$name = (string)$request->request->get('name', '');
			$username = (string)$request->request->get('username', '');
			$password = (string)$request->request->get('password', '');
			$confirmPassword = (string)$request->request->get('confirm_password', '');

			if ($name === '') {
				$errors['name'][] = 'Name is required.';
			}

			if ($username === '') {
				$errors['username'][] = 'Username is required.';
			} elseif (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
				$errors['username'][] = 'Username must be a valid email address.';
			}

			if ($password === '') {
				$errors['password'][] = 'Password is required.';
			} elseif (strlen($password) < 8) {
				$errors['password'][] = 'Password must be at least 8 characters.';
			}

			if ($confirmPassword === '') {
				$errors['confirm_password'][] = 'Please confirm your password.';
			} elseif ($password !== $confirmPassword) {
				$errors['confirm_password'][] = 'Passwords do not match.';
			}

			return $errors;
		}

		/**
		 * Read and validate an optional ?return_to= query parameter. Invalid
		 * input is silently ignored rather than rejected — it only affects
		 * where a successful login lands, never whether it succeeds.
		 * @param Request $request
		 * @return string|null
		 */
		private function validatedReturnPath(Request $request): ?string {
			$returnTo = $request->query->get('return_to');

			if (!is_string($returnTo) || !$this->returnPathValidator->isValid($returnTo)) {
				return null;
			}

			return $returnTo;
		}

		/**
		 * @param string|null $returnPath Already validated by ReturnPathValidator when non-null.
		 * @return RedirectResponse
		 */
		private function redirectToReturnPath(?string $returnPath): RedirectResponse {
			// Defensive re-validation: session-stored data should already be
			// trustworthy, but the cost of checking again here is trivial
			if ($returnPath !== null && $this->returnPathValidator->isValid($returnPath)) {
				return new RedirectResponse($returnPath);
			}

			return new RedirectResponse('/');
		}

		/**
		 * Gate every OIDC route on the config flag, not just start() — a
		 * pending callback, completion, or link-start left reachable after
		 * OIDC is disabled would let a flow already underway still establish
		 * a session, contrary to the plan's rollback instructions.
		 * @return Response|null A 404 response when disabled, null when enabled.
		 */
		private function disabledResponse(): ?Response {
			return $this->oidcConfig->isEnabled() ? null : new Response('Not Found', 404);
		}

		/**
		 * Render a generic failure page for any provider or protocol error.
		 * $reason is never attacker-reflected into the response — it's
		 * logged, with a correlation id, for operators to correlate with
		 * this response; the user only ever sees the correlation id.
		 * @param string $reason
		 * @return Response
		 * @throws TemplateRenderException
		 */
		private function renderGenericFailure(string $reason): Response {
			$correlationId = bin2hex(random_bytes(8));
			error_log("oidc_failure correlation_id={$correlationId} reason=" . $this->sanitizeForLog($reason));

			return $this->render('oidc_error.{{ template_ext }}', [
				'correlationId' => $correlationId,
			]);
		}

		/**
		 * Strips control characters (log injection) and bounds the length of
		 * a value before it reaches error_log().
		 * @param string $value
		 * @return string
		 */
		private function sanitizeForLog(string $value): string {
			$stripped = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
			return mb_substr($stripped, 0, 200);
		}
	}
