<?php
namespace App\Session;

use SessionHandlerInterface;
use PDO;

class SQLiteSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;
    private string $table = 'sessions';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function open($savePath, $sessionName): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($id): string
    {
        $stmt = $this->pdo->prepare("SELECT payload FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['payload'] : '';
    }

    public function write($id, $data): bool
    {
        $now = time();
        $sql = "INSERT INTO {$this->table} (id, payload, last_access) VALUES (:id, :payload, :last_access)
            ON CONFLICT(id) DO UPDATE SET payload = excluded.payload, last_access = excluded.last_access";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([':id' => $id, ':payload' => $data, ':last_access' => $now]);
    }

    public function destroy($id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function gc($maxlifetime): int|false
    {
        $threshold = time() - $maxlifetime;
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE last_access < :threshold");
        return $stmt->execute([':threshold' => $threshold]);
    }
}
