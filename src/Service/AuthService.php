<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Rol;
use App\Domain\UsuarioActual;
use App\Exception\UnauthorizedException;
use App\Exception\ValidationException;
use App\Repository\DuplicateEmailException;
use App\Repository\UsuarioRepositoryInterface;
use App\Validation\AuthValidator;

/**
 * Registro, inicio de sesión y alta de administradores. No toca la sesión
 * HTTP: eso lo hace Core\Auth con el usuario que devuelve este servicio.
 */
final class AuthService
{
    public const CREDENCIALES_INCORRECTAS = 'Email o contraseña incorrectos.';

    /**
     * Hash de una contraseña aleatoria que nadie conoce. Se verifica contra él
     * cuando el email no existe, para que la respuesta tarde lo mismo y no
     * delate qué emails están registrados.
     */
    private const DUMMY_HASH = '$2y$10$eGa7ITM09S0Nr1ijkpdBPecjGvDj49ix6D71EC3CsRd3UbIJrV1Ru';

    private const EMAIL_DUPLICADO = 'Ya existe una cuenta con este email.';

    public function __construct(
        private readonly UsuarioRepositoryInterface $usuarios,
        private readonly AuthValidator $validator,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    /**
     * Registro público: siempre crea un anfitrión. No hay forma de elegir el rol.
     *
     * @param array<string, mixed> $input
     * @throws ValidationException|\App\Exception\TooManyRequestsException
     */
    public function registrar(array $input, string $ip): UsuarioActual
    {
        $this->rateLimiter->consumir(RateLimiter::REGISTRO_IP, $ip);
        $nuevo = $this->validator->validateRegistro($input);

        if ($this->usuarios->findByEmail($nuevo->email) !== null) {
            throw new ValidationException(['email' => self::EMAIL_DUPLICADO]);
        }

        try {
            $id = $this->usuarios->insert($nuevo->nombre, $nuevo->email, self::hash($nuevo->password), Rol::Anfitrion);
        } catch (DuplicateEmailException) {
            // Dos registros simultáneos con el mismo email: la restricción UNIQUE decide.
            throw new ValidationException(['email' => self::EMAIL_DUPLICADO]);
        }

        return new UsuarioActual($id, $nuevo->nombre, $nuevo->email, Rol::Anfitrion);
    }

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException|UnauthorizedException|\App\Exception\TooManyRequestsException
     */
    public function autenticar(array $input, string $ip): UsuarioActual
    {
        $credenciales = $this->validator->validateLogin($input);

        // Dos límites: por IP (un atacante probando muchas cuentas) y por email
        // (muchas IPs atacando una misma cuenta).
        $this->rateLimiter->consumir(RateLimiter::LOGIN_IP, $ip);
        $this->rateLimiter->consumir(RateLimiter::LOGIN_EMAIL, $credenciales['email']);

        $usuario = $this->usuarios->findByEmail($credenciales['email']);
        $valida = password_verify($credenciales['password'], $usuario['password_hash'] ?? self::DUMMY_HASH);
        if ($usuario === null || !$valida) {
            throw new UnauthorizedException(self::CREDENCIALES_INCORRECTAS);
        }

        // Si cambia el algoritmo o el coste por defecto de PHP, el hash se actualiza al entrar.
        if (password_needs_rehash($usuario['password_hash'], PASSWORD_DEFAULT)) {
            $this->usuarios->updatePassword($usuario['id'], self::hash($credenciales['password']));
        }
        $this->rateLimiter->limpiar(RateLimiter::LOGIN_EMAIL, $credenciales['email']);

        return new UsuarioActual($usuario['id'], $usuario['nombre'], $usuario['email'], Rol::from($usuario['rol']));
    }

    public function usuario(int $id): ?UsuarioActual
    {
        $row = $this->usuarios->findById($id);

        return $row === null ? null : new UsuarioActual($row['id'], $row['nombre'], $row['email'], Rol::from($row['rol']));
    }

    /**
     * Solo para bin/crear-admin.php (línea de comandos). Si el email ya existe,
     * promociona esa cuenta a administrador y le pone la nueva contraseña.
     *
     * @param array<string, mixed> $input Mismos campos que el registro.
     * @return array{usuario: UsuarioActual, creado: bool}
     * @throws ValidationException
     */
    public function crearAdmin(array $input): array
    {
        $nuevo = $this->validator->validateRegistro($input);
        $hash = self::hash($nuevo->password);

        $existente = $this->usuarios->findByEmail($nuevo->email);
        if ($existente !== null) {
            $this->usuarios->updateRol($existente['id'], Rol::Admin);
            $this->usuarios->updatePassword($existente['id'], $hash);

            return ['usuario' => new UsuarioActual($existente['id'], $existente['nombre'], $nuevo->email, Rol::Admin), 'creado' => false];
        }

        $id = $this->usuarios->insert($nuevo->nombre, $nuevo->email, $hash, Rol::Admin);

        return ['usuario' => new UsuarioActual($id, $nuevo->nombre, $nuevo->email, Rol::Admin), 'creado' => true];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function anfitriones(): array
    {
        return $this->usuarios->listarAnfitriones();
    }

    private static function hash(#[\SensitiveParameter] string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}
