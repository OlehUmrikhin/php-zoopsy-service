<?php
namespace App\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as Handler;

class ApiKeyMiddleware implements Middleware
{
    private ?string $apiKey;

    public function __construct(?string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (empty($this->apiKey)) {
            return $handler->handle($request);
        }

        $header = $request->getHeaderLine('X-API-KEY');
        if (hash_equals($this->apiKey, $header)) {
            return $handler->handle($request);
        }

        $response = new \Slim\Psr7\Response(401);
        $response->getBody()->write(json_encode(['error' => 'Unauthorized']));
        return $response->withHeader('Content-Type', 'application/json');
    }
}
