<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Reglas de campo comunes a todos los validadores. Cada regla anota su error
 * en $errors (campo => mensaje) y devuelve el valor normalizado o null.
 */
trait FieldRules
{
    private static string $required = 'Este campo es obligatorio.';

    /**
     * Lista blanca de campos: cualquier otro da error explícito, para que el
     * cliente sepa que lo ha enviado mal en vez de que se pierda en silencio.
     *
     * @param array<string, mixed> $input
     * @param list<string> $allowed
     * @param list<string> $serverFields Campos que existen pero que solo decide el servidor.
     * @return array<string, string>
     */
    private function rejectUnexpectedFields(array $input, array $allowed, array $serverFields = []): array
    {
        $errors = [];
        foreach (array_keys($input) as $field) {
            $field = (string) $field;
            if (in_array($field, $allowed, true)) {
                continue;
            }
            $errors[mb_substr($field, 0, 50)] = in_array($field, $serverFields, true)
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
    private function requiredText(array $input, string $field, int $max, array &$errors): ?string
    {
        $value = $input[$field] ?? null;
        if ($value === null) {
            $errors[$field] = self::$required;

            return null;
        }
        if (!is_string($value)) {
            $errors[$field] = 'Debe ser un texto.';

            return null;
        }

        $value = trim($value);
        if ($value === '') {
            $errors[$field] = self::$required;

            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            $errors[$field] = 'Contiene caracteres no permitidos.';

            return null;
        }
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
    private function requiredEmail(array $input, string $field, int $max, array &$errors): ?string
    {
        $email = $this->requiredText($input, $field, $max, $errors);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[$field] = 'Introduce un email válido.';

            return null;
        }

        return $email;
    }

    /**
     * Cantidad en euros > 0 con como máximo 2 decimales y 8 cifras enteras (DECIMAL(10,2)).
     * Admite número JSON o cadena numérica y la normaliza a "1234.50".
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function requiredMoney(array $input, string $field, array &$errors): ?string
    {
        $value = $input[$field] ?? null;
        if ($value === null || $value === '') {
            $errors[$field] = self::$required;

            return null;
        }
        // is_bool primero: en PHP true no es numérico, pero así el mensaje es claro.
        if (is_bool($value) || !(is_int($value) || is_float($value) || is_string($value))) {
            $errors[$field] = 'Debe ser un número.';

            return null;
        }

        $text = is_string($value) ? trim($value) : (string) $value;
        if (!is_numeric($text)) {
            $errors[$field] = 'Debe ser un número.';

            return null;
        }
        if ((float) $text <= 0) {
            $errors[$field] = 'Debe ser mayor que 0.';

            return null;
        }
        if (preg_match('/^\d{1,8}(\.\d{1,2})?$/', $text) !== 1) {
            $errors[$field] = 'Debe tener como máximo 2 decimales y un valor inferior a 100.000.000.';

            return null;
        }

        [$integer, $decimals] = array_pad(explode('.', $text, 2), 2, '');

        return (ltrim($integer, '0') ?: '0') . '.' . str_pad($decimals, 2, '0');
    }

    /**
     * Identificador entero positivo (número JSON o cadena de dígitos, como llega de un formulario).
     *
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function requiredId(array $input, string $field, array &$errors): ?int
    {
        $value = $input[$field] ?? null;
        if ($value === null || $value === '') {
            $errors[$field] = self::$required;

            return null;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', $value) === 1) {
            return (int) $value;
        }
        $errors[$field] = 'Identificador no válido.';

        return null;
    }
}
