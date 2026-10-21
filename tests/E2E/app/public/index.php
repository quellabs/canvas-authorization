<?php

declare(strict_types=1);

use Quellabs\Canvas\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';

(new Kernel())->handle(Request::createFromGlobals())->send();
