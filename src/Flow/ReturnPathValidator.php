<?php

	namespace Quellabs\CanvasAuthorization\Flow;

	/**
	 * Validates a candidate post-login return path: must be a single-slash,
	 * same-origin path with no scheme or authority, so a stored return path
	 * can never redirect the browser to a different host.
	 */
	class ReturnPathValidator {

		/**
		 * @param string $path
		 * @return bool
		 */
		public function isValid(string $path): bool {
			// Must start with exactly one '/': empty, a relative path, or a
			// protocol-relative "//evil.com" (which browsers resolve against
			// a different origin) are all rejected
			if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
				return false;
			}

			// Some browsers normalize a leading backslash to a forward slash,
			// so "/\evil.com" can resolve the same way "//evil.com" does
			if (str_contains($path, '\\')) {
				return false;
			}

			$parts = parse_url($path);

			if (!is_array($parts)) {
				return false;
			}

			// A scheme, host, port, or userinfo component means this isn't a
			// bare path — it names a different origin, however it parses
			if (isset($parts['scheme']) || isset($parts['host']) || isset($parts['port']) || isset($parts['user'])) {
				return false;
			}

			return true;
		}
	}
