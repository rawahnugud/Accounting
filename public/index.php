<?php
declare(strict_types=1);

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

ini_set('display_errors', '0');
require dirname(__DIR__).'/bootstrap/runtime.php';
$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'production', false);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
