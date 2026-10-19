<?php

declare(strict_types=1);

namespace App\Core;

use App\Controller\Api\AlojamientoController as ApiAlojamientoController;
use App\Controller\Api\ReservaController as ApiReservaController;
use App\Controller\Web\AuthController;
use App\Controller\Web\CatalogoController;
use App\Controller\Web\PanelController;
use App\Controller\Web\ReservaController as WebReservaController;
use App\Domain\Rol;
use App\Domain\UsuarioActual;
use App\Exception\ForbiddenException;
use App\Exception\UnauthorizedException;
use App\Repository\AlojamientoRepository;
use App\Repository\LimitePeticionRepository;
use App\Repository\PdoTransactionManager;
use App\Repository\ReservaEventoRepository;
use App\Repository\ReservaRepository;
use App\Repository\UsuarioRepository;
use App\Service\AlojamientoService;
use App\Service\AuthService;
use App\Service\RateLimiter;
use App\Service\ReservaService;
use App\Validation\AlojamientoValidator;
use App\Validation\AuthValidator;
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

    /** false = aún no se ha consultado; null = petición anónima. */
    private UsuarioActual|null|false $usuario = false;

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

        // --- API pública ---
        $router->get('/api/alojamientos', fn () => (new ApiAlojamientoController($this->alojamientoService()))->index());
        $router->post('/api/reservas', $this->csrfProtected($this->limitedByIp(
            RateLimiter::RESERVA_IP,
            fn (Request $r) => $api()->store($r),
        )));

        // --- API del panel (sesión obligatoria; los anfitriones solo ven lo suyo) ---
        $router->get('/api/reservas', $this->authenticated(fn (Request $r, array $p, UsuarioActual $u) => $api()->index($r, $p, $u)));
        // {id} solo acepta dígitos, así que esta ruta no choca con la del detalle.
        $router->get('/api/reservas/resumen', $this->authenticated(fn (Request $r, array $p, UsuarioActual $u) => $api()->resumen($r, $p, $u)));
        $router->get('/api/reservas/{id}', $this->authenticated(fn (Request $r, array $p, UsuarioActual $u) => $api()->show($r, $p, $u)));
        $router->patch('/api/reservas/{id}/estado', $this->csrfProtected($this->authenticated(
            fn (Request $r, array $p, UsuarioActual $u) => $api()->updateEstado($r, $p, $u),
        )));

        // --- Web pública: catálogo y reserva del huésped ---
        $catalogo = fn (): CatalogoController => new CatalogoController($this->alojamientoService(), $this->reservaService(), $this->view());

        $router->get('/', fn () => $catalogo()->index());
        $router->get('/alojamientos/{id}', fn (Request $r, array $p) => $catalogo()->show($r, $p));
        // Reserva por formulario clásico (sin JS). Verifica el token CSRF del campo oculto.
        $router->post('/alojamientos/{id}/reservas', $this->limitedByIp(
            RateLimiter::RESERVA_IP,
            fn (Request $r, array $p) => $catalogo()->reservar($r, $p),
        ));
        $router->get('/reserva-recibida', fn (Request $r) => $catalogo()->recibida($r));

        // --- Acceso ---
        $auth = fn (): AuthController => new AuthController($this->authService(), $this->view());

        $router->get('/login', fn (Request $r) => $auth()->loginForm($r));
        $router->post('/login', fn (Request $r) => $auth()->login($r));
        $router->get('/registro', fn (Request $r) => $auth()->registroForm($r));
        $router->post('/registro', fn (Request $r) => $auth()->registro($r));
        $router->post('/logout', fn (Request $r) => $auth()->logout($r));

        // --- Panel (sesión obligatoria) ---
        $web = fn (): WebReservaController => new WebReservaController($this->reservaService(), $this->view());
        $panel = fn (): PanelController => new PanelController($this->alojamientoService(), $this->authService(), $this->view());

        $router->get('/panel', $this->authenticated(fn (Request $r, array $p, UsuarioActual $u) => $web()->index($r, $p, $u)));
        $router->get('/panel/reservas/{id}', $this->authenticated(fn (Request $r, array $p, UsuarioActual $u) => $web()->show($r, $p, $u)));
        $router->get('/panel/alojamientos', $this->authenticated(fn (Request $r, array $p, UsuarioActual $u) => $panel()->alojamientos($r, $p, $u)));
        $router->post('/panel/alojamientos', $this->authenticated(fn (Request $r, array $p, UsuarioActual $u) => $panel()->crearAlojamiento($r, $p, $u)));
        $router->get('/panel/alojamientos/{id}', $this->authenticated(fn (Request $r, array $p, UsuarioActual $u) => $panel()->editarAlojamiento($r, $p, $u)));
        $router->post('/panel/alojamientos/{id}', $this->authenticated(fn (Request $r, array $p, UsuarioActual $u) => $panel()->actualizarAlojamiento($r, $p, $u)));
        $router->get('/panel/usuarios', $this->authenticated(fn () => $panel()->usuarios(), Rol::Admin));

        return $router;
    }

    private function reservaService(): ReservaService
    {
        $pdo = $this->pdo();

        return new ReservaService(
            new ReservaRepository($pdo),
            new ReservaEventoRepository($pdo),
            new AlojamientoRepository($pdo),
            new PdoTransactionManager($pdo),
            new ReservaValidator(),
        );
    }

    private function alojamientoService(): AlojamientoService
    {
        return new AlojamientoService(new AlojamientoRepository($this->pdo()), new AlojamientoValidator());
    }

    private function authService(): AuthService
    {
        return new AuthService(new UsuarioRepository($this->pdo()), new AuthValidator(), $this->rateLimiter());
    }

    private function rateLimiter(): RateLimiter
    {
        return new RateLimiter(new LimitePeticionRepository($this->pdo()));
    }

    /**
     * Usuario de la sesión, cargado de la base de datos una vez por petición.
     * Si la cuenta ya no existe, la sesión se cierra.
     */
    private function usuario(): ?UsuarioActual
    {
        if ($this->usuario !== false) {
            return $this->usuario;
        }

        $id = Auth::userId();
        $usuario = $id === null ? null : $this->authService()->usuario($id);
        if ($id !== null && $usuario === null) {
            Auth::logout();
        }

        return $this->usuario = $usuario;
    }

    /**
     * Exige sesión (y, opcionalmente, un rol) antes de llamar al manejador, que
     * recibe el usuario como tercer argumento. Sin sesión: 401 en la API y
     * redirección al login en la web. Las escrituras cuentan para el rate limiting.
     *
     * @param callable(Request, array<string, string>, UsuarioActual): Response $handler
     * @return callable(Request, array<string, string>): Response
     */
    private function authenticated(callable $handler, ?Rol $rol = null): callable
    {
        return function (Request $request, array $params) use ($handler, $rol): Response {
            $usuario = $this->usuario();
            if ($usuario === null) {
                if ($request->isApi()) {
                    throw new UnauthorizedException();
                }

                return new Response('', 303, [
                    'Location' => $request->basePath . '/login?next=' . rawurlencode($request->path),
                ]);
            }
            if ($rol !== null && $usuario->rol !== $rol) {
                throw new ForbiddenException();
            }
            if (!in_array($request->method, ['GET', 'HEAD'], true)) {
                $this->rateLimiter()->consumir(RateLimiter::ESCRITURA_USUARIO, (string) $usuario->id);
            }

            return $handler($request, $params, $usuario);
        };
    }

    /**
     * Rate limiting por IP para las escrituras públicas (reservas de huéspedes).
     *
     * @param callable(Request, array<string, string>): Response $handler
     * @return callable(Request, array<string, string>): Response
     */
    private function limitedByIp(string $cubo, callable $handler): callable
    {
        return function (Request $request, array $params) use ($cubo, $handler): Response {
            $this->rateLimiter()->consumir($cubo, $request->clientIp);

            return $handler($request, $params);
        };
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
            fn (): ?UsuarioActual => $this->usuario(),
        );
    }
}
