<?php
declare(strict_types=1);

use Slim\Factory\AppFactory;
use Slim\Middleware\ErrorMiddleware;
use App\Session\SQLiteSessionHandler;
use App\Middleware\ApiKeyMiddleware;
use App\Middleware\JwtAuthMiddleware;
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
$dbPathEnv = $_ENV['DB_PATH'] ?? $_SERVER['DB_PATH'] ?? 'data/database.sqlite';

if (strpos($dbPathEnv, '/') === 0) {
    // Absolute path (e.g. Railway volume)
    $absoluteDbPath = $dbPathEnv;
} else {
    // Relative path fallback (e.g. local dev)
    $dbPath = str_replace(['./', '.\\'], '', $dbPathEnv);
    $absoluteDbPath = __DIR__ . '/../' . $dbPath;
}

// Ensure the directory exists if it's an absolute path
$dbDir = dirname($absoluteDbPath);
if (!file_exists($dbDir)) {
    @mkdir($dbDir, 0755, true);
}

$apiKey = $_ENV['API_KEY'] ?? $_SERVER['API_KEY'] ?? null;

$dsn = 'sqlite:' . $absoluteDbPath;
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// performance & reliability pragmas for SQLite
$pdo->exec('PRAGMA journal_mode = WAL;');
$pdo->exec('PRAGMA synchronous = NORMAL;');
$pdo->exec('PRAGMA busy_timeout = 5000;');

// Session handler — stored in SQLite, but NO cookie is sent to the browser.
// This is a cross-site API: setting PHPSESSID via Set-Cookie would be blocked
// by browsers for SameSite=Lax on cross-site fetch requests.
// Session ID is derived server-side from IP + date (daily unique-visitor key).
ini_set('session.use_cookies', '0');
ini_set('session.use_only_cookies', '0');
ini_set('session.use_trans_sid', '0');

$handler = new SQLiteSessionHandler($pdo);
session_set_save_handler($handler, true);

$clientIp  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$serverSid = md5($clientIp . date('Y-m-d'));
session_id($serverSid);
session_start();

$app = AppFactory::create();
$app->addRoutingMiddleware();

// CORS Middleware
$app->add(function ($request, $handler) {
    global $appEnv;
    $response = $handler->handle($request);
    
    // We allow our specific Vercel frontend, plus optionally local dev environments
    $origin = $request->getHeaderLine('Origin');
    $allowedOrigins = ['https://zoopsy-fe.vercel.app'];
    
    if ($appEnv !== 'production') {
        $allowedOrigins[] = 'http://localhost:3000';
    }

    if (in_array($origin, $allowedOrigins)) {
        $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
    } else {
        // Fallback for public API endpoints or strict checking.
        // If this is meant to be a private API just for the frontend, we can leave this out or strict-allow.
        $response = $response->withHeader('Access-Control-Allow-Origin', 'https://zoopsy-fe.vercel.app');
    }
    
    return $response
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization, Options')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
        ->withHeader('Access-Control-Allow-Credentials', 'true');
});

$app->options('/{routes:.+}', function ($request, $response) { return $response; });

$app->addBodyParsingMiddleware();

$visitController = new VisitController($pdo);
$logController = new LogController($pdo);
$statsController = new StatsController($pdo);

// CLERK_JWKS_URL is optional: if missing, JWT middleware runs in dev mode (no signature check)
$clerkJwksUrl = $_ENV['CLERK_JWKS_URL'] ?? null;
if (!$clerkJwksUrl) {
    error_log("Warning: CLERK_JWKS_URL not set — JWT signatures will NOT be verified (dev mode)");
}

$jwtAuthMiddleware = new JwtAuthMiddleware($clerkJwksUrl ?? '');

$app->post('/visit', [$visitController, 'handle'])->add($jwtAuthMiddleware);
$app->post('/log', [$logController, 'handle'])->add($jwtAuthMiddleware);
$app->get('/logs', [$logController, 'list'])->add($jwtAuthMiddleware);
$app->get('/stats', [$statsController, 'handle']);
$app->get('/stats/user', [$statsController, 'handle'])->add($jwtAuthMiddleware);

$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// CORS middleware MUST be the outermost middleware (added last in Slim)
$app->add(function ($request, $handler) use ($appEnv) {
    $origin = $request->getHeaderLine('Origin');
    $raw = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '';
    $allowed = array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== '');

    if (empty($allowed) && ($appEnv ?? 'production') !== 'production') {
        $allowed = [
            'http://localhost:5173',
            'http://localhost:3000',
            'http://localhost:8080',
            'https://zoopsy-fe.vercel.app' 
        ];
    }

    $allowOrigin = '*';
    $allowCredentials = false;
    if ($origin && in_array($origin, $allowed, true)) {
        $allowOrigin = $origin;
        $allowCredentials = true;
    }

    $response = $handler->handle($request);
    $response = $response
        ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, X-API-KEY, Authorization, authorization, content-type')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, PUT, DELETE');

    if ($allowCredentials) {
        $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
    }

    return $response;
});

$app->run();
