<?php

	namespace Quellabs\CanvasAuthorization;

	use Quellabs\Contracts\Configuration\ConfigProviderInterface;
	use Quellabs\Contracts\Context\MethodContextInterface;
	use Quellabs\DependencyInjection\ContainerLocator;
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
	 * A custom DI provider has no direct access to the container through its
	 * constructor or createInstance() parameters — the discovery mechanism
	 * instantiates providers with `new $class()`, and interface resolution
	 * skips autowiring entirely (see Quellabs\DependencyInjection\Container::
	 * createInstance()). ContainerLocator::getInstance() is the documented
	 * way to reach the shared container from here instead.
	 *
	 * Neither OidcClient nor OidcIdentityRepository actually needs this
	 * provider to be constructed: both have sensible defaults for every
	 * constructor parameter that isn't itself resolvable through the
	 * container's own concrete-class autowiring (OidcConfig autowires from
	 * ConfigProviderInterface the same way JwtAuthenticationAspect's
	 * constructor parameter already does). This provider exists only so
	 * that application code can depend on the package-owned interfaces —
	 * OidcClientInterface, OidcIdentityRepositoryInterface — rather than the
	 * concrete classes, and still resolve through the container. An
	 * application that wants a different implementation registers its own
	 * provider for the same interface; Canvas tries providers in
	 * registration order and falls back to the default only when none claims it.
	 */
	class OidcServiceProvider extends DependencyInjectionServiceProvider {

		/**
		 * Fixed location install:auth-oidc generates the identity entity at.
		 */
		private const string IDENTITY_ENTITY_CLASS = 'App\\Entities\\OidcIdentityEntity';

		/**
		 * @inheritDoc
		 */
		public function supports(string $className, array $metadata): bool {
			return $className === OidcClientInterface::class || $className === OidcIdentityRepositoryInterface::class;
		}

		/**
		 * @inheritDoc
		 */
		public function createInstance(string $className, array $dependencies, array $metadata, ?MethodContextInterface $methodContext = null): object {
			$container = ContainerLocator::getInstance();

			if ($className === OidcIdentityRepositoryInterface::class) {
				/** @var EntityManager $entityManager */
				$entityManager = $container->get(EntityManager::class);
				return new OidcIdentityRepository($entityManager, self::IDENTITY_ENTITY_CLASS);
			}

			/** @var ConfigProviderInterface $configLoader */
			$configLoader = $container->get(ConfigProviderInterface::class);
			return new OidcClient(new OidcConfig($configLoader));
		}
	}
