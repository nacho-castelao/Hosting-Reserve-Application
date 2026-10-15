<?php

declare(strict_types=1);

namespace App\Validation;

use App\Domain\EstadoReserva;
use App\Domain\FiltroReservas;
use App\Domain\NuevaReserva;
use App\Exception\ValidationException;
use DateTimeImmutable;

/**
 * Validación en servidor de todo lo que envía el cliente. Acumula todos los
 * errores y los lanza juntos en una ValidationException (campo => mensaje).
 */
final class ReservaValidator
{
    /** Longitudes máximas: coinciden con las columnas VARCHAR de database/schema.sql. */
    public const MAX_LENGTHS = [
        'nombre_huesped' => 150,
        'email_huesped' => 254,
        'nombre_alojamiento' => 200,
        'notas' => 1000,
    ];

    public const MAX_Q_LENGTH = 100;

    private const CREATE_FIELDS = [
        'nombre_huesped', 'email_huesped', 'nombre_alojamiento',
        'fecha_entrada', 'fecha_salida', 'importe', 'notas',
    ];

    /** Campos que existen en la tabla pero que solo decide el servidor. */
    private const SERVER_FIELDS = ['id', 'estado', 'fecha_creacion'];

    private const REQUIRED = 'Este campo es obligatorio.';

    private readonly DateTimeImmutable $today;

    /** @param DateTimeImmutable|null $today Inyectable para que los tests no dependan del reloj. */
    public function __construct(?DateTimeImmutable $today = null)
    {
        $this->today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
    }

    /**
     * @param array<string, mixed> $input Cuerpo JSON ya decodificado.
     * @throws ValidationException
     */
    public function validateCreate(array $input): NuevaReserva
    {
        $errors = $this->rejectUnexpectedFields($input, self::CREATE_FIELDS);

        $nombre = $this->requiredText($input, 'nombre_huesped', $errors);
        $alojamiento = $this->requiredText($input, 'nombre_alojamiento', $errors);

        $email = $this->requiredText($input, 'email_huesped', $errors);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email_huesped'] = 'Introduce un email válido.';
            $email = null;
        }

        $entrada = $this->requiredDate($input, 'fecha_entrada', $errors);
        if ($entrada !== null && $entrada < $this->today) {
            $errors['fecha_entrada'] = 'La fecha de entrada no puede ser anterior a hoy.';
        }

        $salida = $this->requiredDate($input, 'fecha_salida', $errors);
        if ($entrada !== null && $salida !== null && $salida <= $entrada) {
            $errors['fecha_salida'] = 'La fecha de salida debe ser posterior a la de entrada.';
        }

        $importe = $this->importe($input, $errors);
        $notas = $this->optionalNotes($input, $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // Sin errores, todos los obligatorios tienen valor.
        return new NuevaReserva($nombre, $email, $alojamiento, $entrada, $salida, $importe, $notas);
    }

    /**
     * Cuerpo del PATCH de estado: {"estado": "confirmada" | "cancelada"}.
     * Aquí solo se valida que sea un estado existente; si la transición es
     * legal lo decide el servicio (409).
     *
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function validateEstado(array $input): EstadoReserva
    {
        $errors = $this->rejectUnexpectedFields($input, ['estado']);
        $estado = null;

        $value = $input['estado'] ?? null;
        if ($value === null || $value === '') {
            $errors['estado'] = self::REQUIRED;
        } elseif (!is_string($value) || ($estado = EstadoReserva::tryFrom($value)) === null) {
            $errors['estado'] = 'Estado no válido. Valores admitidos: ' . implode(', ', EstadoReserva::values()) . '.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var EstadoReserva $estado */
        return $estado;
    }

    /**
     * Filtros del listado (query string). Parámetros desconocidos se ignoran de
     * forma explícita; los conocidos con valor inválido devuelven 400.
     *
     * @param array<string, mixed> $query
     * @throws ValidationException (400)
     */
    public function validateFiltros(array $query): FiltroReservas
    {
        $errors = [];
        $read = static function (string $key) use ($query, &$errors): ?string {
            $value = $query[$key] ?? null;
            if ($value === null) {
                return null;
            }
            if (!is_string($value)) {   // p. ej. ?estado[]=x
                $errors[$key] = 'Valor no válido.';

                return null;
            }
            $value = trim($value);

            return $value === '' ? null : $value;
        };

        $estado = null;
        $rawEstado = $read('estado');
        if ($rawEstado !== null && ($estado = EstadoReserva::tryFrom($rawEstado)) === null) {
            $errors['estado'] = 'Estado no válido. Valores admitidos: ' . implode(', ', EstadoReserva::values()) . '.';
        }

        $desde = null;
        $rawDesde = $read('desde');
        if ($rawDesde !== null && ($desde = self::parseDate($rawDesde)) === null) {
            $errors['desde'] = 'Debe ser una fecha válida con formato AAAA-MM-DD.';
        }

        $hasta = null;
        $rawHasta = $read('hasta');
        if ($rawHasta !== null && ($hasta = self::parseDate($rawHasta)) === null) {
            $errors['hasta'] = 'Debe ser una fecha válida con formato AAAA-MM-DD.';
        }
        if ($desde !== null && $hasta !== null && $hasta < $desde) {
            $errors['hasta'] = 'La fecha "hasta" no puede ser anterior a "desde".';
        }

        $q = $read('q');
        if ($q !== null && mb_strlen($q) > self::MAX_Q_LENGTH) {
            $errors['q'] = 'La búsqueda admite como máximo ' . self::MAX_Q_LENGTH . ' caracteres.';
        }

        $limit = FiltroReservas::LIMIT_DEFAULT;
        $rawLimit = $read('limit');
        if ($rawLimit !== null) {
            if (!ctype_digit($rawLimit) || (int) $rawLimit < 1) {
                $errors['limit'] = 'Debe ser un número entero mayor que 0.';
            } else {
                // Se acepta cualquier valor pero se limita al máximo permitido.
                $limit = min((int) $rawLimit, FiltroReservas::LIMIT_MAX);
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors, 'Los filtros indicados no son válidos.', 400);
        }

        return new FiltroReservas($estado, $desde, $hasta, $q, $limit);
    }

    /**
     * Fecha estricta Y-m-d. El "!" pone a cero la hora y la comparación de ida y
     * vuelta rechaza fechas que PHP "corrige" en silencio (2026-02-30 → 2026-03-02).
     */
    public static function parseDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return ($date !== false && $date->format('Y-m-d') === $value) ? $date : null;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $allowed
     * @return array<string, string>
     */
    private function rejectUnexpectedFields(array $input, array $allowed): array
    {
        $errors = [];
        foreach (array_keys($input) as $field) {
            $field = (string) $field;
            if (in_array($field, $allowed, true)) {
                continue;
            }
            $errors[mb_substr($field, 0, 50)] = in_array($field, self::SERVER_FIELDS, true)
                ? 'Este campo lo asigna el servidor y no se puede enviar.'
                : 'Campo no permitido.';
        }

        return $errors;
    }

    /**
     * Texto obligatorio de una línea: string, recortado, no vacío, sin
     * caracteres de control y dentro de la longitud máxima.
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function requiredText(array $input, string $field, array &$errors): ?string
    {
        $value = $input[$field] ?? null;
        if ($value === null) {
            $errors[$field] = self::REQUIRED;

            return null;
        }
        if (!is_string($value)) {
            $errors[$field] = 'Debe ser un texto.';

            return null;
        }

        $value = trim($value);
        if ($value === '') {
            $errors[$field] = self::REQUIRED;

            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $errors[$field] = 'Contiene caracteres no permitidos.';

            return null;
        }

        $max = self::MAX_LENGTHS[$field];
        if (mb_strlen($value) > $max) {
            $errors[$field] = "No puede superar los {$max} caracteres.";

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function requiredDate(array $input, string $field, array &$errors): ?DateTimeImmutable
    {
        $value = $input[$field] ?? null;
        if ($value === null || $value === '') {
            $errors[$field] = self::REQUIRED;

            return null;
        }

        $date = is_string($value) ? self::parseDate(trim($value)) : null;
        if ($date === null) {
            $errors[$field] = 'Debe ser una fecha válida con formato AAAA-MM-DD.';
        }

        return $date;
    }

    /**
     * Importe >= 0 con como máximo 2 decimales y 8 cifras enteras (DECIMAL(10,2)).
     * Admite número JSON o cadena numérica y lo normaliza a "1234.50".
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function importe(array $input, array &$errors): ?string
    {
        $value = $input['importe'] ?? null;
        if ($value === null || $value === '') {
            $errors['importe'] = self::REQUIRED;

            return null;
        }
        // is_bool primero: en PHP true no es numérico, pero así el mensaje es claro.
        if (is_bool($value) || !(is_int($value) || is_float($value) || is_string($value))) {
            $errors['importe'] = 'Debe ser un número.';

            return null;
        }

        $text = is_string($value) ? trim($value) : (string) $value;
        if (!is_numeric($text)) {
            $errors['importe'] = 'Debe ser un número.';

            return null;
        }
        if ((float) $text < 0) {
            $errors['importe'] = 'El importe no puede ser negativo.';

            return null;
        }
        if (preg_match('/^\d{1,8}(\.\d{1,2})?$/', $text) !== 1) {
            $errors['importe'] = 'Debe tener como máximo 2 decimales y un valor inferior a 100.000.000.';

            return null;
        }

        [$integer, $decimals] = array_pad(explode('.', $text, 2), 2, '');

        return (ltrim($integer, '0') ?: '0') . '.' . str_pad($decimals, 2, '0');
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function optionalNotes(array $input, array &$errors): ?string
    {
        $value = $input['notas'] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            $errors['notas'] = 'Debe ser un texto.';

            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // En las notas se permiten saltos de línea y tabuladores, no otros caracteres de control.
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            $errors['notas'] = 'Contiene caracteres no permitidos.';

            return null;
        }

        $max = self::MAX_LENGTHS['notas'];
        if (mb_strlen($value) > $max) {
            $errors['notas'] = "No puede superar los {$max} caracteres.";

            return null;
        }

        return $value;
    }
}
