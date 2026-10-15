<?php

declare(strict_types=1);

namespace App\Core;

use App\Exception\HttpException;
use Closure;
use ErrorException;
use Throwable;

/**
 * Punto único donde las excepciones se convierten en respuestas HTTP.
 * Registra el detalle en el log del servidor y al cliente solo le llega un
 * mensaje genérico (salvo HttpException, cuyo mensaje es seguro por diseño).
 */
final class ErrorHandler
{
    private const GENERIC_MESSAGE = 'Se ha producido un error interno. Inténtalo de nuevo más tarde.';

    private ?Request $request = null;

    /** @var (Closure(int, string): Response)|null */
    private ?Closure $htmlRenderer = null;

    public function __construct(private readonly bool $debug, private readonly string $logFile)
    {
    }

    public function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', $this->debug ? '1' : '0');
        ini_set('log_errors', '1');
        if (is_dir(dirname($this->logFile)) || @mkdir(dirname($this->logFile), 0775, true)) {
            ini_set('error_log', $this->logFile);
        }

        // Warnings y notices se tratan como excepciones: nada falla en silencio.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler(function (Throwable $e): void {
            $this->handle($e)->send();
        });

        // Errores fatales (memoria, tiempo…) que no pasan por el manejador de excepciones.
        register_shutdown_function(function (): void {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->handle(new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']))->send();
            }
        });
    }

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    /** @param Closure(int, string): Response $renderer */
    public function setHtmlRenderer(Closure $renderer): void
    {
        $this->htmlRenderer = $renderer;
    }

    public function handle(Throwable $e): Response
    {
        if ($e instanceof HttpException) {
            $status = $e->getStatusCode();
            $message = $e->getMessage();
            $fields = $e->getFields();
            $headers = $e->getHeaders();
        } else {
            $status = 500;
            $message = self::GENERIC_MESSAGE;
            $fields = [];
            $headers = [];
        }

        if ($status >= 500) {
            $this->log($e);
        }

        if ($this->wantsJson()) {
            $debug = ($this->debug && $status >= 500) ? [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ] : null;

            return JsonResponse::error($message, $status, $fields, $headers, $debug);
        }

        return $this->renderHtml($status, $message, $headers);
    }

    /** @param array<string, string> $headers */
    private function renderHtml(int $status, string $message, array $headers): Response
    {
        $response = null;
        if ($this->htmlRenderer !== null) {
            try {
                $response = ($this->htmlRenderer)($status, $message);
            } catch (Throwable $renderError) {
                // Si falla la propia plantilla de error, se cae a texto plano.
                $this->log($renderError);
            }
        }
        $response ??= new Response(
            htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $status,
            ['Content-Type' => 'text/plain; charset=utf-8'],
        );

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private function wantsJson(): bool
    {
        if ($this->request !== null) {
            return $this->request->isApi();
        }

        // Error antes de construir la Request: se decide por la URL en bruto.
        return str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/');
    }

    private function log(Throwable $e): void
    {
        $context = $this->request !== null ? $this->request->method . ' ' . $this->request->path : 'CLI/arranque';
        error_log(sprintf(
            "[%s] %s: %s en %s:%d\n%s",
            $context,
            $e::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString(),
        ));
    }
}
