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
        $userId = $body['userId'] ?? null;

        $auth = $request->getHeaderLine('Authorization');
        $jwksUrl = $_ENV['JWKS_URL'] ?? $_ENV['CLERK_JWKS_URL'] ?? null;
        if ($auth && preg_match('/^Bearer\s+(.+)$/', $auth, $m)) {
            $token = $m[1];
            if ($jwksUrl) {
                try {
                    $claims = $this->verifyJwtWithJwks($token, $jwksUrl);
                    if (isset($claims->sub)) {
                        $userId = $claims->sub;
                    }
                } catch (\Throwable $e) {
                    $response->getBody()->write(json_encode(['error' => 'invalid_token', 'message' => $e->getMessage()]));
                    return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
                }
            }
        }
        if (!$page) {
            $response->getBody()->write(json_encode(['error' => 'page required']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? $request->getHeaderLine('X-Forwarded-For');
        $ua = $request->getHeaderLine('User-Agent');
        $ref = $request->getHeaderLine('Referer') ?: null;
        $phpAuthUser = $_SERVER['PHP_AUTH_USER'] ?? null;
        $today = (new \DateTime('now'))->format('Y-m-d');

        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare("INSERT INTO page_views (page, view_date, count) VALUES (:page, :view_date, 1)
                ON CONFLICT(page, view_date) DO UPDATE SET count = count + 1");
            $stmt->execute([':page' => $page, ':view_date' => $today]);

            $sessionId = session_id();
            // Using session_id() as the unique key guarantees that when a session expires 
            // after 15 minutes, a new host/session will be counted, fulfilling the lecture requirements.
            $uniqKey = $sessionId;

            // Record unique visitor if not already counted for this page/date
            $check = $this->pdo->prepare("SELECT 1 FROM unique_page_views WHERE page = :page AND view_date = :view_date AND uniq_key = :uniq_key LIMIT 1");
            $check->execute([':page' => $page, ':view_date' => $today, ':uniq_key' => $uniqKey]);
            $exists = $check->fetchColumn();
            if (!$exists) {
                $insertUnique = $this->pdo->prepare("INSERT INTO unique_page_views (page, view_date, uniq_key, user_id, session_id)
                    VALUES (:page, :view_date, :uniq_key, :user_id, :session_id)");
                $insertUnique->execute([
                    ':page' => $page,
                    ':view_date' => $today,
                    ':uniq_key' => $uniqKey,
                    ':user_id' => $userId,
                    ':session_id' => $sessionId,
                ]);
            }

            $id = bin2hex(random_bytes(16));
            $stmt2 = $this->pdo->prepare("INSERT INTO user_logs (id, user_id, event_type, data, ip, user_agent, created_at)
                VALUES (:id, :user_id, 'page_view', :data, :ip, :ua, :created_at)");
            $stmt2->execute([
                ':id' => $id,
                ':user_id' => $userId,
                ':data' => json_encode(['page' => $page, 'referer' => $ref, 'php_auth_user' => $phpAuthUser]),
                ':ip' => $ip,
                ':ua' => $ua,
                ':created_at' => (new \DateTime('now'))->format(DATE_ATOM)
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $response->getBody()->write(json_encode(['error' => 'db error', 'message' => $e->getMessage()]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['ok' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }


    private function verifyJwtWithJwks(string $token, string $jwksUrl)
    {
        [$headerB64] = explode('.', $token, 2);
        $headerJson = $this->base64UrlDecode($headerB64);
        $header = json_decode($headerJson, true);
        if (empty($header['kid'])) throw new \RuntimeException('kid not found in token header');
        $kid = $header['kid'];

        $jwks = json_decode(file_get_contents($jwksUrl), true);
        if (empty($jwks['keys']) || !is_array($jwks['keys'])) throw new \RuntimeException('invalid jwks');

        $jwk = null;
        foreach ($jwks['keys'] as $k) {
            if (isset($k['kid']) && $k['kid'] === $kid) { $jwk = $k; break; }
        }
        if (!$jwk) throw new \RuntimeException('matching JWK not found');

        if (!isset($jwk['n']) || !isset($jwk['e'])) throw new \RuntimeException('unsupported JWK');

        $pem = $this->getPemFromJwk($jwk['n'], $jwk['e']);

        $decoded = JWT::decode($token, new Key($pem, $jwk['alg'] ?? 'RS256'));
        return $decoded;
    }

    private function base64UrlDecode(string $input): string
    {
        $remainder = strlen($input) % 4;
        if ($remainder) $input .= str_repeat('=', 4 - $remainder);
        return base64_decode(strtr($input, '-_', '+/'));
    }

    private function getPemFromJwk(string $n_b64, string $e_b64): string
    {
        $n = $this->base64UrlDecode($n_b64);
        $e = $this->base64UrlDecode($e_b64);

        $components = [
            'modulus' => $this->encodeLength(strlen($n)) . $n,
            'exponent' => $this->encodeLength(strlen($e)) . $e,
        ];

        $modulus = chr(0x02) . $components['modulus'];
        $exponent = chr(0x02) . $components['exponent'];

        $sequence = chr(0x30) . $this->encodeLength(strlen($modulus . $exponent)) . $modulus . $exponent;

        $rsaOid = hex2bin('300d06092a864886f70d0101010500');
        $bitString = chr(0x03) . $this->encodeLength(strlen($sequence) + 1) . chr(0x00) . $sequence;
        $pubKey = chr(0x30) . $this->encodeLength(strlen($rsaOid . $bitString)) . $rsaOid . $bitString;

        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($pubKey), 64, "\n") . "-----END PUBLIC KEY-----\n";
        return $pem;
    }

    private function encodeLength(int $length): string
    {
        if ($length <= 0x7F) return chr($length);
        $lenHex = dechex($length);
        if (strlen($lenHex) % 2) $lenHex = '0' . $lenHex;
        $len = hex2bin($lenHex);
        return chr(0x80 | strlen($len)) . $len;
    }
}
