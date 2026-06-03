<?php
declare(strict_types=1);

use Slim\Factory\AppFactory;
use App\Session\SQLiteSessionHandler;
use App\Middleware\ApiKeyMiddleware;
use App\Middleware\JwtMiddleware;
use App\Controllers\VisitController;
use App\Controllers\LogController;
use App\Controllers\StatsController;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    Dotenv::createImmutable(__DIR__ . '/..')->load();
}

$appEnv   = $_ENV['APP_ENV']   ?? $_SERVER['APP_ENV']   ?? 'production';
$dbPath   = $_ENV['DB_PATH']   ?? __DIR__ . '/../data/database.sqlite';
// If DB_PATH is relative, resolve it against the project root
if (!str_starts_with($dbPath, '/') && !preg_match('/^[A-Za-z]:/', $dbPath)) {
    $dbPath = __DIR__ . '/../' . ltrim($dbPath, './');
}
$apiKey   = $_ENV['API_KEY']   ?? null;
$jwksUrl  = $_ENV['CLERK_JWKS_URL'] ?? null;

$allowedOrigins = array_filter(array_map(
    'trim',
    explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? '*')
));

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// PHP Sessions stored in SQLite
$handler = new SQLiteSessionHandler($pdo);
session_set_save_handler($handler, true);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $appEnv === 'production',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$app = AppFactory::create();

// CORS — allow Authorization header for JWT
$app->add(function ($request, $handler) use ($allowedOrigins) {
    $origin = $request->getHeaderLine('Origin');
    $allow  = in_array('*', $allowedOrigins) ? '*'
        : (in_array($origin, $allowedOrigins) ? $origin : '');

    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', $allow ?: '*')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, X-API-KEY, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        ->withHeader('Access-Control-Allow-Credentials', 'true');
});

$app->options('/{routes:.+}', function ($request, $response) {
    return $response;
});

$app->addBodyParsingMiddleware();

$visitController = new VisitController($pdo);
$logController   = new LogController($pdo);
$statsController = new StatsController($pdo);

$apiKeyMiddleware = new ApiKeyMiddleware($apiKey);
$jwtMiddleware    = new JwtMiddleware($jwksUrl);

// POST /visit — JWT extracts userId; API key guards the endpoint
$app->post('/visit', [$visitController, 'handle'])
    ->add($jwtMiddleware)
    ->add($apiKeyMiddleware);

// POST /log — same
$app->post('/log', [$logController, 'handle'])
    ->add($jwtMiddleware)
    ->add($apiKeyMiddleware);

// GET /stats — global page stats (public)
$app->get('/stats', [$statsController, 'handle']);

// GET /stats/user — per-user page stats; JWT extracts userId automatically
$app->get('/stats/user', [$statsController, 'handleUser'])
    ->add($jwtMiddleware);

$app->addErrorMiddleware(true, true, true);

$app->run();
