<?php

	namespace Quellabs\CanvasAuthorization\Tests\Fixtures;

	use Quellabs\Canvas\Configuration\Configuration;
	use Quellabs\Contracts\Configuration\ConfigProviderInterface;
	use Quellabs\Contracts\Configuration\ConfigurationInterface;

	/**
	 * In-memory ConfigProviderInterface for tests: returns a pre-seeded
	 * Configuration for each filename instead of reading config/*.php from disk.
	 */
	class FakeConfigProvider implements ConfigProviderInterface {

		/** @var array<string, array<string, mixed>> */
		private array $files;

		/**
		 * @param array<string, array<string, mixed>> $files Filename (with or without .php) => config array.
		 */
		public function __construct(array $files = []) {
			$this->files = $files;
		}

		/**
		 * @param string $filename
		 * @return ConfigurationInterface
		 */
		public function loadConfigFile(string $filename): ConfigurationInterface {
			if (!str_ends_with($filename, '.php')) {
				$filename .= '.php';
			}

			return new Configuration($this->files[$filename] ?? []);
		}
	}
