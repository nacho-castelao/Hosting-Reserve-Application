<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Exception\ConflictException;
use App\Exception\ValidationException;
use App\Service\AlojamientoService;
use App\Service\ReservaService;
use App\Validation\ReservaValidator;

/**
 * Parte pública: catálogo de alojamientos y reserva del huésped (sin cuenta).
 */
final class CatalogoController
{
    /** Campos que se aceptan del formulario HTML (el alojamiento sale de la URL). */
    private const FORM_FIELDS = ['nombre_huesped', 'email_huesped', 'fecha_entrada', 'fecha_salida', 'notas'];

    private const FLASH_RESERVA = 'reserva_recibida';

    public function __construct(
        private readonly AlojamientoService $alojamientos,
        private readonly ReservaService $reservas,
        private readonly View $view,
    ) {
    }

    /** GET / */
    public function index(): Response
    {
        return $this->view->render('catalogo/index.html.twig', [
            'alojamientos' => $this->alojamientos->catalogo(),
        ]);
    }

    /** GET /alojamientos/{id} */
    public function show(Request $request, array $params): Response
    {
        return $this->renderShow((int) $params['id']);
    }

    /**
     * POST /alojamientos/{id}/reservas — reserva desde el formulario clásico cuando no hay JS.
     * Patrón PRG: si va bien, 303 a /reserva-recibida con el resumen en un flash de sesión,
     * de modo que la URL final no expone ningún id ni dato del huésped.
     */
    public function reservar(Request $request, array $params): Response
    {
        $form = $request->formParams();
        $token = $form['_csrf'] ?? null;
        Csrf::verify(is_string($token) ? $token : null);

        $id = (int) $params['id'];
        $input = array_intersect_key($form, array_flip(self::FORM_FIELDS)) + ['alojamiento_id' => $id];

        try {
            $reserva = $this->reservas->crear($input);
        } catch (ValidationException $e) {
            return $this->renderShow($id, $e->getFields(), null, $input, 422);
        } catch (ConflictException $e) {
            return $this->renderShow($id, [], $e->getMessage(), $input, 409);
        }

        Session::flash(self::FLASH_RESERVA, $reserva);

        return new Response('', 303, ['Location' => $request->basePath . '/reserva-recibida']);
    }

    /** GET /reserva-recibida */
    public function recibida(Request $request): Response
    {
        $reserva = Session::pullFlash(self::FLASH_RESERVA);
        if (!is_array($reserva)) {
            // Recarga o acceso directo: no hay nada que mostrar.
            return new Response('', 303, ['Location' => $request->basePath . '/']);
        }

        return $this->view->render('catalogo/recibida.html.twig', ['reserva' => $reserva]);
    }

    /**
     * @param array<string, string> $formErrors
     * @param array<string, mixed> $old Valores enviados, para no perderlos al mostrar errores.
     */
    private function renderShow(int $id, array $formErrors = [], ?string $formError = null, array $old = [], int $status = 200): Response
    {
        return $this->view->render('catalogo/show.html.twig', [
            'alojamiento' => $this->alojamientos->publico($id),
            'form_errors' => $formErrors,
            'form_error' => $formError,
            'old' => array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $old),
            'max_lengths' => ReservaValidator::MAX_LENGTHS,
            'max_noches' => ReservaValidator::MAX_NOCHES,
            'today' => date('Y-m-d'),
        ], $status);
    }
}
