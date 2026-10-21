<?php

namespace Quellabs\CanvasAuthorization\Tests\Oidc;

use App\Controllers\OidcAuthenticationController;
use App\Entities\UserEntity;
use App\Services\OidcLocalAccountService;
use PHPUnit\Framework\TestCase;
use Quellabs\CanvasAuthorization\Flow\OidcFlowStore;
use Quellabs\CanvasAuthorization\Flow\PendingFlow;
use Quellabs\CanvasAuthorization\Identity\OidcIdentityRepository;
use Quellabs\CanvasAuthorization\Oidc\IdTokenClaims;
use Quellabs\CanvasAuthorization\Oidc\OidcClient;
use Quellabs\CanvasAuthorization\Oidc\OidcConfig;
use Quellabs\CanvasAuthorization\Tests\Fixtures\FakeConfigProvider;
use Quellabs\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

require_once dirname(__DIR__, 2) . '/stubs/Services/OidcLocalAccountService.php';
require_once dirname(__DIR__, 2) . '/stubs/Controllers/OidcAuthenticationController.php';

class OidcCallbackTest extends TestCase {

    /**
     * Build a signed-token result as seen by the controller after protocol validation.
     * @param int|null $authTime
     * @return IdTokenClaims
     */
    private function claims(?int $authTime): IdTokenClaims {
        return new IdTokenClaims('https://idp.example.test', 'subject-123', 'client-123', null, time() + 300, time(), $authTime);
    }

    /**
     * Build the enabled configuration used by these browser callback tests.
     * @return OidcConfig
     */
    private function config(): OidcConfig {
        return new OidcConfig(new FakeConfigProvider(['oidc.php' => [
            'enabled' => true,
            'issuer' => 'https://idp.example.test',
            'client_id' => 'client-123',
            'client_secret' => 'secret',
            'redirect_uri' => 'https://app.example.test/auth/oidc/callback',
        ]]));
    }

    /**
     * Store a callback flow and return its browser request and session.
     * @param OidcFlowStore $flowStore
     * @param string|null $returnPath
     * @return array{Request, Session}
     */
    private function callbackRequest(OidcFlowStore $flowStore, ?string $returnPath = null): array {
        $session = new Session(new MockArraySessionStorage());
        $flowStore->storeFlow($session, new PendingFlow('state-123', 'nonce-123', 'verifier-123', time(), 'login', null, null, $returnPath));
        $request = Request::create('/auth/oidc/callback?state=state-123&code=code-123');
        $request->setSession($session);
        return [$request, $session];
    }

    /**
     * A linked identity creates a fresh local session using only verified provider claims.
     * @return void
     */
    public function testLinkedIdentityEstablishesSessionAndConsumesState(): void {
        $flowStore = new OidcFlowStore();
        [$request, $session] = $this->callbackRequest($flowStore, '/dashboard');
        $session->set('user_validated_at', time());
        $authTime = time() - 30;

        $client = $this->createMock(OidcClient::class);
        $client->expects($this->once())
            ->method('exchangeAuthorizationCode')
            ->with('code-123', 'verifier-123', 'nonce-123')
            ->willReturn($this->claims($authTime));
        $repository = $this->createMock(OidcIdentityRepository::class);
        $repository->expects($this->once())
            ->method('findLocalUserId')
            ->with('https://idp.example.test', 'subject-123')
            ->willReturn(42);
        $accounts = $this->createMock(OidcLocalAccountService::class);
        $accounts->method('findById')->with(42)->willReturn(new UserEntity());
        $controller = new OidcAuthenticationController($this->createMock(Container::class), $client, $this->config(), $repository, $accounts, $flowStore);

        $response = $controller->callback($request);

        $this->assertSame('/dashboard', $response->headers->get('Location'));
        $this->assertSame(42, $session->get('auth_user_id'));
        $this->assertSame($authTime, $session->get('auth_time'));
        $this->assertSame([], $session->get('auth_methods'));
        $this->assertFalse($session->has('user_validated_at'));
        $this->assertNull($flowStore->consumeFlow($session, 'state-123'));
    }

    /**
     * An unlinked identity waits for explicit account completion without authenticating.
     * @return void
     */
    public function testUnlinkedIdentityCreatesOnlyAPendingCompletion(): void {
        $flowStore = new OidcFlowStore();
        [$request, $session] = $this->callbackRequest($flowStore);
        $client = $this->createMock(OidcClient::class);
        $client->method('exchangeAuthorizationCode')->willReturn($this->claims(null));
        $repository = $this->createMock(OidcIdentityRepository::class);
        $repository->method('findLocalUserId')->willReturn(null);
        $repository->expects($this->never())->method('link');
        $accounts = $this->createMock(OidcLocalAccountService::class);
        $accounts->expects($this->never())->method('createLocalAccount');
        $accounts->expects($this->never())->method('createOidcOnlyProfile');
        $controller = new OidcAuthenticationController($this->createMock(Container::class), $client, $this->config(), $repository, $accounts, $flowStore);

        $response = $controller->callback($request);

        $this->assertSame('/auth/oidc/complete', $response->headers->get('Location'));
        $this->assertFalse($session->has('auth_user_id'));
        $completion = $flowStore->peekCompletion($session);
        $this->assertNotNull($completion);
        $this->assertSame('https://idp.example.test', $completion->getIssuer());
        $this->assertSame('subject-123', $completion->getSubject());
        $this->assertNull($flowStore->consumeFlow($session, 'state-123'));
    }
}
