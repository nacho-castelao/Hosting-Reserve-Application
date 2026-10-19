<?php

declare(strict_types=1);

/*
 * Crea un administrador, o promociona a administrador una cuenta existente.
 * Es la única forma de obtener ese rol: la web solo registra anfitriones.
 *
 * Uso (desde la raíz del proyecto, con el .env configurado):
 *   php bin/crear-admin.php
 *
 * Pide nombre, email y contraseña por teclado (la contraseña sin eco en Linux/macOS).
 */

use App\Core\Config;
use App\Core\Database;
use App\Exception\ValidationException;
use App\Repository\LimitePeticionRepository;
use App\Repository\UsuarioRepository;
use App\Service\AuthService;
use App\Service\RateLimiter;
use App\Validation\AuthValidator;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$config = Config::fromEnvFile($root . '/.env');
date_default_timezone_set($config->get('APP_TIMEZONE', 'Europe/Madrid'));

/** Lee una línea de la entrada estándar. */
function ask(string $label): string
{
    fwrite(STDOUT, $label);
    $line = fgets(STDIN);

    return $line === false ? '' : rtrim($line, "\r\n");
}

/** Lee una contraseña sin mostrarla en pantalla (si el terminal lo permite). */
function askSecret(string $label): string
{
    $canHide = DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN);
    if ($canHide) {
        shell_exec('stty -echo');
    }
    try {
        $value = ask($label);
    } finally {
        if ($canHide) {
            shell_exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }
    }

    return $value;
}

fwrite(STDOUT, "Alta de administrador\n=====================\n");
if (DIRECTORY_SEPARATOR !== '/') {
    fwrite(STDOUT, "Aviso: en Windows la contraseña se verá al escribirla.\n");
}

$input = [
    'nombre' => ask('Nombre: '),
    'email' => ask('Email: '),
    'password' => askSecret('Contraseña (mín. ' . AuthValidator::PASSWORD_MIN . ' caracteres): '),
];
$input['password_confirmacion'] = askSecret('Repite la contraseña: ');

$pdo = Database::connect($config);
$service = new AuthService(
    new UsuarioRepository($pdo),
    new AuthValidator(),
    new RateLimiter(new LimitePeticionRepository($pdo)),
);

try {
    $resultado = $service->crearAdmin($input);
} catch (ValidationException $e) {
    fwrite(STDERR, "\nNo se ha creado el administrador:\n");
    foreach ($e->getFields() as $campo => $mensaje) {
        fwrite(STDERR, "  - {$campo}: {$mensaje}\n");
    }
    exit(1);
}

$usuario = $resultado['usuario'];
fwrite(STDOUT, sprintf(
    "\n%s: %s <%s> (id %d). Ya puedes entrar en /login.\n",
    $resultado['creado'] ? 'Administrador creado' : 'Cuenta existente promocionada a administrador',
    $usuario->nombre,
    $usuario->email,
    $usuario->id,
));
