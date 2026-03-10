<?php
	
	namespace Quellabs\CanvasAuthorization;
	
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\StubCommand;
	
	class MakeAuthCommand extends StubCommand {
		
		/**
		 * Returns the signature of this command
		 * @return string
		 */
		public function getSignature(): string {
			return "make:auth";
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
				'Validation/LoginFormValidator.php'            => 'src/Validation/LoginFormValidator.php',
				'Validation/RegistrationFormValidator.php'     => 'src/Validation/RegistrationFormValidator.php',
				'Entities/UserEntity.php'                      => 'src/Entities/UserEntity.php',
				'Aspects/AuthenticationAspect.php'             => 'src/Aspects/AuthenticationAspect.php',
				'Exceptions/UserCreationException.php'         => 'src/Exceptions/UserCreationException.php',
				"{$engine}/templates/login.{$ext}"             => "templates/login.{$ext}",
				"{$engine}/templates/registration_form.{$ext}" => "templates/registration_form.{$ext}",
			];
		}
		
		/**
		 * Execute the command, then show next steps on success.
		 * @param ConfigurationManager $config
		 * @return int Exit code (0 = success, 1 = error)
		 */
		public function execute(ConfigurationManager $config): int {
			$this->output->writeLn("<info>Installing Authentication System</info>");
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
			$this->output->writeLn("<info>Next Steps:</info>");
			$this->output->writeLn("");
			$this->output->writeLn("1. Generate database migration:");
			$this->output->writeLn("   <comment>php ./vendor/bin/sculpt make:migrations</comment>");
			$this->output->writeLn("");
			$this->output->writeLn("2. Run the migration:");
			$this->output->writeLn("   <comment>php ./vendor/bin/sculpt quel:migrate</comment>");
			$this->output->writeLn("");
			$this->output->writeLn("3. Apply authentication to your controllers:");
			$this->output->writeLn("   <comment>@InterceptWith(App\\Aspects\\AuthenticationAspect::class)</comment>");
		}
	}