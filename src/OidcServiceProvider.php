<?php

	namespace Quellabs\CanvasAuthorization;

	use Quellabs\Contracts\Configuration\ConfigProviderInterface;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\Contracts\DependencyInjection\ContainerInterface;
	use Quellabs\DependencyInjection\Provider\ServiceProvider as DependencyInjectionServiceProvider;
	use Quellabs\CanvasAuthorization\Identity\OidcIdentityRepository;
	use Quellabs\CanvasAuthorization\Identity\OidcIdentityRepositoryInterface;
	use Quellabs\CanvasAuthorization\Oidc\OidcClient;
	use Quellabs\CanvasAuthorization\Oidc\OidcClientInterface;
	use Quellabs\CanvasAuthorization\Oidc\OidcConfig;
	use Quellabs\ObjectQuel\EntityManager;

	/**
	 * Registers the default implementations of OidcClientInterface and
	 * OidcIdentityRepositoryInterface with Canvas's DI container, discovered
	 * through composer.json's extra.discover.di.provider key.
	 *
	 * Canvas injects its active container when it discovers this provider.
	 * Resolve other services lazily because the framework registers some of
	 * their bindings after provider discovery.
	 *
	 * Application code can depend on these interfaces while Canvas supplies
	 * the default implementations.
	 */
	class OidcServiceProvider extends DependencyInjectionServiceProvider {

		/**
		 * Fixed location install:auth-oidc generates the identity entity at.
		 */
		private const string IDENTITY_ENTITY_CLASS = 'App\\Entities\\OidcIdentityEntity';

		/** @var ContainerInterface Container */
		private ContainerInterface $container;

		/**
		 * Keep the container used by the Canvas application.
		 * @param ContainerInterface $container
		 */
		public function __construct(ContainerInterface $container) {
			$this->container = $container;
		}

		/**
		 * Claim the OIDC interfaces for unscoped or explicitly OIDC requests.
		 * @param string $className
		 * @param array<string, mixed> $metadata
		 * @return bool
		 */
		public function supports(string $className, array $metadata): bool {
			if ($className !== OidcClientInterface::class && $className !== OidcIdentityRepositoryInterface::class) {
				return false;
			}

			$context = $metadata['context'] ?? null;
			return $context === null || $context === '' || $context === 'oidc';
		}

		/**
		 * Builds the default implementation of whichever of the two interfaces was requested.
		 * @param string $className
		 * @param array<int|string, mixed> $dependencies
		 * @param array<string, mixed> $metadata
		 * @param MethodContextInterface|null $methodContext
		 * @return object
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): object {
			if ($className === OidcIdentityRepositoryInterface::class) {
				/** @var EntityManager $entityManager */
				$entityManager = $this->container->get(EntityManager::class);
				return new OidcIdentityRepository($entityManager, self::IDENTITY_ENTITY_CLASS);
			}

			/** @var ConfigProviderInterface $configLoader */
			$configLoader = $this->container->get(ConfigProviderInterface::class);
			return new OidcClient(new OidcConfig($configLoader));
		}
	}
