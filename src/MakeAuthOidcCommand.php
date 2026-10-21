<?php

	namespace Quellabs\CanvasAuthorization;

	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\StubCommand;
	use Quellabs\Support\ComposerUtils;

	/**
	 * Installs OIDC login. Additive: never overwrites an existing
	 * AuthenticationController, UserEntity, or local templates — see
	 * getStubs() for exactly what it copies.
	 *
	 * --mode=password_and_oidc (default) adds OIDC alongside an existing
	 * (or not-yet-installed) password login; run install:auth first if you
	 * want both.
	 *
	 * --mode=oidc_only additionally generates the shared UserEntity,
	 * AuthenticatedController, AuthErrorHandler, UserCreationException, and
	 * an OIDC-only /login + /logout — the same files install:auth would
	 * generate, since an oidc_only application skips install:auth entirely.
	 * Refuses to run when src/Controllers/AuthenticationController.php already exists, so
	 * a combined install never ends up with two controllers both claiming
	 * /login and /logout.
	 */
	class MakeAuthOidcCommand extends StubCommand {

		private const array SUPPORTED_MODES = ['password_and_oidc', 'oidc_only'];

		/**
		 * Maps a template engine name to the file extension its templates use.
		 */
		private const array TEMPLATE_EXTENSIONS = [
			'smarty' => 'tpl',
			'blade'  => 'blade.php',
			'latte'  => 'latte',
			'php'    => 'php',
			'twig'   => 'twig',
		];

		/**
		 * Resolved from --mode once execute() runs; getStubs() reads it.
		 * @var string
		 */
		private string $mode = 'password_and_oidc';

		/**
		 * @return string
		 */
		public function getSignature(): string {
			return "install:auth-oidc";
		}

		/**
		 * @return string
		 */
		public function getDescription(): string {
			return "Install OIDC (OpenID Connect) browser login";
		}

		/**
		 * @param ConfigurationManager $config
		 * @return int Exit code (0 = success, 1 = error)
		 */
		public function execute(ConfigurationManager $config): int {
			$this->mode = $config->getAsString('mode', 'password_and_oidc');

			if (!in_array($this->mode, self::SUPPORTED_MODES, true)) {
				$this->output->error(
					"Unsupported --mode value '{$this->mode}'. Expected one of: " . implode(', ', self::SUPPORTED_MODES) . '.'
				);
				return 1;
			}

			if ($this->mode === 'oidc_only' && $this->passwordControllerExists()) {
				$this->output->error(
					"Refusing an oidc_only install: src/Controllers/AuthenticationController.php already exists. " .
					"That controller and this install's OIDC-only login page would both claim /login and /logout. " .
					"Install OIDC in combined mode instead (the default), link existing users via local sign-in, " .
					"then remove the password routes and switch config/oidc.php's login_mode to 'oidc_only'."
				);
				return 1;
			}

			$this->output->writeLn("<green>Installing OIDC Authentication</green>");
			$this->output->writeLn("");

			$exitCode = parent::execute($config);

			if ($exitCode === 0) {
				$this->showNextSteps();
			}

			return $exitCode;
		}

		/**
		 * Returns token list
		 * @return array|string[]
		 */
		protected function getTokens(): array {
			return array_merge(parent::getTokens(), [
				'{{ template_ext }}' => $this->resolveTemplateExtension(),
				'{{ login_mode }}'   => $this->mode,
			]);
		}

		/**
		 * Resolves the file extension used by the configured template engine.
		 * @return string
		 */
		private function resolveTemplateExtension(): string {
			return self::TEMPLATE_EXTENSIONS[$this->resolveTemplateEngine()] ?? 'tpl';
		}

		/**
		 * @return array<string, string>
		 */
		protected function getStubs(): array {
			$engine = $this->resolveTemplateEngine();
			$ext = $this->resolveTemplateExtension();

			$stubs = [
				'Controllers/OidcAuthenticationController.php' => 'src/Controllers/OidcAuthenticationController.php',
				'Services/OidcLocalAccountService.php'         => 'src/Services/OidcLocalAccountService.php',
				'Entities/OidcIdentityEntity.php'               => 'src/Entities/OidcIdentityEntity.php',
				'config/oidc.php'                               => 'config/oidc.php',
				'config/oidc.local.php'                         => 'config/oidc.local.php',
				"/templates/{$engine}/oidc_complete.{$ext}"     => "templates/oidc_complete.{$ext}",
				"/templates/{$engine}/oidc_error.{$ext}"        => "templates/oidc_error.{$ext}",
			];

			if ($this->mode === 'oidc_only') {
				// Same files install:auth generates — an oidc_only application
				// skips install:auth entirely, so this is their only source.
				// UserCreationException is included here too: OidcLocalAccountService
				// throws it on a failed user flush, and a combined install already
				// gets it from install:auth (a documented prerequisite of that mode)
				$stubs['Entities/UserEntity.php'] = 'src/Entities/UserEntity.php';
				$stubs['Controllers/AuthenticatedController.php'] = 'src/Controllers/AuthenticatedController.php';
				$stubs['Errors/AuthErrorHandler.php'] = 'src/Errors/AuthErrorHandler.php';
				$stubs['Exceptions/UserCreationException.php'] = 'src/Exceptions/UserCreationException.php';
				$stubs['Controllers/OidcOnlyLoginController.php'] = 'src/Controllers/OidcOnlyLoginController.php';
				$stubs["/templates/{$engine}/oidc_only_login.{$ext}"] = "templates/oidc_only_login.{$ext}";
			}

			return $stubs;
		}

		/**
		 * @return bool
		 */
		private function passwordControllerExists(): bool {
			return file_exists(ComposerUtils::getProjectRoot() . '/src/Controllers/AuthenticationController.php');
		}

		/**
		 * @return void
		 */
		private function showNextSteps(): void {
			$this->output->writeLn("");
			$this->output->writeLn("<green>Next Steps:</green>");
			$this->output->writeLn("");
			$this->output->writeLn("1. Register this application with your OIDC provider. Give it this exact");
			$this->output->writeLn("   callback URL (the provider rejects anything that doesn't match exactly):");
			$this->output->writeLn("   <yellow>https://your-domain.example/auth/oidc/callback</yellow>");
			$this->output->writeLn("");
			$this->output->writeLn("2. Fill in config/oidc.php: issuer, client_id, redirect_uri. Leave");
			$this->output->writeLn("   'enabled' => false until the next steps are done.");
			$this->output->writeLn("");
			$this->output->writeLn("3. Put the client secret the provider gave you in config/oidc.local.php —");
			$this->output->writeLn("   never commit that file with a real value.");
			$this->output->writeLn("");
			$this->output->writeLn("4. Generate and run the identity-table migration:");
			$this->output->writeLn("   <yellow>php ./vendor/bin/sculpt make:migrations</yellow>");
			$this->output->writeLn("   <yellow>php ./vendor/bin/sculpt quel:migrate</yellow>");
			$this->output->writeLn("");
			$this->output->writeLn("5. In production, confirm your session cookie is Secure, HttpOnly, and");
			$this->output->writeLn("   SameSite=Lax, and that redirect_uri in config/oidc.php is HTTPS — OidcConfig");
			$this->output->writeLn("   refuses a non-HTTPS redirect_uri unless app.php's debug_mode is true.");
			$this->output->writeLn("");
			$this->output->writeLn("6. Set config/oidc.php's 'enabled' to true, then visit /auth/oidc/start.");

			if ($this->mode === 'password_and_oidc') {
				$this->output->writeLn("");
				$this->output->writeLn("   A new 'Sign in with your organization' button was added to the login");
				$this->output->writeLn("   template generated by install:auth. If your app already had its own");
				$this->output->writeLn("   edited login template, add the button manually — see this package's");
				$this->output->writeLn("   README for the exact markup and the /auth/oidc/start link.");
			}
		}
	}
