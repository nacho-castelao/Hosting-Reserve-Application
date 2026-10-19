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
    use FieldRules;

    /** Longitudes máximas: coinciden con las columnas VARCHAR de database/schema.sql. */
    public const MAX_LENGTHS = [
        'nombre_huesped' => 150,
        'email_huesped' => 254,
        'notas' => 1000,
    ];

    public const MAX_Q_LENGTH = 100;

    public const MAX_NOCHES = 90;

    private const CREATE_FIELDS = [
        'alojamiento_id', 'nombre_huesped', 'email_huesped', 'fecha_entrada', 'fecha_salida', 'notas',
    ];

    /**
     * Campos que existen en la tabla pero que solo decide el servidor. El importe
     * se calcula a partir del precio por noche del alojamiento.
     */
    private const SERVER_FIELDS = ['id', 'estado', 'importe', 'fecha_creacion'];

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
        $errors = $this->rejectUnexpectedFields($input, self::CREATE_FIELDS, self::SERVER_FIELDS);

        $alojamiento = $this->requiredId($input, 'alojamiento_id', $errors);
        $nombre = $this->requiredText($input, 'nombre_huesped', self::MAX_LENGTHS['nombre_huesped'], $errors);
        $email = $this->requiredEmail($input, 'email_huesped', self::MAX_LENGTHS['email_huesped'], $errors);

        $entrada = $this->requiredDate($input, 'fecha_entrada', $errors);
        if ($entrada !== null && $entrada < $this->today) {
            $errors['fecha_entrada'] = 'La fecha de entrada no puede ser anterior a hoy.';
        }

        $salida = $this->requiredDate($input, 'fecha_salida', $errors);
        if ($entrada !== null && $salida !== null) {
            if ($salida <= $entrada) {
                $errors['fecha_salida'] = 'La fecha de salida debe ser posterior a la de entrada.';
            } elseif ($entrada->diff($salida)->days > self::MAX_NOCHES) {
                // Una reserva pública muy larga bloquearía el alojamiento durante meses.
                $errors['fecha_salida'] = 'La estancia no puede superar las ' . self::MAX_NOCHES . ' noches.';
            }
        }

        $notas = $this->optionalNotes($input, $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        // Sin errores, todos los obligatorios tienen valor.
        return new NuevaReserva($alojamiento, $nombre, $email, $entrada, $salida, $notas);
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
            $errors['estado'] = self::$required;
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
     * @param array<string, string> $errors
     */
    private function requiredDate(array $input, string $field, array &$errors): ?DateTimeImmutable
    {
        $value = $input[$field] ?? null;
        if ($value === null || $value === '') {
            $errors[$field] = self::$required;

            return null;
        }

        $date = is_string($value) ? self::parseDate(trim($value)) : null;
        if ($date === null) {
            $errors[$field] = 'Debe ser una fecha válida con formato AAAA-MM-DD.';
        }

        return $date;
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
