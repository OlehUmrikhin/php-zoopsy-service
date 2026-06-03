<?php
namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use PDO;

class VisitController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function handle(Request $request, Response $response): Response
    {
        $body = json_decode((string)$request->getBody(), true);
        $page = $body['page'] ?? null;

        if (!$page) {
            $response->getBody()->write(json_encode(['error' => 'page required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // userId: JWT attribute takes priority over body
        $userId = $request->getAttribute('userId') ?? $body['userId'] ?? null;

        $ip    = $request->getServerParams()['REMOTE_ADDR'] ?? $request->getHeaderLine('X-Forwarded-For');
        $ua    = $request->getHeaderLine('User-Agent');
        $today = (new \DateTime('now'))->format('Y-m-d');

        try {
            $this->pdo->beginTransaction();

            // --- Global counter ---
            $stmt = $this->pdo->prepare(
                "INSERT INTO page_views (page, view_date, count) VALUES (:page, :view_date, 1)
                 ON CONFLICT(page, view_date) DO UPDATE SET count = count + 1"
            );
            $stmt->execute([':page' => $page, ':view_date' => $today]);

            // --- Per-user counter ---
            if ($userId !== null) {
                $stmt2 = $this->pdo->prepare(
                    "INSERT INTO user_page_views (user_id, page, view_date, count) VALUES (:uid, :page, :view_date, 1)
                     ON CONFLICT(user_id, page, view_date) DO UPDATE SET count = count + 1"
                );
                $stmt2->execute([':uid' => $userId, ':page' => $page, ':view_date' => $today]);
            }

            // --- Log entry ---
            $id    = bin2hex(random_bytes(16));
            $stmt3 = $this->pdo->prepare(
                "INSERT INTO user_logs (id, user_id, event_type, data, ip, user_agent, created_at)
                 VALUES (:id, :user_id, 'page_view', :data, :ip, :ua, :created_at)"
            );
            $stmt3->execute([
                ':id'         => $id,
                ':user_id'    => $userId,
                ':data'       => json_encode(['page' => $page]),
                ':ip'         => $ip,
                ':ua'         => $ua,
                ':created_at' => (new \DateTime('now'))->format(DATE_ATOM),
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $response->getBody()->write(json_encode(['error' => 'db error', 'message' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['ok' => true, 'userId' => $userId]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
