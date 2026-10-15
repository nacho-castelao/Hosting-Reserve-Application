<?php

declare(strict_types=1);

namespace App\Core;

use App\Exception\HttpException;
use JsonException;

final class Request
{
    /** Tamaño máximo del cuerpo de la petición (bytes). Una reserva ocupa < 2 KB. */
    public const MAX_BODY_BYTES = 16 * 1024;

    private ?string $body;

    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers Nombres en minúsculas.
     * @param string $basePath Prefijo público de la aplicación ('' si está en la raíz).
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query = [],
        private readonly array $headers = [],
        public readonly string $basePath = '',
        ?string $body = null,
    ) {
        $this->body = $body;
    }

    public static function fromGlobals(string $configuredBaseUrl = ''): self
    {
        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uriPath = is_string($uriPath) ? rawurldecode($uriPath) : '/';

        $basePath = $configuredBaseUrl !== ''
            ? rtrim($configuredBaseUrl, '/')
            : self::detectBasePath($uriPath, (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        $path = $uriPath;
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . trim($path, '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $headers[$name] = $_SERVER[$key];
            }
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $headers,
            $basePath,
        );
    }

    /**
     * Calcula el prefijo público a partir del script ejecutado. Contempla que el
     * hosting reescriba la raíz del proyecto hacia public/ (el /public no aparece en la URL).
     */
    private static function detectBasePath(string $uriPath, string $scriptName): string
    {
        $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($scriptDir === '' || $scriptDir === '.') {
            return '';
        }
        if ($uriPath === $scriptDir || str_starts_with($uriPath, $scriptDir . '/')) {
            return $scriptDir;
        }
        if (str_ends_with($scriptDir, '/public')) {
            $projectDir = substr($scriptDir, 0, -strlen('/public'));
            if ($projectDir === '' || $uriPath === $projectDir || str_starts_with($uriPath, $projectDir . '/')) {
                return $projectDir;
            }
        }

        return '';
    }

    public function isApi(): bool
    {
        return $this->path === '/api' || str_starts_with($this->path, '/api/');
    }

    /** @return array<string, mixed> Parámetros de la query string tal cual (pueden ser arrays). */
    public function queryParams(): array
    {
        return $this->query;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isJson(): bool
    {
        $contentType = $this->header('content-type') ?? '';
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        return $mediaType === 'application/json';
    }

    /**
     * Cuerpo JSON de la petición como objeto (array asociativo).
     *
     * @return array<string, mixed>
     * @throws HttpException 415 si no es JSON, 413 si es demasiado grande, 400 si está mal formado.
     */
    public function json(): array
    {
        if (!$this->isJson()) {
            throw new HttpException(415, 'El cuerpo de la petición debe enviarse como application/json.');
        }

        try {
            $data = json_decode($this->rawBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new HttpException(400, 'El cuerpo de la petición no es un JSON válido.');
        }

        // Se exige un objeto JSON: escalares y listas no tienen sentido aquí.
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new HttpException(400, 'El cuerpo de la petición debe ser un objeto JSON.');
        }

        return $data;
    }

    private function rawBody(): string
    {
        if ($this->body !== null) {
            return $this->body;
        }

        $declared = $this->header('content-length');
        if ($declared !== null && (int) $declared > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'El cuerpo de la petición es demasiado grande.');
        }

        // Se lee como máximo el límite + 1 byte para detectar cuerpos que mientan en Content-Length.
        $raw = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1);
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, 'El cuerpo de la petición es demasiado grande.');
        }

        return $this->body = $raw;
    }
}
