<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use App\Controllers\ReverseProxyResolutionController;
use App\Controllers\FlushNginxCacheController;

require __DIR__ . '/vendor/autoload.php';

$app = AppFactory::create();
$app->addErrorMiddleware(true, true, true);

$app->get('/', function (Request $request, Response $response, $args) {
    $response->getBody()->write("Hello world two!");
    return $response;
});

# Reverse Proxy Resolution
$reverseProxyResolutionController = new ReverseProxyResolutionController();
$app->get('/reverse-proxy-resolution/{domain}', function ($request, $response, $args) use ($reverseProxyResolutionController) {
    return $reverseProxyResolutionController->getReverseProxyResoltionJson($request, $response, $args['domain']);
});

# Flush Bind DNS
$flushNginxCacheController = new FlushNginxCacheController();
$app->get('/flush-nginx-cache', [$flushNginxCacheController, 'flushNginxCache']);


$app->run();