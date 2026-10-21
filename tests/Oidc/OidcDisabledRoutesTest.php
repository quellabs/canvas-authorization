<?php

namespace Quellabs\CanvasAuthorization\Tests\Oidc;

use App\Controllers\OidcAuthenticationController;
use App\Services\OidcLocalAccountService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quellabs\CanvasAuthorization\Identity\OidcIdentityRepositoryInterface;
use Quellabs\CanvasAuthorization\Oidc\OidcClientInterface;
use Quellabs\CanvasAuthorization\Oidc\OidcConfig;
use Quellabs\CanvasAuthorization\Tests\Fixtures\FakeConfigProvider;
use Quellabs\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

require_once dirname(__DIR__, 2) . '/stubs/Services/OidcLocalAccountService.php';
require_once dirname(__DIR__, 2) . '/stubs/Controllers/OidcAuthenticationController.php';

class OidcDisabledRoutesTest extends TestCase {

    /**
     * Every OIDC action must stop when the feature is disabled.
     * @return array<string, array{string, string, string}>
     */
    public static function routes(): array {
        return [
            'start' => ['start', 'GET', '/auth/oidc/start'],
            'callback' => ['callback', 'GET', '/auth/oidc/callback'],
            'completion page' => ['complete', 'GET', '/auth/oidc/complete'],
            'existing account' => ['completeWithExistingAccount', 'POST', '/auth/oidc/complete/existing'],
            'registration' => ['completeWithRegistration', 'POST', '/auth/oidc/complete/register'],
            'link start' => ['linkStart', 'POST', '/auth/oidc/link/start'],
        ];
    }

    /**
     * A pending flow cannot continue through any action after OIDC is disabled.
     * @param string $action
     * @param string $method
     * @param string $path
     * @return void
     */
    #[DataProvider('routes')]
    public function testDisabledOidcReturnsNotFoundForEveryAction(string $action, string $method, string $path): void {
        $config = new OidcConfig(new FakeConfigProvider(['oidc.php' => ['enabled' => false]]));
        $controller = new OidcAuthenticationController(
            $this->createMock(Container::class),
            $this->createMock(OidcClientInterface::class),
            $config,
            $this->createMock(OidcIdentityRepositoryInterface::class),
            $this->createMock(OidcLocalAccountService::class)
        );
        $request = Request::create($path, $method);
        $request->setSession(new Session(new MockArraySessionStorage()));

        $response = $controller->$action($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', $response->getContent());
    }
}
