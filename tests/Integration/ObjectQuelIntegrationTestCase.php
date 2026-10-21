<?php

	namespace Quellabs\CanvasAuthorization\Tests\Integration;

	use PHPUnit\Framework\TestCase;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Base class for tests against the real MySQL connection set up in
	 * tests/integration-bootstrap.php (see phpunit.integration.xml) — adapted
	 * from F:\Work\framework\tests\ObjectQuel\ObjectQuelTestCase.php, the
	 * same pattern the framework's own ObjectQuel test suite uses.
	 *
	 * Only one EntityManager exists for the whole process (UnitOfWork
	 * registers signals on the process-wide SignalHub with no duplicate
	 * guard, so a second EntityManager anywhere in the same PHPUnit run
	 * throws) — $GLOBALS['test_em'] is it; never construct another one.
	 */
	abstract class ObjectQuelIntegrationTestCase extends TestCase {

		protected EntityManager $em;

		/**
		 * Tables to clear before each test. Override in subclasses.
		 * @var string[]
		 */
		protected array $truncateTables = [];

		protected function setUp(): void {
			$this->em = $GLOBALS['test_em'];

			$connection = $this->em->getConnection()->getConnection();

			foreach ($this->truncateTables as $table) {
				$connection->execute("DELETE FROM `{$table}`");
				$connection->execute("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
			}

			// Clears stale entities from a previous test so reused primary
			// keys after truncation can't resolve to the wrong object
			$this->em->getUnitOfWork()->clear();
		}
	}
