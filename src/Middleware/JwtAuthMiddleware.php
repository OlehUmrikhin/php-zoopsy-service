<?php
namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;

class JwtAuthMiddleware implements Middleware
{
    private string $jwksUrl;

    public function __construct(string $jwksUrl)
    {
        $this->jwksUrl = $jwksUrl;
    }

    public function process(Request $request, Handler $handler): Response
    {
        $header = $request->getHeaderLine('Authorization');
        
        // If no token is provided, just proceed as an anonymous guest
        if (empty($header) || !preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return $handler->handle($request);
        }

        $token = $matches[1];

        try {
            if (empty($this->jwksUrl)) {
                // Dev mode: decode without signature verification
                $parts = explode('.', $token);
                if (count($parts) !== 3) throw new \RuntimeException('Malformed JWT');
                $pad     = strlen($parts[1]) % 4;
                $decoded = json_decode(base64_decode(strtr($parts[1], '-_', '+/') . ($pad ? str_repeat('=', 4 - $pad) : '')));
                if (!$decoded) throw new \RuntimeException('Cannot decode JWT payload');
            } else {
                $jwksJson = file_get_contents($this->jwksUrl);
                $jwks     = json_decode($jwksJson, true);
                $keys     = JWK::parseKeySet($jwks);
                $decoded  = JWT::decode($token, $keys);
            }
            
            // Set session data
            $_SESSION['user_id'] = $decoded->sub;
            if (isset($decoded->user_id)) {
                $_SESSION['clerk_user_id'] = $decoded->user_id;
            }

            // Optionally attach it to request attributes
            $request = $request->withAttribute('user', $decoded);

            return $handler->handle($request);
        } catch (\Exception $e) {
            return $this->unauthorized('Invalid Token: ' . $e->getMessage());
        }
    }

    private function unauthorized(string $message): Response
    {
        $response = new \Slim\Psr7\Response(401);
        $response->getBody()->write(json_encode(['error' => 'Unauthorized', 'message' => $message]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
