<?php
	
	namespace Quellabs\CanvasAuthorization;
	
	use Quellabs\Sculpt\Application;
	use Quellabs\Sculpt\ServiceProvider;
	
	class AuthProvider extends ServiceProvider {
		
		public function register(Application $application): void {
			// Register the commands into the Sculpt application
			$this->registerCommands($application, [
				MakeAuthCommand::class
			]);
		}
	}