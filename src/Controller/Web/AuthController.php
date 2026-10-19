<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Exception\HttpException;
use App\Service\AuthService;
use App\Validation\AuthValidator;

/**
 * Inicio de sesión, registro de anfitriones y cierre de sesión. Formularios
 * clásicos con PRG: funcionan igual con y sin JavaScript.
 */
final class AuthController
{
    private const HOME = '/panel';

    public function __construct(private readonly AuthService $service, private readonly View $view)
    {
    }

    /** GET /login */
    public function loginForm(Request $request): Response
    {
        if (Auth::userId() !== null) {
            return self::redirect($request, self::HOME);
        }

        return $this->renderLogin($request);
    }

    /** POST /login */
    public function login(Request $request): Response
    {
        $form = $this->verifiedForm($request);

        try {
            $usuario = $this->service->autenticar(
                array_intersect_key($form, array_flip(['email', 'password'])),
                $request->clientIp,
            );
        } catch (HttpException $e) {
            // 401 credenciales, 422 campos vacíos o 429 demasiados intentos: se vuelve a pintar el formulario.
            $email = $form['email'] ?? '';

            return $this->renderLogin($request, $e, is_string($email) ? $email : '');
        }

        Auth::login($usuario->id);

        return self::redirect($request, self::safeNext($form['next'] ?? null));
    }

    /** GET /registro */
    public function registroForm(Request $request): Response
    {
        if (Auth::userId() !== null) {
            return self::redirect($request, self::HOME);
        }

        return $this->renderRegistro();
    }

    /** POST /registro — siempre crea un anfitrión. */
    public function registro(Request $request): Response
    {
        $form = $this->verifiedForm($request);
        $input = array_intersect_key($form, array_flip(['nombre', 'email', 'password', 'password_confirmacion']));

        try {
            $usuario = $this->service->registrar($input, $request->clientIp);
        } catch (HttpException $e) {
            // Las contraseñas nunca se devuelven al formulario.
            unset($input['password'], $input['password_confirmacion']);

            return $this->renderRegistro($e, $input);
        }

        Auth::login($usuario->id);

        return self::redirect($request, '/panel/alojamientos?bienvenida=1');
    }

    /** POST /logout */
    public function logout(Request $request): Response
    {
        $this->verifiedForm($request);
        Auth::logout();

        return self::redirect($request, '/');
    }

    /** @return array<string, mixed> */
    private function verifiedForm(Request $request): array
    {
        $form = $request->formParams();
        $token = $form['_csrf'] ?? null;
        Csrf::verify(is_string($token) ? $token : null);

        return $form;
    }

    private function renderLogin(Request $request, ?HttpException $error = null, string $email = ''): Response
    {
        $next = self::safeNext($request->formParams()['next'] ?? $request->queryParams()['next'] ?? null);

        $response = $this->view->render('auth/login.html.twig', [
            'error' => $error?->getMessage(),
            'email' => $email,
            'next' => $next === self::HOME ? '' : $next,
        ], $error?->getStatusCode() ?? 200);

        return self::withHeaders($response, $error);
    }

    /** @param array<string, mixed> $old */
    private function renderRegistro(?HttpException $error = null, array $old = []): Response
    {
        $response = $this->view->render('auth/registro.html.twig', [
            'error' => $error !== null && $error->getFields() === [] ? $error->getMessage() : null,
            'form_errors' => $error?->getFields() ?? [],
            'old' => array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $old),
            'max_lengths' => AuthValidator::MAX_LENGTHS,
            'password_min' => AuthValidator::PASSWORD_MIN,
        ], $error?->getStatusCode() ?? 200);

        return self::withHeaders($response, $error);
    }

    /** Conserva cabeceras como Retry-After (429) al re-pintar el formulario. */
    private static function withHeaders(Response $response, ?HttpException $error): Response
    {
        foreach ($error?->getHeaders() ?? [] as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    /**
     * Destino tras iniciar sesión. Solo rutas internas del panel: evita usar el
     * login como redirección abierta (?next=//evil.example).
     */
    private static function safeNext(mixed $next): string
    {
        if (is_string($next) && preg_match('#^/panel(/[A-Za-z0-9/_-]*)?$#', $next) === 1) {
            return $next;
        }

        return self::HOME;
    }

    private static function redirect(Request $request, string $path): Response
    {
        return new Response('', 303, ['Location' => $request->basePath . $path]);
    }
}
