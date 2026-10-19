<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\UsuarioActual;
use App\Exception\ValidationException;
use App\Service\AlojamientoService;
use App\Service\AuthService;
use App\Validation\AlojamientoValidator;

/**
 * Gestión de alojamientos (anfitriones y administrador) y listado de
 * anfitriones (solo administrador). Formularios clásicos con PRG.
 */
final class PanelController
{
    private const FORM_FIELDS = ['nombre', 'ciudad', 'precio_noche', 'activo'];

    public function __construct(
        private readonly AlojamientoService $alojamientos,
        private readonly AuthService $auth,
        private readonly View $view,
    ) {
    }

    /** GET /panel/alojamientos */
    public function alojamientos(Request $request, array $params, UsuarioActual $usuario): Response
    {
        $query = $request->queryParams();

        return $this->renderAlojamientos($usuario, [
            'bienvenida' => ($query['bienvenida'] ?? null) === '1',
            'guardado' => ($query['guardado'] ?? null) === '1',
        ]);
    }

    /** POST /panel/alojamientos */
    public function crearAlojamiento(Request $request, array $params, UsuarioActual $usuario): Response
    {
        $input = $this->verifiedForm($request);

        try {
            $this->alojamientos->crear($input, $usuario);
        } catch (ValidationException $e) {
            return $this->renderAlojamientos($usuario, [
                'form_errors' => $e->getFields(),
                'old' => self::old($input),
            ], 422);
        }

        return new Response('', 303, ['Location' => $request->basePath . '/panel/alojamientos?guardado=1']);
    }

    /** GET /panel/alojamientos/{id} */
    public function editarAlojamiento(Request $request, array $params, UsuarioActual $usuario): Response
    {
        $alojamiento = $this->alojamientos->obtener((int) $params['id'], $usuario);

        return $this->renderEditar($alojamiento, [
            'nombre' => $alojamiento['nombre'],
            'ciudad' => $alojamiento['ciudad'],
            'precio_noche' => $alojamiento['precio_noche'],
            'activo' => $alojamiento['activo'] ? '1' : '',
        ]);
    }

    /** POST /panel/alojamientos/{id} */
    public function actualizarAlojamiento(Request $request, array $params, UsuarioActual $usuario): Response
    {
        $id = (int) $params['id'];
        $input = $this->verifiedForm($request);

        try {
            $this->alojamientos->actualizar($id, $input, $usuario);
        } catch (ValidationException $e) {
            return $this->renderEditar($this->alojamientos->obtener($id, $usuario), self::old($input), $e->getFields(), 422);
        }

        return new Response('', 303, ['Location' => $request->basePath . '/panel/alojamientos?guardado=1']);
    }

    /** GET /panel/usuarios — solo administrador (lo comprueba App). */
    public function usuarios(): Response
    {
        return $this->view->render('panel/usuarios.html.twig', [
            'anfitriones' => $this->auth->anfitriones(),
        ]);
    }

    /** @return array<string, mixed> Solo los campos del alojamiento, ya con el CSRF comprobado. */
    private function verifiedForm(Request $request): array
    {
        $form = $request->formParams();
        $token = $form['_csrf'] ?? null;
        Csrf::verify(is_string($token) ? $token : null);

        return array_intersect_key($form, array_flip(self::FORM_FIELDS));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    private static function old(array $input): array
    {
        return array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $input);
    }

    /** @param array<string, mixed> $extra */
    private function renderAlojamientos(UsuarioActual $usuario, array $extra = [], int $status = 200): Response
    {
        return $this->view->render('panel/alojamientos.html.twig', $extra + [
            'alojamientos' => $this->alojamientos->listar($usuario),
            'form_errors' => [],
            // Un alojamiento nuevo empieza activo.
            'old' => ['activo' => '1'],
            'bienvenida' => false,
            'guardado' => false,
            'max_lengths' => AlojamientoValidator::MAX_LENGTHS,
        ], $status);
    }

    /**
     * @param array<string, mixed> $alojamiento
     * @param array<string, string> $old
     * @param array<string, string> $errors
     */
    private function renderEditar(array $alojamiento, array $old, array $errors = [], int $status = 200): Response
    {
        return $this->view->render('panel/alojamiento-editar.html.twig', [
            'alojamiento' => $alojamiento,
            'old' => $old,
            'form_errors' => $errors,
            'max_lengths' => AlojamientoValidator::MAX_LENGTHS,
        ], $status);
    }
}
