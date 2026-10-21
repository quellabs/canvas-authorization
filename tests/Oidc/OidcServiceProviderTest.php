<?php

declare(strict_types=1);

namespace Quellabs\CanvasAuthorization\Tests\Oidc;

use PHPUnit\Framework\TestCase;
use Quellabs\CanvasAuthorization\Identity\OidcIdentityRepository;
use Quellabs\CanvasAuthorization\Identity\OidcIdentityRepositoryInterface;
use Quellabs\CanvasAuthorization\Oidc\OidcClient;
use Quellabs\CanvasAuthorization\Oidc\OidcClientInterface;
use Quellabs\CanvasAuthorization\OidcServiceProvider;
use Quellabs\CanvasAuthorization\Tests\Fixtures\FakeConfigProvider;
use Quellabs\Contracts\Configuration\ConfigProviderInterface;
use Quellabs\Contracts\DependencyInjection\ContainerInterface;
use Quellabs\ObjectQuel\EntityManager;

class OidcServiceProviderTest extends TestCase {

    /**
     * Resolve both interfaces through services held by the injected application container.
     * @return void
     */
    public function testUsesInjectedContainerForBothInterfaces(): void {
        $configLoader = new FakeConfigProvider();
        $entityManager = $this->createMock(EntityManager::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->exactly(2))
            ->method('get')
            ->willReturnCallback(static function (string $className) use ($configLoader, $entityManager): object {
                return match ($className) {
                    ConfigProviderInterface::class => $configLoader,
                    EntityManager::class => $entityManager,
                    default => throw new \LogicException("Unexpected service: {$className}"),
                };
            });

        $provider = new OidcServiceProvider($container);

        $this->assertTrue($provider->supports(OidcClientInterface::class, []));
        $this->assertTrue($provider->supports(OidcIdentityRepositoryInterface::class, []));
        $this->assertInstanceOf(OidcClient::class, $provider->createInstance(OidcClientInterface::class, [], []));
        $this->assertInstanceOf(OidcIdentityRepository::class, $provider->createInstance(OidcIdentityRepositoryInterface::class, [], []));
    }

    /**
     * Allow OIDC resolution without context or with its named context only.
     * @return void
     */
    public function testClaimsOnlyOidcContext(): void {
        $provider = new OidcServiceProvider($this->createMock(ContainerInterface::class));

        foreach ([OidcClientInterface::class, OidcIdentityRepositoryInterface::class] as $type) {
            $this->assertTrue($provider->supports($type, []));
            $this->assertTrue($provider->supports($type, ['context' => 'oidc']));
            $this->assertFalse($provider->supports($type, ['context' => 'other']));
        }

        $this->assertFalse($provider->supports(\stdClass::class, ['context' => 'oidc']));
    }
}
