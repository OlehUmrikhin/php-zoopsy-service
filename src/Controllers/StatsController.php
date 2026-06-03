<?php
namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use PDO;

class StatsController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** GET /stats?page=...&period=7d — global stats */
    public function handle(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page   = $params['page'] ?? null;
        $period = $params['period'] ?? '7d';

        if (!$page) {
            $response->getBody()->write(json_encode(['error' => 'page required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $result = $this->buildStats('page_views', $page, $period, null);
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    /** GET /stats/user?page=...&period=7d&userId=... — per-user stats */
    public function handleUser(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page   = $params['page'] ?? null;
        $period = $params['period'] ?? '7d';

        // userId: JWT attribute takes priority over query param
        $userId = $request->getAttribute('userId') ?? $params['userId'] ?? null;

        if (!$page) {
            $response->getBody()->write(json_encode(['error' => 'page required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        if (!$userId) {
            $response->getBody()->write(json_encode(['error' => 'userId required (pass JWT or ?userId=)']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $result = $this->buildStats('user_page_views', $page, $period, $userId);
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function buildStats(string $table, string $page, string $period, ?string $userId): array
    {
        $days = $this->periodToDays($period);
        $from = (new \DateTime('-' . ($days - 1) . ' days'))->format('Y-m-d');

        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $dates[] = (new \DateTime("-{$i} days"))->format('Y-m-d');
        }

        if ($userId !== null) {
            $stmt = $this->pdo->prepare(
                "SELECT view_date, count FROM {$table}
                 WHERE user_id = :uid AND page = :page AND view_date >= :from
                 ORDER BY view_date ASC"
            );
            $stmt->execute([':uid' => $userId, ':page' => $page, ':from' => $from]);
        } else {
            $stmt = $this->pdo->prepare(
                "SELECT view_date, count FROM {$table}
                 WHERE page = :page AND view_date >= :from
                 ORDER BY view_date ASC"
            );
            $stmt->execute([':page' => $page, ':from' => $from]);
        }

        $rows  = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $data  = [];
        $total = 0;
        foreach ($dates as $d) {
            $c      = isset($rows[$d]) ? (int)$rows[$d] : 0;
            $data[] = ['date' => $d, 'count' => $c];
            $total += $c;
        }

        return [
            'page'   => $page,
            'period' => $period,
            'userId' => $userId,
            'total'  => $total,
            'data'   => $data,
        ];
    }

    private function periodToDays(string $period): int
    {
        if ($period === 'day') return 1;
        if (preg_match('/^(\d+)d$/', $period, $m)) return (int)$m[1];
        return 7;
    }
}
