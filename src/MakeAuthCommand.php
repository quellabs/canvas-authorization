<?php
	
	namespace Quellabs\CanvasAuthorization;
	
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\StubCommand;
	
	class MakeAuthCommand extends StubCommand {
		
		/**
		 * Returns token list
		 * @return array|string[]
		 */
		protected function getTokens(): array {
			$templateExtensions = [
				'smarty' => 'tpl',
				'blade'  => 'blade.php',
				'latte'  => 'latte',
				'php'    => 'php',
				'twig'   => 'twig',
			];
			
			$ext = $templateExtensions[$this->resolveTemplateEngine()] ?? 'tpl';
			
			return array_merge(parent::getTokens(), [
				'{{ template_ext }}' => $ext,
			]);
		}
		
		/**
		 * Returns the signature of this command
		 * @return string
		 */
		public function getSignature(): string {
			return "install:auth";
		}
		
		/**
		 * Returns a brief description of what this command is for
		 * @return string
		 */
		public function getDescription(): string {
			return "Install authentication system with login, registration, and user management";
		}
		
		/**
		 * Return stubs to copy: stub path (relative to package stubs/) => target path (relative to project root)
		 * @return array<string, string>
		 */
		protected function getStubs(): array {
			$engine = $this->resolveTemplateEngine();
			
			$templateExtensions = [
				'smarty' => 'tpl',
				'blade'  => 'blade.php',
				'latte'  => 'latte',
				'php'    => 'php',
				'twig'   => 'twig',
			];
			
			$ext = $templateExtensions[$engine] ?? 'tpl';
			
			return [
				'Controllers/AuthenticationController.php'     => 'src/Controllers/AuthenticationController.php',
				'Controllers/AuthenticatedController.php'      => 'src/Controllers/AuthenticatedController.php',
				'Validation/LoginFormValidator.php'            => 'src/Validation/LoginFormValidator.php',
				'Validation/RegistrationFormValidator.php'     => 'src/Validation/RegistrationFormValidator.php',
				'Entities/UserEntity.php'                      => 'src/Entities/UserEntity.php',
				'Exceptions/UserCreationException.php'         => 'src/Exceptions/UserCreationException.php',
				'Errors/AuthErrorHandler.php'                  => 'src/Errors/AuthErrorHandler.php',
				"/templates/{$engine}/login.{$ext}"             => "templates/login.{$ext}",
				"/templates/{$engine}/registration_form.{$ext}" => "templates/registration_form.{$ext}",
			];
		}
		
		/**
		 * Execute the command, then show next steps on success.
		 * @param ConfigurationManager $config
		 * @return int Exit code (0 = success, 1 = error)
		 */
		public function execute(ConfigurationManager $config): int {
			$this->output->writeLn("<green>Installing Authentication System</green>");
			$this->output->writeLn("");
			
			$exitCode = parent::execute($config);
			
			if ($exitCode === 0) {
				$this->showNextSteps();
			}
			
			return $exitCode;
		}
		
		/**
		 * Show next steps after successful installation
		 * @return void
		 */
		private function showNextSteps(): void {
			$this->output->writeLn("");
			$this->output->writeLn("<green>Next Steps:</green>");
			$this->output->writeLn("");
			$this->output->writeLn("1. Generate database migration:");
			$this->output->writeLn("   <yellow>php ./vendor/bin/sculpt make:migrations</yellow>");
			$this->output->writeLn("");
			$this->output->writeLn("2. Run the migration:");
			$this->output->writeLn("   <yellow>php ./vendor/bin/sculpt quel:migrate</yellow>");
			$this->output->writeLn("");
			$this->output->writeLn("3. Protect a controller by extending AuthenticatedController instead of BaseController:");
			$this->output->writeLn("   <yellow>class DashboardController extends App\\Controllers\\AuthenticatedController</yellow>");
			$this->output->writeLn("");
			$this->output->writeLn("   This applies session and account-eligibility checks to every action in it.");
			$this->output->writeLn("   Both aspects throw rather than redirect — src/Errors/AuthErrorHandler.php is");
			$this->output->writeLn("   what turns a failure into a redirect to /login; edit it if your login route differs.");
			$this->output->writeLn("");
			$this->output->writeLn("   Need to customize the revalidation logic beyond what AccountEligibilityAspect");
			$this->output->writeLn("   exposes? Eject a local, fully editable copy instead:");
			$this->output->writeLn("   <yellow>php ./vendor/bin/sculpt make:auth-aspect</yellow>");
		}
	}