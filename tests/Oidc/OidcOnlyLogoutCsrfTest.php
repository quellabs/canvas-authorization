<?php

namespace Quellabs\CanvasAuthorization\Tests\Oidc;

use App\Controllers\OidcOnlyLoginController;
use PHPUnit\Framework\TestCase;
use Quellabs\AnnotationReader\AnnotationReader;
use Quellabs\AnnotationReader\Configuration;
use Quellabs\Canvas\AOP\AspectResolver;
use Quellabs\Canvas\Exceptions\CsrfTokenException;
use Quellabs\Canvas\Routing\Contracts\MethodContextInterface;
use Quellabs\Canvas\Security\CsrfProtectionAspect;
use Quellabs\Canvas\Security\Foundation\CsrfTokenManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

require_once dirname(__DIR__, 2) . '/stubs/Controllers/OidcOnlyLoginController.php';

class OidcOnlyLogoutCsrfTest extends TestCase {

    /**
     * Resolve the protection Canvas applies to the generated logout action.
     * @return CsrfProtectionAspect
     */
    private function logoutAspect(): CsrfProtectionAspect {
        $resolver = new AspectResolver(new AnnotationReader(new Configuration()));
        $definitions = $resolver->resolve(OidcOnlyLoginController::class, 'logout');
        $csrfDefinitions = array_values(array_filter(
            $definitions,
            static fn(array $definition): bool => $definition['class'] === CsrfProtectionAspect::class
        ));

        self::assertCount(1, $csrfDefinitions);
        return new CsrfProtectionAspect(...$csrfDefinitions[0]['parameters']);
    }

    /**
     * Build a POST context with a fresh session.
     * @param Request $request
     * @return MethodContextInterface
     */
    private function contextFor(Request $request): MethodContextInterface {
        $context = $this->createMock(MethodContextInterface::class);
        $context->method('getRequest')->willReturn($request);
        return $context;
    }

    /**
     * A missing token must stop the action before it invalidates the session.
     * @return void
     */
    public function testLogoutRejectsPostWithoutCsrfToken(): void {
        $request = Request::create('/logout', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $this->expectException(CsrfTokenException::class);
        $this->logoutAspect()->before($this->contextFor($request));
    }

    /**
     * A token generated for this session permits the logout action.
     * @return void
     */
    public function testLogoutAcceptsPostWithValidCsrfToken(): void {
        $request = Request::create('/logout', 'POST');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $token = (new CsrfTokenManager($request->getSession()))->generateToken();
        $request->request->set('_csrf_token', $token);

        $result = $this->logoutAspect()->before($this->contextFor($request));

        $this->assertNull($result);
        $this->assertTrue($request->attributes->get('csrf_validation_succeeded'));
    }
}
