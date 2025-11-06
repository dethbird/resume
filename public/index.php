<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Create Container
$container = new Container();

// Configure Twig
$container->set('twig', function() {
    $templatesDir = __DIR__ . '/../templates';
    $cacheDir = __DIR__ . '/../cache/twig';

    // Ensure cache directory exists and is writable; fall back to no-cache in dev
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $cacheOption = is_writable($cacheDir) ? $cacheDir : false;

    $loader = new FilesystemLoader($templatesDir);
    return new Environment($loader, [
        'cache' => $cacheOption,
        'debug' => true,
        'auto_reload' => true,
    ]);
});

// Set container to create App with on AppFactory
AppFactory::setContainer($container);
$app = AppFactory::create();

// If the app is served from a subdirectory, set base path (helps Apache deployments)
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($basePath && $basePath !== '/') {
    $app->setBasePath($basePath);
}

// Add error middleware
$app->addErrorMiddleware(true, true, true);

// Routes
$app->get('/', function (Request $request, Response $response, $args) use ($container) {
    $twig = $container->get('twig');
    $html = $twig->render('index.twig', [
        'title' => 'Resume Generator',
        'message' => 'Welcome to the Resume Generator'
    ]);
    
    $response->getBody()->write($html);
    return $response;
});

$app->get('/api/health', function (Request $request, Response $response, $args) {
    $data = ['status' => 'ok', 'timestamp' => date('c')];
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();

