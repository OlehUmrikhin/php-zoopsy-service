<?php
namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use PDO;

class LogController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function handle(Request $request, Response $response): Response
    {
        $body = json_decode((string)$request->getBody(), true);
        $userId = $body['userId'] ?? null;
        $event = $body['event'] ?? null;
        $meta = $body['meta'] ?? null;

        if (!$event) {
            $response->getBody()->write(json_encode(['error' => 'event required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? $request->getHeaderLine('X-Forwarded-For');
        $ua = $request->getHeaderLine('User-Agent');

        try {
            $id = bin2hex(random_bytes(16));
            $stmt = $this->pdo->prepare("INSERT INTO user_logs (id, user_id, event_type, data, ip, user_agent, created_at)
                VALUES (:id, :user_id, :event_type, :data, :ip, :ua, :created_at)");
            $stmt->execute([
                ':id' => $id,
                ':user_id' => $userId,
                ':event_type' => $event,
                ':data' => json_encode($meta),
                ':ip' => $ip,
                ':ua' => $ua,
                ':created_at' => (new \DateTime('now'))->format(DATE_ATOM)
            ]);
        } catch (\Throwable $e) {
            $response->getBody()->write(json_encode(['error' => 'db error', 'message' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['id' => $id]));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }
}
