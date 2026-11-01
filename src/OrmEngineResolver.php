<?php

	namespace Quellabs\CanvasAuthorization;

	use Quellabs\Support\ComposerUtils;

	/**
	 * Determines which ORM a Canvas project has installed, so install:auth and
	 * make:auth-aspect can scaffold the matching stub variant instead of
	 * assuming ObjectQuel.
	 *
	 * Detection reads the project's own config/discovery-mapping.php — the file
	 * Discover generates from every installed package's composer.json "extra"
	 * block — rather than a config value, because no config key currently
	 * names the active ORM the way config/app.php's template_engine does.
	 */
	class OrmEngineResolver {

		/**
		 * Canvas integration package => ORM identifier, checked in this order.
		 * ObjectQuel is the fallback when none of these are discovered.
		 */
		private const array ORM_PACKAGES = [
			'quellabs/canvas-doctrine' => 'doctrine',
			'quellabs/canvas-eloquent' => 'eloquent',
		];

		/**
		 * Resolve the ORM identifier ('doctrine', 'eloquent', or 'objectquel')
		 * for the current project.
		 * @param string|null $startDirectory Directory to start the project-root search from (defaults to the current directory)
		 * @return string
		 */
		public static function resolve(?string $startDirectory = null): string {
			$mappingPath = ComposerUtils::getDiscoveryMappingPath($startDirectory);

			if ($mappingPath === null || !file_exists($mappingPath)) {
				return 'objectquel';
			}

			$mapping = require $mappingPath;

			if (!is_array($mapping)) {
				return 'objectquel';
			}

			foreach (self::ORM_PACKAGES as $package => $orm) {
				if (isset($mapping[$package])) {
					return $orm;
				}
			}

			return 'objectquel';
		}
	}
