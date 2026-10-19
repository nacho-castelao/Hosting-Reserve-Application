<?php

declare(strict_types=1);

namespace App\Core;

use App\Controller\Api\ReservaController as ApiReservaController;
use App\Controller\Web\ReservaController as WebReservaController;
use App\Repository\PdoTransactionManager;
use App\Repository\ReservaEventoRepository;
use App\Repository\ReservaRepository;
use App\Service\ReservaService;
use App\Validation\ReservaValidator;
use PDO;
use Throwable;

/**
 * Arranque de la aplicación: configuración, manejo de errores, cabeceras de
 * seguridad, rutas y construcción (perezosa) de las dependencias.
 */
final class App
{
    private Config $config;
    private ?PDO $pdo = null;
    private ?View $view = null;
    private string $basePath = '';

    public function __construct(private readonly string $rootDir)
    {
        $this->config = Config::fromEnvFile($rootDir . '/.env');
    }

    public function run(): void
    {
        $debug = $this->config->bool('APP_DEBUG');
        date_default_timezone_set($this->config->get('APP_TIMEZONE', 'Europe/Madrid'));

        $errors = new ErrorHandler($debug, $this->rootDir . '/var/log/app.log');
        $errors->register();
        SecurityHeaders::send();

        try {
            $request = Request::fromGlobals($this->config->get('BASE_URL'));
            $errors->setRequest($request);
            $this->basePath = $request->basePath;
            Session::configure($request->basePath);

            $errors->setHtmlRenderer(fn (int $status, string $message): Response => $this->view()->render(
                $status === 404 ? 'errors/404.html.twig' : 'errors/error.html.twig',
                ['status' => $status, 'message' => $message],
                $status,
            ));

            $response = $this->router()->dispatch($request);
        } catch (Throwable $e) {
            $response = $errors->handle($e);
        }

        $response->send();
    }

    private function router(): Router
    {
        $router = new Router();

        // Los controladores se crean dentro de cada closure: una petición que no
        // los necesita (p. ej. un 404) no abre conexión a la base de datos.
        $api = fn (): ApiReservaController => new ApiReservaController($this->reservaService());

        $router->get('/api/reservas', fn (Request $r) => $api()->index($r));
        $router->post('/api/reservas', $this->csrfProtected(fn (Request $r) => $api()->store($r)));
        // {id} solo acepta dígitos, así que esta ruta no choca con la del detalle.
        $router->get('/api/reservas/resumen', fn () => $api()->resumen());
        $router->get('/api/reservas/{id}', fn (Request $r, array $p) => $api()->show($r, $p));
        $router->patch('/api/reservas/{id}/estado', $this->csrfProtected(
            fn (Request $r, array $p) => $api()->updateEstado($r, $p),
        ));

        $web = fn (): WebReservaController => new WebReservaController($this->reservaService(), $this->view());

        $router->get('/', fn (Request $r) => $web()->index($r));
        // Alta por formulario clásico (sin JS). Verifica el token CSRF del campo oculto.
        $router->post('/reservas', fn (Request $r) => $web()->store($r));
        $router->get('/reservas/{id}', fn (Request $r, array $p) => $web()->show($r, $p));

        return $router;
    }

    private function reservaService(): ReservaService
    {
        $pdo = $this->pdo();

        return new ReservaService(
            new ReservaRepository($pdo),
            new ReservaEventoRepository($pdo),
            new PdoTransactionManager($pdo),
            new ReservaValidator(),
        );
    }

    /**
     * Envuelve un manejador de escritura de la API con la comprobación CSRF.
     *
     * @param callable(Request, array<string, string>): Response $handler
     * @return callable(Request, array<string, string>): Response
     */
    private function csrfProtected(callable $handler): callable
    {
        return static function (Request $request, array $params) use ($handler): Response {
            Csrf::verify($request->header('x-csrf-token'));

            return $handler($request, $params);
        };
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= Database::connect($this->config);
    }

    private function view(): View
    {
        return $this->view ??= new View(
            $this->rootDir . '/templates',
            $this->rootDir . '/var/cache/twig',
            $this->config->bool('APP_DEBUG'),
            $this->basePath,
        );
    }
}
