<?php

declare(strict_types=1);

namespace App\Validation;

use App\Domain\NuevoUsuario;
use App\Exception\ValidationException;

/**
 * Formularios de registro e inicio de sesión.
 */
final class AuthValidator
{
    use FieldRules;

    public const MAX_LENGTHS = ['nombre' => 150, 'email' => 254];

    public const PASSWORD_MIN = 10;

    /** bcrypt ignora lo que pasa de 72 bytes: mejor rechazarlo que truncarlo en silencio. */
    public const PASSWORD_MAX_BYTES = 72;

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function validateRegistro(array $input): NuevoUsuario
    {
        $errors = $this->rejectUnexpectedFields($input, ['nombre', 'email', 'password', 'password_confirmacion']);

        $nombre = $this->requiredText($input, 'nombre', self::MAX_LENGTHS['nombre'], $errors);
        $email = $this->requiredEmail($input, 'email', self::MAX_LENGTHS['email'], $errors);

        $password = $input['password'] ?? null;
        if (!is_string($password) || $password === '') {
            $errors['password'] = self::$required;
        } elseif (mb_strlen($password) < self::PASSWORD_MIN) {
            $errors['password'] = 'Debe tener al menos ' . self::PASSWORD_MIN . ' caracteres.';
        } elseif (strlen($password) > self::PASSWORD_MAX_BYTES) {
            $errors['password'] = 'Es demasiado larga (máximo ' . self::PASSWORD_MAX_BYTES . ' bytes).';
        } elseif (($input['password_confirmacion'] ?? null) !== $password) {
            $errors['password_confirmacion'] = 'Las contraseñas no coinciden.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var string $password */
        return new NuevoUsuario($nombre, mb_strtolower($email), $password);
    }

    /**
     * Solo comprueba que haya algo que verificar. Las reglas de la contraseña no
     * se aplican aquí, para no dar pistas sobre cuentas existentes.
     *
     * @param array<string, mixed> $input
     * @return array{email: string, password: string}
     * @throws ValidationException
     */
    public function validateLogin(array $input): array
    {
        $errors = [];
        $email = $input['email'] ?? null;
        $password = $input['password'] ?? null;

        if (!is_string($email) || trim($email) === '') {
            $errors['email'] = self::$required;
        }
        if (!is_string($password) || $password === '') {
            $errors['password'] = self::$required;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        /** @var string $email */
        /** @var string $password */
        return ['email' => mb_strtolower(trim($email)), 'password' => $password];
    }
}
