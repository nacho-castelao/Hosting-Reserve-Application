<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Domain\Rol;
use App\Exception\TooManyRequestsException;
use App\Exception\UnauthorizedException;
use App\Exception\ValidationException;
use App\Service\AuthService;
use App\Service\RateLimiter;
use App\Validation\AuthValidator;
use PHPUnit\Framework\TestCase;
use Tests\Fake\InMemoryLimitePeticionRepository;
use Tests\Fake\InMemoryUsuarioRepository;

final class AuthServiceTest extends TestCase
{
    private const IP = '203.0.113.7';
    private const PASSWORD = 'una-contraseña-larga';

    private InMemoryUsuarioRepository $usuarios;
    private AuthService $service;

    protected function setUp(): void
    {
        $this->usuarios = new InMemoryUsuarioRepository();
        $this->service = new AuthService(
            $this->usuarios,
            new AuthValidator(),
            new RateLimiter(new InMemoryLimitePeticionRepository()),
        );
    }

    /** @return array<string, string> */
    private static function registro(array $override = []): array
    {
        return $override + [
            'nombre' => 'Lucía Navarro',
            'email' => 'Lucia@Example.com',
            'password' => self::PASSWORD,
            'password_confirmacion' => self::PASSWORD,
        ];
    }

    public function testRegistrationAlwaysCreatesAHostWithAHashedPassword(): void
    {
        $usuario = $this->service->registrar(self::registro(), self::IP);

        self::assertSame(Rol::Anfitrion, $usuario->rol);
        self::assertSame('lucia@example.com', $usuario->email, 'El email se normaliza a minúsculas.');
        $guardado = $this->usuarios->rows[$usuario->id];
        self::assertNotSame(self::PASSWORD, $guardado['password_hash']);
        self::assertTrue(password_verify(self::PASSWORD, $guardado['password_hash']));
    }

    public function testRoleCannotBeChosenOnRegistration(): void
    {
        try {
            $this->service->registrar(self::registro(['rol' => 'admin']), self::IP);
            self::fail('Se esperaba una ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['rol' => 'Campo no permitido.'], $e->getFields());
            self::assertSame([], $this->usuarios->rows);
        }
    }

    public function testDuplicateEmailIsAValidationError(): void
    {
        $this->service->registrar(self::registro(), self::IP);

        try {
            $this->service->registrar(self::registro(['email' => 'lucia@example.com']), self::IP);
            self::fail('Se esperaba una ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['email' => 'Ya existe una cuenta con este email.'], $e->getFields());
        }
    }

    public function testPasswordRules(): void
    {
        try {
            $this->service->registrar(self::registro(['password' => 'corta', 'password_confirmacion' => 'corta']), self::IP);
            self::fail('Se esperaba una ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('Debe tener al menos 10 caracteres.', $e->getFields()['password']);
        }

        try {
            $this->service->registrar(self::registro(['password_confirmacion' => 'otra-contraseña']), self::IP);
            self::fail('Se esperaba una ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame(['password_confirmacion' => 'Las contraseñas no coinciden.'], $e->getFields());
        }
    }

    public function testLoginWithValidCredentials(): void
    {
        $this->service->registrar(self::registro(), self::IP);

        $usuario = $this->service->autenticar(['email' => ' LUCIA@example.com ', 'password' => self::PASSWORD], self::IP);

        self::assertSame('Lucía Navarro', $usuario->nombre);
    }

    public function testWrongPasswordAndUnknownEmailGiveTheSameError(): void
    {
        $this->service->registrar(self::registro(), self::IP);

        $mensajes = [];
        foreach (['lucia@example.com', 'nadie@example.com'] as $email) {
            try {
                $this->service->autenticar(['email' => $email, 'password' => 'incorrecta-123'], self::IP);
                self::fail('Se esperaba una UnauthorizedException.');
            } catch (UnauthorizedException $e) {
                $mensajes[] = $e->getMessage();
            }
        }

        self::assertSame([AuthService::CREDENCIALES_INCORRECTAS, AuthService::CREDENCIALES_INCORRECTAS], $mensajes);
    }

    public function testDummyHashIsARealHash(): void
    {
        // Si el hash ficticio no fuese válido, password_verify volvería al instante y el
        // tiempo de respuesta delataría qué emails existen.
        $info = password_get_info((new \ReflectionClassConstant(AuthService::class, 'DUMMY_HASH'))->getValue());

        self::assertSame(PASSWORD_BCRYPT, $info['algo']);
    }

    public function testTooManyFailedLoginsForAnEmailAre429(): void
    {
        $this->service->registrar(self::registro(), self::IP);

        for ($i = 0; $i < 5; $i++) {
            try {
                $this->service->autenticar(['email' => 'lucia@example.com', 'password' => 'incorrecta-123'], "198.51.100.{$i}");
            } catch (UnauthorizedException) {
            }
        }

        // Ni siquiera la contraseña correcta pasa mientras dura el bloqueo, aunque llegue desde otra IP.
        $this->expectException(TooManyRequestsException::class);
        $this->service->autenticar(['email' => 'lucia@example.com', 'password' => self::PASSWORD], '198.51.100.99');
    }

    public function testSuccessfulLoginClearsTheEmailCounter(): void
    {
        $this->service->registrar(self::registro(), self::IP);
        $fallar = function (): void {
            try {
                $this->service->autenticar(['email' => 'lucia@example.com', 'password' => 'incorrecta-123'], self::IP);
            } catch (UnauthorizedException) {
            }
        };

        for ($i = 0; $i < 4; $i++) {
            $fallar();
        }
        $this->service->autenticar(['email' => 'lucia@example.com', 'password' => self::PASSWORD], self::IP);
        for ($i = 0; $i < 4; $i++) {
            $fallar();
        }

        $this->addToAssertionCount(1);
    }

    public function testOutdatedHashIsUpgradedOnLogin(): void
    {
        $usuario = $this->service->registrar(self::registro(), self::IP);
        $viejo = password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]);
        $this->usuarios->rows[$usuario->id]['password_hash'] = $viejo;

        $this->service->autenticar(['email' => 'lucia@example.com', 'password' => self::PASSWORD], self::IP);

        self::assertNotSame($viejo, $this->usuarios->rows[$usuario->id]['password_hash']);
        self::assertFalse(password_needs_rehash($this->usuarios->rows[$usuario->id]['password_hash'], PASSWORD_DEFAULT));
    }

    public function testCreateAdminPromotesAnExistingAccount(): void
    {
        $anfitrion = $this->service->registrar(self::registro(), self::IP);

        $resultado = $this->service->crearAdmin(self::registro());

        self::assertFalse($resultado['creado']);
        self::assertSame($anfitrion->id, $resultado['usuario']->id);
        self::assertSame(Rol::Admin->value, $this->usuarios->rows[$anfitrion->id]['rol']);
    }

    public function testCreateAdminCreatesANewAccount(): void
    {
        $resultado = $this->service->crearAdmin(self::registro(['email' => 'admin@example.com']));

        self::assertTrue($resultado['creado']);
        self::assertTrue($resultado['usuario']->esAdmin());
    }
}
