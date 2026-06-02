<?php
declare(strict_types=1);

use Slim\Factory\AppFactory;
use Slim\Middleware\ErrorMiddleware;
use App\Session\SQLiteSessionHandler;
use App\Middleware\ApiKeyMiddleware;
use App\Controllers\VisitController;
use App\Controllers\LogController;
use App\Controllers\StatsController;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$envPath = __DIR__ . '/../.env';
if (file_exists(__DIR__ . '/../.env')) {
    Dotenv::createImmutable(__DIR__ . '/..')->load();
}

$appEnv = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'production';
$dbPath = $_ENV['DB_PATH'] ?? 'data/database.sqlite';
$apiKey = $_ENV['API_KEY'] ?? null;

$dsn = 'sqlite:' . $dbPath;
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// session handler
$handler = new SQLiteSessionHandler($pdo);
session_set_save_handler($handler, true);

$secure = $appEnv === 'production';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

$app = AppFactory::create();

// CORS middleware
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, X-API-KEY')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
});

$app->options('/{routes:.+}', function ($request, $response) { return $response; });

$app->addBodyParsingMiddleware();

$visitController = new VisitController($pdo);
$logController = new LogController($pdo);
$statsController = new StatsController($pdo);

$apiKeyMiddleware = new ApiKeyMiddleware($apiKey);

$app->post('/visit', [$visitController, 'handle'])->add($apiKeyMiddleware);
$app->post('/log', [$logController, 'handle'])->add($apiKeyMiddleware);
$app->get('/stats', [$statsController, 'handle']);

$errorMiddleware = $app->addErrorMiddleware(true, true, true);

$app->run();
