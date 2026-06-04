<?php
declare(strict_types=1);

use Slim\Factory\AppFactory;
use App\Session\SQLiteSessionHandler;
use App\Middleware\JwtAuthMiddleware;
use App\Controllers\VisitController;
use App\Controllers\LogController;
use App\Controllers\StatsController;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    Dotenv::createImmutable(__DIR__ . '/..')->load();
}

$appEnv    = $_ENV['APP_ENV']  ?? $_SERVER['APP_ENV']  ?? 'production';
$dbPathEnv = $_ENV['DB_PATH']  ?? $_SERVER['DB_PATH']  ?? 'data/database.sqlite';

if (strpos($dbPathEnv, '/') === 0) {
    $absoluteDbPath = $dbPathEnv;
} else {
    $dbPath = str_replace(['./', '.\\'], '', $dbPathEnv);
    $absoluteDbPath = __DIR__ . '/../' . $dbPath;
}

$dbDir = dirname($absoluteDbPath);
if (!file_exists($dbDir)) {
    @mkdir($dbDir, 0755, true);
}

$dsn = 'sqlite:' . $absoluteDbPath;
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode = WAL;');
$pdo->exec('PRAGMA synchronous = NORMAL;');
$pdo->exec('PRAGMA busy_timeout = 5000;');

// --- Session setup ---

$handler = new SQLiteSessionHandler($pdo);
session_set_save_handler($handler, true);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

$isProduction = ($appEnv === 'production');

// Cross-site (Vercel в†’ Railway) requires SameSite=None; Secure.
// In development without HTTPS we fall back to Lax so browsers don't block it locally.
$sameSite = ($isProduction || $isHttps) ? 'None' : 'Lax';
$secure   = ($sameSite === 'None');

$cookieOptions = [
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => $sameSite,
];

session_set_cookie_params($cookieOptions);
session_start();

// Session expiration
$sessionLifetime = (int)($_ENV['SESSION_LIFETIME'] ?? 900);
$sid = session_id();

if ($sid) {
    try {
        $stmt = $pdo->prepare("SELECT last_access FROM sessions WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $sid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $now = time();

        if ($row) {
            $last = (int)$row['last_access'];
            if ($last + $sessionLifetime < $now) {
                session_destroy();
                if (PHP_SAPI !== 'cli') {
                    setcookie(session_name(), '', time() - 3600, $cookieOptions['path'], $_SERVER['HTTP_HOST'] ?? '', $cookieOptions['secure'], true);
                }
                session_start();
            }
        }
    } catch (\Throwable $e) {
        error_log("Session handling error: " . $e->getMessage());
    }
}

// --- App ---

$app = AppFactory::create();
$app->addRoutingMiddleware();

// Single CORS middleware вЂ” must be added last (outermost in Slim)
$app->add(function ($request, $handler) use ($appEnv) {
    $origin  = $request->getHeaderLine('Origin');
    $raw     = $_ENV['CORS_ALLOWED_ORIGINS'] ?? '';
    $allowed = array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== '');

    if (empty($allowed) && $appEnv !== 'production') {
        $allowed = [
            'http://localhost:5173',
            'http://localhost:3000',
            'http://localhost:8080',
            'https://zoopsy-fe.vercel.app',
        ];
    }

    $allowOrigin      = 'https://zoopsy-fe.vercel.app';
    $allowCredentials = false;

    if ($origin && in_array($origin, $allowed, true)) {
        $allowOrigin      = $origin;
        $allowCredentials = true;
    }

    if (strtoupper($request->getMethod()) === 'OPTIONS') {
        $response = new \Slim\Psr7\Response(204);
    } else {
        $response = $handler->handle($request);
    }

    $response = $response
        ->withHeader('Access-Control-Allow-Origin',  $allowOrigin)
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, X-API-KEY, Authorization, X-Requested-With, Origin')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, PUT, DELETE')
        ->withHeader('Vary', 'Origin');

    if ($allowCredentials) {
        $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
    }

    return $response;
});

$app->options('/{routes:.+}', function ($request, $response) {
    return $response;
});

$app->addBodyParsingMiddleware();
$app->addErrorMiddleware($appEnv !== 'production', true, true);

// --- Controllers & routes ---

$visitController = new VisitController($pdo);
$logController   = new LogController($pdo);
$statsController = new StatsController($pdo);

$clerkJwksUrl = $_ENV['CLERK_JWKS_URL'] ?? $_SERVER['CLERK_JWKS_URL'] ?? null;
if (!$clerkJwksUrl) {
    die("CLERK_JWKS_URL is missing from .env");
}

$jwtAuthMiddleware = new JwtAuthMiddleware($clerkJwksUrl);

$app->post('/visit', [$visitController, 'handle'])->add($jwtAuthMiddleware);
$app->post('/log',   [$logController,   'handle'])->add($jwtAuthMiddleware);
$app->get('/logs',   [$logController,   'list'])->add($jwtAuthMiddleware);
$app->get('/stats',  [$statsController, 'handle']);

$app->run();

