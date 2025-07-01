<?php
	
	namespace Quellabs\CanvasAuthorization\Publishing;
	
	use Quellabs\Contracts\Discovery\ProviderInterface;
	use Quellabs\Contracts\Publishing\AssetPublisher;
	use RuntimeException;
	
	/**
	 * AuthorizationPublisher handles the publishing of authorization-related assets
	 * for the Canvas system. This class implements both ProviderInterface for
	 * discovery and AssetPublisher for asset publishing functionality.
	 */
	class AuthorizationPublisher implements ProviderInterface, AssetPublisher {
		
		/**
		 * Configuration storage
		 * @var array
		 */
		private array $config = [];
		
		/**
		 * Returns a human-readable description of this publisher
		 * @return string The description of the authorization publisher
		 */
		public static function getDescription(): string {
			return "Publishes authorization system components";
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
Installs a complete user authorization system with database migration,
authentication controller, and AOP before aspect for login validation.

==============================================================================
COMPONENTS:
==============================================================================
• Database migration for users table with standard authentication fields
• Authentication controller with login, logout, and user registration endpoints
• AOP before aspect for validating logged-in users
• Form validation for login functionality
• User entity for database operations
• Login template for user interface

==============================================================================
FILES INSTALLED:
==============================================================================
• src/Controllers/AuthenticationController.php - Authentication controller
• src/Validation/LoginFormValidator.php - Login form validation
• src/Entities/UserEntity.php - User entity for database operations
• templates/login.tpl - Login page template

==============================================================================
NOTES:
==============================================================================
The AOP before aspect can be applied to controllers or methods that require
user authentication, automatically redirecting unauthenticated users to the
login page.

After installation, you will need to run the migration commands to set up
the database table.
HELP;
		}
		
		/**
		 * Returns the unique tag identifier for this publisher
		 * @return string The tag used to identify this publisher
		 */
		public static function getTag(): string {
			return "canvas/authorization";
		}
		
		/**
		 * Publishes authorization assets to the specified base path
		 * @param string $basePath The base directory path where assets will be published
		 * @param bool $force Whether to force republish existing assets (default: false)
		 * @return void
		 * @throws RuntimeException If publishing fails
		 */
		public function publish(string $basePath, bool $force = false): void {
			$sourcePath = dirname(__FILE__) . "/../../templates/";
			
			// Validate source path exists
			if (!is_dir($sourcePath)) {
				throw new RuntimeException("Source template directory not found: {$sourcePath}");
			}
			
			// Define target paths
			$targetPaths = [
				'src'         => $basePath . DIRECTORY_SEPARATOR . "src",
				'controllers' => $basePath . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "Controllers",
				'validation'  => $basePath . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "Validation",
				'entities'    => $basePath . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "Entities",
				'templates'   => $basePath . DIRECTORY_SEPARATOR . "templates"
			];
			
			// Create necessary directories
			$this->createDirectories($targetPaths);
			
			// Copy files
			$this->copyFiles($sourcePath, $targetPaths, $force);
		}
		
		/**
		 * Creates necessary directories for the assets
		 * @param array $targetPaths Array of target directory paths
		 * @return void
		 * @throws RuntimeException If directory creation fails
		 */
		private function createDirectories(array $targetPaths): void {
			$requiredDirs = ['validation', 'entities', 'templates'];
			
			foreach ($requiredDirs as $dir) {
				if (!is_dir($targetPaths[$dir])) {
					if (!mkdir($targetPaths[$dir], 0755, true)) {
						throw new RuntimeException("Failed to create directory: {$targetPaths[$dir]}");
					}
				}
			}
		}
		
		/**
		 * Copies template files to target locations
		 * @param string $sourcePath Source template directory
		 * @param array $targetPaths Target directory paths
		 * @param bool $force Whether to overwrite existing files
		 * @return void
		 * @throws RuntimeException If file copying fails
		 */
		private function copyFiles(string $sourcePath, array $targetPaths, bool $force): void {
			$filesToCopy = [
				[
					'source' => $sourcePath . "controllers" . DIRECTORY_SEPARATOR . "AuthenticationController.php",
					'target' => $targetPaths['controllers'] . DIRECTORY_SEPARATOR . "AuthenticationController.php"
				],
				[
					'source' => $sourcePath . "validation" . DIRECTORY_SEPARATOR . "LoginFormValidator.php",
					'target' => $targetPaths['validation'] . DIRECTORY_SEPARATOR . "LoginFormValidator.php"
				],
				[
					'source' => $sourcePath . "entities" . DIRECTORY_SEPARATOR . "UserEntity.php",
					'target' => $targetPaths['entities'] . DIRECTORY_SEPARATOR . "UserEntity.php"
				],
				[
					'source' => $sourcePath . "views" . DIRECTORY_SEPARATOR . "login.tpl",
					'target' => $targetPaths['templates'] . DIRECTORY_SEPARATOR . "login.tpl"
				]
			];
			
			foreach ($filesToCopy as $file) {
				$this->copyFile($file['source'], $file['target'], $force);
			}
		}
		
		/**
		 * Copies a single file with validation
		 * @param string $source Source file path
		 * @param string $target Target file path
		 * @param bool $force Whether to overwrite existing files
		 * @return void
		 * @throws RuntimeException If file copying fails
		 */
		private function copyFile(string $source, string $target, bool $force): void {
			if (!file_exists($source)) {
				throw new RuntimeException("Source file not found: {$source}");
			}
			
			if (file_exists($target) && !$force) {
				throw new RuntimeException("Target file already exists (use --force to overwrite): {$target}");
			}
			
			if (!copy($source, $target)) {
				throw new RuntimeException("Failed to copy file from {$source} to {$target}");
			}
		}
		
		/**
		 * Returns instructions to be displayed after successful publishing
		 * @return string Post-publish instruction text
		 */
		public function getPostPublishInstructions(): string {
			return <<<INSTRUCTIONS
==============================================================================
INSTALLATION COMPLETE
==============================================================================

The following files have been successfully installed:
• src/Controllers/AuthenticationController.php
• src/Validation/LoginFormValidator.php
• src/Entities/UserEntity.php
• templates/login.tpl

==============================================================================
NEXT STEPS - DATABASE SETUP
==============================================================================

To complete the installation, you need to set up the database table:

1. Generate migration for the new UserEntity:
   php ./vendor/bin/sculpt make:migrations

2. Run the migration to create the database table:
   php ./vendor/bin/sculpt quel:migrate

==============================================================================
USAGE
==============================================================================

After completing the database setup, you can:
• Access the login page through your routing system
• Use the AuthenticationController for login/logout functionality
• Apply authentication validation to your controllers using AOP aspects

The authorization system is now ready for use!
INSTRUCTIONS;
		}
		
		/**
		 * Checks if this publisher can currently publish assets
		 * @return bool True if publishing is possible, false otherwise
		 */
		public function canPublish(): bool {
			$sourcePath = dirname(__FILE__) . "/../../templates/";
			return is_dir($sourcePath) && is_readable($sourcePath);
		}
		
		/**
		 * Returns the reason why publishing cannot be performed
		 * Only relevant when canPublish() returns false
		 * @return string Human-readable reason for publishing failure
		 */
		public function getCannotPublishReason(): string {
			$sourcePath = dirname(__FILE__) . "/../../templates/";
			
			if (!is_dir($sourcePath)) {
				return "Template directory not found: {$sourcePath}";
			}
			
			if (!is_readable($sourcePath)) {
				return "Template directory is not readable: {$sourcePath}";
			}
			
			return "Unknown reason - publishing should be possible";
		}
		
		/**
		 * Returns metadata information about this publisher
		 * @return array Associative array of metadata key-value pairs
		 */
		public static function getMetadata(): array {
			return [
				'version'     => '1.0.0',
				'author'      => 'Quellabs',
				'category'    => 'Authentication',
				'requires'    => ['php' => '>=7.4'],
				'files_count' => 4
			];
		}
		
		/**
		 * Returns default configuration values for this publisher
		 * @return array Associative array of default configuration values
		 */
		public static function getDefaults(): array {
			return [
				'overwrite_existing' => false,
				'create_backup'      => true,
				'permissions'        => 0755
			];
		}
		
		/**
		 * Returns the current configuration for this publisher instance
		 * @return array Associative array of current configuration values
		 */
		public function getConfig(): array {
			return $this->config ?? self::getDefaults();
		}
		
		/**
		 * Sets the configuration for this publisher instance
		 * @param array $config Associative array of configuration values to set
		 * @return void
		 */
		public function setConfig(array $config): void {
			$this->config = array_merge(self::getDefaults(), $config);
		}
	}