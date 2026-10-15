<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Configuración leída de un fichero .env (KEY=VALUE) con un parser mínimo,
 * sin dependencias. Las variables de entorno reales tienen prioridad.
 */
final class Config
{
    /** @param array<string, string> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function fromEnvFile(string $path): self
    {
        $values = [];

        if (is_file($path) && is_readable($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                $values[$key] = self::unquote($value);
            }
        }

        foreach ($values as $key => $value) {
            $env = getenv($key);
            if ($env !== false) {
                $values[$key] = $env;
            }
        }

        return new self($values);
    }

    /** @param array<string, string> $values */
    public static function fromArray(array $values): self
    {
        return new self($values);
    }

    public function get(string $key, string $default = ''): string
    {
        return $this->values[$key] ?? $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!isset($this->values[$key])) {
            return $default;
        }

        return filter_var($this->values[$key], FILTER_VALIDATE_BOOLEAN);
    }

    private static function unquote(string $value): string
    {
        $length = strlen($value);
        if ($length >= 2) {
            $first = $value[0];
            if (($first === '"' || $first === "'") && $value[$length - 1] === $first) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
