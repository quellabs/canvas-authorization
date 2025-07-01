<?php
	
	namespace Quellabs\CanvasAuthorization\Publishing;
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Contracts\Publishing\AssetPublisher;
	
	/**
	 * AuthenticationPublisher handles the publishing of authentication-related assets
	 * for the Canvas system. This class implements both ProviderInterface for
	 * discovery and AssetPublisher for asset publishing functionality.
	 */
	class AuthorizationPublisher implements ProviderInterface, AssetPublisher {
		
		/**
		 * Returns a human-readable description of this publisher
		 * @return string The description of the authentication publisher
		 */
		public static function getDescription(): string {
			return "Publishes authentication system components";
		}
		
		/**
		 * Returns detailed help information for this publisher
		 * @return string Help text explaining what this publisher does and how to use it
		 */
		public static function getHelp(): string {
			return <<<HELP
==============================================================================
DETAILS:
==============================================================================
Installs a complete user authentication system with database migration,
authentication controller, and AOP before aspect for login validation.

==============================================================================
COMPONENTS:
==============================================================================
• Database migration for users table with standard authentication fields
• Authentication controller with login, logout, and user registration endpoints
• AOP before aspect for validating logged-in users

==============================================================================
FILES INSTALLED:
==============================================================================
• database/migrations/create_users_table.php - User table migration
• config/auth.php - Authentication configuration file
• src/Controllers/AuthController.php - Authentication controller

==============================================================================
NOTES:
==============================================================================
The AOP before aspect can be applied to controllers or methods that require
user authentication, automatically redirecting unauthenticated users to the
login page.
HELP;
		}
		
		/**
		 * Returns the unique tag identifier for this publisher
		 * @return string The tag used to identify this publisher
		 */
		public static function getTag(): string {
			return "canvas/authentication";
		}
		
		/**
		 * Publishes authentication assets to the specified base path
		 * @param string $basePath The base directory path where assets will be published
		 * @param bool $force Whether to force to republish existing assets (default: false)
		 * @return void True if publishing was successful, false otherwise
		 */
		public function publish(string $basePath, bool $force = false): void {
			$sourcePath = dirname(__FILE__) . "/../../templates/";
			$targetPathSrc = $basePath . DIRECTORY_SEPARATOR . "src";
			$targetPathControllers = $targetPathSrc . DIRECTORY_SEPARATOR . "Controllers";
			$targetPathValidation = $targetPathSrc . DIRECTORY_SEPARATOR . "Validation";
			$targetPathTemplates = $basePath . DIRECTORY_SEPARATOR . "templates";
			
			if (!is_dir($targetPathValidation)) {
				mkdir($targetPathValidation, 0755, true);
			}
			
			if (!is_dir($targetPathTemplates)) {
				mkdir($targetPathTemplates, 0755, true);
			}
			
			copy(
				$sourcePath . DIRECTORY_SEPARATOR . "controllers" . DIRECTORY_SEPARATOR . "AuthenticationController.php",
				$targetPathControllers . DIRECTORY_SEPARATOR . "AuthenticationController.php"
			);

			copy(
				$sourcePath . DIRECTORY_SEPARATOR . "validation" . DIRECTORY_SEPARATOR . "LoginFormValidator.php",
				$targetPathValidation . DIRECTORY_SEPARATOR . "LoginFormValidator.php"
			);

			copy(
				$sourcePath . DIRECTORY_SEPARATOR . "views" . DIRECTORY_SEPARATOR . "login.tpl",
				$targetPathTemplates . DIRECTORY_SEPARATOR . "login.tpl"
			);
		}
		
		/**
		 * Returns instructions to be displayed after successful publishing
		 * @return string Array of post-publish instruction strings
		 */
		public function getPostPublishInstructions(): string {
			return "";
		}
		
		/**
		 * Checks if this publisher can currently publish assets
		 * @return bool True if publishing is possible, false otherwise
		 */
		public function canPublish(): bool {
			return true;
		}
		
		/**
		 * Returns the reason why publishing cannot be performed
		 * Only relevant when canPublish() returns false
		 * @return string Human-readable reason for publishing failure
		 */
		public function getCannotPublishReason(): string {
			return "getCannotPublishReason";
		}
		
		/**
		 * Returns metadata information about this publisher
		 * @return array Associative array of metadata key-value pairs
		 */
		public static function getMetadata(): array {
			return [];
		}
		
		/**
		 * Returns default configuration values for this publisher
		 * @return array Associative array of default configuration values
		 */
		public static function getDefaults(): array {
			return [];
		}
		
		/**
		 * Returns the current configuration for this publisher instance
		 * @return array Associative array of current configuration values
		 */
		public function getConfig(): array {
			return [];
		}
		
		/**
		 * Sets the configuration for this publisher instance
		 * @param array $config Associative array of configuration values to set
		 * @return void
		 */
		public function setConfig(array $config): void {
		}
	}