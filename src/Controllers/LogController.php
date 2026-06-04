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

    public function list(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $userId = $params['userId'] ?? null;
        $event = $params['event'] ?? null;
        $from = $params['from'] ?? null;
        $to = $params['to'] ?? null;
        $limit = isset($params['limit']) ? (int)$params['limit'] : 100;
        $limit = max(1, min(1000, $limit));
        $page = isset($params['page']) ? max(1, (int)$params['page']) : 1;
        $offset = ($page - 1) * $limit;

        $sql = "SELECT id, user_id, event_type, data, ip, user_agent, created_at FROM user_logs WHERE 1=1";
        $bind = [];
        if ($userId) { $sql .= " AND user_id = :user_id"; $bind[':user_id'] = $userId; }
        if ($event) { $sql .= " AND event_type = :event"; $bind[':event'] = $event; }
        if ($from) { $sql .= " AND date(created_at) >= :from"; $bind[':from'] = $from; }
        if ($to) { $sql .= " AND date(created_at) <= :to"; $bind[':to'] = $to; }

        $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($bind as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // total count for pagination
        $countSql = "SELECT COUNT(*) FROM user_logs WHERE 1=1";
        $countBind = [];
        if ($userId) { $countSql .= " AND user_id = :user_id"; $countBind[':user_id'] = $userId; }
        if ($event) { $countSql .= " AND event_type = :event"; $countBind[':event'] = $event; }
        if ($from) { $countSql .= " AND date(created_at) >= :from"; $countBind[':from'] = $from; }
        if ($to) { $countSql .= " AND date(created_at) <= :to"; $countBind[':to'] = $to; }

        $countStmt = $this->pdo->prepare($countSql);
        foreach ($countBind as $k => $v) { $countStmt->bindValue($k, $v); }
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        $response->getBody()->write(json_encode(['data' => $rows, 'meta' => ['total' => $total, 'page' => $page, 'limit' => $limit]]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
