<?php
	
	namespace Quellabs\CanvasAuthorization;
	
	use Quellabs\Sculpt\Application;
	
	class ServiceProvider extends \Quellabs\Sculpt\ServiceProvider {
		
		public function register(Application $application): void {
			// Register the commands into the Sculpt application
			$this->registerCommands($application, [
				MakeAuthCommand::class
			]);
		}
	}