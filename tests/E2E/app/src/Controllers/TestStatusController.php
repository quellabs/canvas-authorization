<?php

namespace App\Controllers;

use Quellabs\Canvas\Annotations\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class TestStatusController extends AuthenticatedController {

    /**
     * Expose the current local user ID only inside this disposable test app.
     * @Route("/test/whoami", methods={"GET"})
     * @param Request $request
     * @return Response
     */
    public function status(Request $request): Response {
        return new Response((string)$request->getSession()->get('auth_user_id'));
    }
}
