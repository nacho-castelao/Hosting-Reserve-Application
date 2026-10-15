<?php

declare(strict_types=1);

namespace App\Core;

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

        return $router;
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
