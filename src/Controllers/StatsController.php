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

    public function handle(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = $params['page'] ?? null;
        $period = $params['period'] ?? '7d';

        if (!$page) {
            $response->getBody()->write(json_encode(['error' => 'page required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $days = $this->periodToDays($period);
        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $dates[] = (new \DateTime("-{$i} days"))->format('Y-m-d');
        }

        $stmt = $this->pdo->prepare("SELECT view_date, count FROM page_views WHERE page = :page AND view_date >= :from ORDER BY view_date ASC");
        $from = (new \DateTime("-" . ($days - 1) . " days"))->format('Y-m-d');
        $stmt->execute([':page' => $page, ':from' => $from]);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $data = [];
        $total = 0;
        foreach ($dates as $d) {
            $c = isset($rows[$d]) ? (int)$rows[$d] : 0;
            $data[] = ['date' => $d, 'count' => $c];
            $total += $c;
        }

        $response->getBody()->write(json_encode(['page' => $page, 'period' => $period, 'total' => $total, 'data' => $data]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function periodToDays(string $period): int
    {
        if ($period === 'day') return 1;
        if (preg_match('/^(\d+)d$/', $period, $m)) return (int)$m[1];
        return 7;
    }
}
