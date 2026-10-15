<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Exception\MethodNotAllowedException;
use App\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->get('/api/reservas/{id}', static fn (Request $r, array $p): Response => new Response('show ' . $p['id']));
        $this->router->patch('/api/reservas/{id}/estado', static fn (Request $r, array $p): Response => new Response('patch'));
    }

    public function testMatchesNumericParameter(): void
    {
        $response = $this->router->dispatch(new Request('GET', '/api/reservas/42'));

        self::assertSame('show 42', $response->getBody());
    }

    public function testHeadUsesTheGetRoute(): void
    {
        self::assertSame(200, $this->router->dispatch(new Request('HEAD', '/api/reservas/42'))->getStatus());
    }

    /** Parámetros no numéricos, cero o con basura no casan con la ruta. */
    public function testNonNumericIdsAreNotFound(): void
    {
        foreach (['/api/reservas/abc', '/api/reservas/0', '/api/reservas/1%20OR%201=1', '/api/reservas/-1'] as $path) {
            try {
                $this->router->dispatch(new Request('GET', $path));
                self::fail("Se esperaba 404 para {$path}");
            } catch (NotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testWrongMethodReturns405WithAllowHeader(): void
    {
        try {
            $this->router->dispatch(new Request('DELETE', '/api/reservas/7/estado'));
            self::fail('Se esperaba 405');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(405, $e->getStatusCode());
            self::assertSame(['Allow' => 'PATCH'], $e->getHeaders());
        }
    }
}
