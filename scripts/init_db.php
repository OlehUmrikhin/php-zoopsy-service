<?php
// Run: php scripts/init_db.php
$root = __DIR__ . '/..';
require $root . '/vendor/autoload.php';

$envFile = $root . '/.env';
if (file_exists($envFile)) {
    (Dotenv\Dotenv::createImmutable($root))->load();
}

$dbPathEnv = getenv('DB_PATH') ?: 'data/database.sqlite';

if (strpos($dbPathEnv, '/') === 0) {
    $dbPath = $dbPathEnv;
} else {
    $dbPath = $root . '/' . ltrim($dbPathEnv, '/\\');
}

if (!is_dir(dirname($dbPath))) {
    mkdir(dirname($dbPath), 0777, true);
}

$dsn = 'sqlite:' . $dbPath;
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode = WAL;');
$pdo->exec('PRAGMA synchronous = NORMAL;');
$pdo->exec('PRAGMA busy_timeout = 5000;');

$sql = file_get_contents(__DIR__ . '/../migrations/init.sql');
$pdo->exec($sql);

echo "Initialized DB at: {$dbPath}\n";
