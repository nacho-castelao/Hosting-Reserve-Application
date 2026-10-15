<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Request;
use App\Exception\HttpException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    private static function post(string $body, string $contentType = 'application/json'): Request
    {
        return new Request('POST', '/api/reservas', [], ['content-type' => $contentType], '', $body);
    }

    public function testDecodesJsonObject(): void
    {
        $request = self::post('{"nombre_huesped":"Ana"}', 'application/json; charset=utf-8');

        self::assertSame(['nombre_huesped' => 'Ana'], $request->json());
    }

    #[DataProvider('badBodies')]
    public function testRejectsBadBodies(string $body, string $contentType, int $expectedStatus): void
    {
        try {
            self::post($body, $contentType)->json();
            self::fail('Se esperaba una HttpException');
        } catch (HttpException $e) {
            self::assertSame($expectedStatus, $e->getStatusCode());
        }
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function badBodies(): iterable
    {
        yield 'no es JSON' => ['nombre=Ana', 'application/x-www-form-urlencoded', 415];
        yield 'JSON roto' => ['{"nombre":', 'application/json', 400];
        yield 'escalar' => ['"hola"', 'application/json', 400];
        yield 'lista' => ['[1, 2]', 'application/json', 400];
        yield 'vacío' => ['', 'application/json', 400];
    }

    public function testRejectsOversizedBody(): void
    {
        $request = new Request('POST', '/api/reservas', [], [
            'content-type' => 'application/json',
            'content-length' => (string) (Request::MAX_BODY_BYTES + 1),
        ]);

        $this->expectExceptionObject(new HttpException(413, 'El cuerpo de la petición es demasiado grande.'));
        $request->json();
    }
}
