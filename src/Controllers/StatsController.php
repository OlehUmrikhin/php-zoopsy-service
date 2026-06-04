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
        $userId = $params['userId'] ?? null;

        if (!$page) {
            $response->getBody()->write(json_encode(['error' => 'page required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $days = $this->periodToDays($period);
        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $dates[] = (new \DateTime("-{$i} days"))->format('Y-m-d');
        }

        $from = (new \DateTime("-" . ($days - 1) . " days"))->format('Y-m-d');
        
        if ($userId) {
            // Stats for a specific user: query from user_logs
            // Note: date(created_at) converts ISO8601 to Y-m-d
            $stmt = $this->pdo->prepare("
                SELECT date(created_at) as v_date, COUNT(*) as c
                FROM user_logs 
                WHERE event_type = 'page_view' 
                  AND user_id = :user_id 
                  AND data LIKE :page_pattern
                  AND date(created_at) >= :from
                GROUP BY date(created_at)
            ");
            $escapedPage = str_replace('/', '\\/', $page);
            $stmt->execute([
                ':user_id' => $userId, 
                ':page_pattern' => '%"page":"' . $escapedPage . '"%', 
                ':from' => $from
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $hostsRows = []; // Uniq hosts doesn't make much sense for a single user, or it's always max 1 per day
        } else {
            // Aggregated stats from page_views
            $stmt = $this->pdo->prepare("SELECT view_date, count FROM page_views WHERE page = :page AND view_date >= :from ORDER BY view_date ASC");
            $stmt->execute([':page' => $page, ':from' => $from]);
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            // Get hosts (unique visitors) per date from unique_page_views
            $stmtHosts = $this->pdo->prepare("SELECT view_date, COUNT(*) as hosts FROM unique_page_views WHERE page = :page AND view_date >= :from GROUP BY view_date");
            $stmtHosts->execute([':page' => $page, ':from' => $from]);
            $hostsRows = $stmtHosts->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        $data = [];
        $total = 0;
        $totalHosts = 0;
        foreach ($dates as $d) {
            $c = isset($rows[$d]) ? (int)$rows[$d] : 0;
            $h = isset($hostsRows[$d]) ? (int)$hostsRows[$d] : 0;
            $data[] = ['date' => $d, 'count' => $c, 'hosts' => $h];
            $total += $c;
            $totalHosts += $h;
        }

        $response->getBody()->write(json_encode(['page' => $page, 'period' => $period, 'total' => $total, 'total_hosts' => $totalHosts, 'data' => $data]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function periodToDays(string $period): int
    {
        if ($period === 'day') return 1;
        if (preg_match('/^(\d+)d$/', $period, $m)) return (int)$m[1];
        return 7;
    }
}
