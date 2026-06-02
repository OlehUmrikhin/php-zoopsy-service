<?php
// Run: php scripts/init_db.php
$root = __DIR__ . '/..';
require $root . '/vendor/autoload.php';

$envFile = $root . '/.env';
if (file_exists($envFile)) {
    (Dotenv\Dotenv::createImmutable($root))->load();
}

$dbPath = getenv('DB_PATH') ?: $root . '/data/database.sqlite';
if (!is_dir(dirname($dbPath))) {
    mkdir(dirname($dbPath), 0777, true);
}

$dsn = 'sqlite:' . $dbPath;
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = file_get_contents(__DIR__ . '/../migrations/init.sql');
$pdo->exec($sql);

echo "Initialized DB at: {$dbPath}\n";
