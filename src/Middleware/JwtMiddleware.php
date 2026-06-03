<?php
namespace App\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as Handler;

class JwtMiddleware implements Middleware
{
    private ?string $jwksUrl;

    public function __construct(?string $jwksUrl)
    {
        $this->jwksUrl = $jwksUrl;
    }

    public function process(Request $request, Handler $handler): Response
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return $handler->handle($request);
        }

        $token = substr($authHeader, 7);

        try {
            $payload = $this->decode($token);
            $userId  = $payload->sub ?? null;
            $request = $request
                ->withAttribute('userId', $userId)
                ->withAttribute('jwtPayload', $payload);
        } catch (\Throwable $e) {
            $response = new \Slim\Psr7\Response(401);
            $response->getBody()->write(json_encode([
                'error'   => 'Invalid JWT',
                'message' => $e->getMessage(),
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $handler->handle($request);
    }

    private function decode(string $token): object
    {
        if (!$this->jwksUrl) {
            // Decode without verification (dev mode — no JWKS URL configured)
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                throw new \RuntimeException('Malformed JWT');
            }
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), false);
            if (!$payload) {
                throw new \RuntimeException('Cannot decode JWT payload');
            }
            return $payload;
        }

        $ctx  = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $raw  = @file_get_contents($this->jwksUrl, false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException('Failed to fetch JWKS from ' . $this->jwksUrl);
        }

        $jwks    = json_decode($raw, true);
        $keySet  = JWK::parseKeySet($jwks);
        return JWT::decode($token, $keySet);
    }
}
