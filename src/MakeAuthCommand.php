<?php
	
	namespace Quellabs\CanvasAuthorization;
	
	use Quellabs\Sculpt\ConfigurationManager;
	use Quellabs\Sculpt\Contracts\CommandBase;
	
	class MakeAuthCommand extends CommandBase {
		
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
		 * Execute the command
		 * @param ConfigurationManager $config
		 * @return int Exit code (0 = success, 1 = error)
		 */
		public function execute(ConfigurationManager $config): int {
			$this->output->writeLn("<info>Installing Authentication System</info>");
			$this->output->writeLn("");
			
			$stubPath = $this->getStubPath();
			$force = $config->hasFlag('force');
			
			$files = [
				'AuthenticationController.php'  => 'src/Controllers/AuthenticationController.php',
				'LoginFormValidator.php'        => 'src/Validation/LoginFormValidator.php',
				'RegistrationFormValidator.php' => 'src/Validation/RegistrationFormValidator.php',
				'UserEntity.php'                => 'src/Entities/UserEntity.php',
				'AuthenticationAspect.php'      => 'src/Aspects/AuthenticationAspect.php',
				'UserCreationException.php'     => 'src/Exceptions/UserCreationException.php',
				'login.tpl'                     => 'templates/login.tpl',
				'registration_form.tpl'         => 'templates/registration_form.tpl',
			];
			
			// Check if any files already exist
			$conflicts = [];
			foreach ($files as $target) {
				if (file_exists($target)) {
					$conflicts[] = $target;
				}
			}
			
			if (!empty($conflicts) && !$force) {
				$this->output->error("The following files already exist:");
				
				foreach ($conflicts as $file) {
					$this->output->writeLn("  - $file");
				}
				
				$this->output->writeLn("");
				$this->output->writeLn("Use --force to overwrite existing files.");
				return 1;
			}
			
			// Copy files
			$copied = 0;
			
			foreach ($files as $stub => $target) {
				$source = $stubPath . DIRECTORY_SEPARATOR . $stub;
				
				if (!file_exists($source)) {
					$this->output->error("Stub file not found: $source");
					return 1;
				}
				
				// Create directory if it doesn't exist
				$targetDir = dirname($target);
				
				if (!is_dir($targetDir)) {
					mkdir($targetDir, 0755, true);
				}
				
				if (copy($source, $target)) {
					$this->output->writeLn("  Created: $target");
					$copied++;
				} else {
					$this->output->error("Failed to copy: $target");
					return 1;
				}
			}
			
			$this->output->writeLn("");
			$this->output->success("Authentication system installed ($copied files)");
			$this->showNextSteps();
			
			return 0;
		}
		
		/**
		 * Get the path to stub files
		 * @return string
		 */
		private function getStubPath(): string {
			return dirname(__DIR__, 2) . '/canvas-authorization/assets';
		}
		
		/**
		 * Show next steps after installation
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