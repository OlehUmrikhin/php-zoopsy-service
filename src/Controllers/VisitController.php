<?php
namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use PDO;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

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

        // userId: JWT middleware attribute → body fallback
        $jwtUser = $request->getAttribute('user');
        $userId  = $jwtUser->sub ?? $body['userId'] ?? null;

        // Inline JWT extraction if middleware wasn't applied (legacy callers)
        if (!$userId) {
            $auth    = $request->getHeaderLine('Authorization');
            $jwksUrl = $_ENV['JWKS_URL'] ?? $_ENV['CLERK_JWKS_URL'] ?? null;
            if ($auth && preg_match('/^Bearer\s+(.+)$/', $auth, $m) && $jwksUrl) {
                try {
                    $claims = $this->verifyJwtWithJwks($m[1], $jwksUrl);
                    $userId = $claims->sub ?? null;
                } catch (\Throwable) {}
            }
        }

        if (!$page) {
            $response->getBody()->write(json_encode(['error' => 'page required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $ip    = $request->getServerParams()['REMOTE_ADDR'] ?? $request->getHeaderLine('X-Forwarded-For');
        $ua    = $request->getHeaderLine('User-Agent');
        $ref   = $request->getHeaderLine('Referer') ?: null;
        $today = (new \DateTime('now'))->format('Y-m-d');

        try {
            $this->pdo->beginTransaction();

            // --- Global counter ---
            $stmt = $this->pdo->prepare(
                "INSERT INTO page_views (page, view_date, count) VALUES (:page, :view_date, 1)
                 ON CONFLICT(page, view_date) DO UPDATE SET count = count + 1"
            );
            $stmt->execute([':page' => $page, ':view_date' => $today]);

            // --- Unique visitor (session-based) ---
            $sessionId = session_id();
            $uniqKey   = $sessionId;
            $check = $this->pdo->prepare(
                "SELECT 1 FROM unique_page_views WHERE page = :page AND view_date = :view_date AND uniq_key = :uniq_key LIMIT 1"
            );
            $check->execute([':page' => $page, ':view_date' => $today, ':uniq_key' => $uniqKey]);
            if (!$check->fetchColumn()) {
                $ins = $this->pdo->prepare(
                    "INSERT INTO unique_page_views (page, view_date, uniq_key, user_id, session_id)
                     VALUES (:page, :view_date, :uniq_key, :user_id, :session_id)"
                );
                $ins->execute([
                    ':page'       => $page,
                    ':view_date'  => $today,
                    ':uniq_key'   => $uniqKey,
                    ':user_id'    => $userId,
                    ':session_id' => $sessionId,
                ]);
            }

            // --- Per-user counter (JWT userId) ---
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
                ':data'       => json_encode(['page' => $page, 'referer' => $ref]),
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

    private function verifyJwtWithJwks(string $token, string $jwksUrl): object
    {
        [$headerB64] = explode('.', $token, 2);
        $header = json_decode($this->base64UrlDecode($headerB64), true);
        if (empty($header['kid'])) throw new \RuntimeException('kid not found');

        $jwks = json_decode(file_get_contents($jwksUrl), true);
        $jwk  = null;
        foreach ($jwks['keys'] ?? [] as $k) {
            if (($k['kid'] ?? '') === $header['kid']) { $jwk = $k; break; }
        }
        if (!$jwk) throw new \RuntimeException('matching JWK not found');

        $pem = $this->getPemFromJwk($jwk['n'], $jwk['e']);
        return JWT::decode($token, new Key($pem, $jwk['alg'] ?? 'RS256'));
    }

    private function base64UrlDecode(string $input): string
    {
        $rem = strlen($input) % 4;
        if ($rem) $input .= str_repeat('=', 4 - $rem);
        return base64_decode(strtr($input, '-_', '+/'));
    }

    private function getPemFromJwk(string $n_b64, string $e_b64): string
    {
        $n = $this->base64UrlDecode($n_b64);
        $e = $this->base64UrlDecode($e_b64);
        $mod = chr(0x02) . $this->encodeLength(strlen($n)) . $n;
        $exp = chr(0x02) . $this->encodeLength(strlen($e)) . $e;
        $seq = chr(0x30) . $this->encodeLength(strlen($mod . $exp)) . $mod . $exp;
        $oid = hex2bin('300d06092a864886f70d0101010500');
        $bit = chr(0x03) . $this->encodeLength(strlen($seq) + 1) . chr(0x00) . $seq;
        $pub = chr(0x30) . $this->encodeLength(strlen($oid . $bit)) . $oid . $bit;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($pub), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function encodeLength(int $len): string
    {
        if ($len <= 0x7F) return chr($len);
        $hex = dechex($len);
        if (strlen($hex) % 2) $hex = '0' . $hex;
        $bin = hex2bin($hex);
        return chr(0x80 | strlen($bin)) . $bin;
    }
}
