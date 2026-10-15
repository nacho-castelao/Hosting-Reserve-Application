<?php

declare(strict_types=1);

namespace App\Core;

final class JsonResponse extends Response
{
    // JSON_HEX_* escapa < > & ' como \u003C… : defensa extra por si algún
    // navegador interpretase la respuesta como HTML.
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

    /** @param array<string, string> $headers */
    public function __construct(mixed $payload, int $status = 200, array $headers = [])
    {
        parent::__construct(
            json_encode($payload, self::FLAGS),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'] + $headers,
        );
    }

    /**
     * Respuesta de éxito con la forma {"data": ..., "meta": ...}.
     *
     * @param array<string, mixed>|null $meta
     * @param array<string, string> $headers
     */
    public static function data(mixed $data, int $status = 200, ?array $meta = null, array $headers = []): self
    {
        $payload = ['data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return new self($payload, $status, $headers);
    }

    /**
     * Respuesta de error con la forma {"error": {"message": ..., "fields": {...}}}.
     *
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $debug Solo con APP_DEBUG=true.
     */
    public static function error(
        string $message,
        int $status,
        array $fields = [],
        array $headers = [],
        ?array $debug = null,
    ): self {
        // "fields" siempre presente (objeto vacío si no hay) para que el cliente
        // pueda confiar en una forma de error constante.
        $error = ['message' => $message, 'fields' => (object) $fields];
        if ($debug !== null) {
            $error['debug'] = $debug;
        }

        return new self(['error' => $error], $status, $headers);
    }
}
