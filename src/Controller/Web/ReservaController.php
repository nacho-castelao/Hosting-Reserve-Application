<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\EstadoReserva;
use App\Domain\FiltroReservas;
use App\Exception\ValidationException;
use App\Service\ReservaService;
use App\Validation\ReservaValidator;

/**
 * Páginas HTML renderizadas en servidor. Funcionan sin JavaScript; el JS
 * las mejora después usando la API.
 */
final class ReservaController
{
    /** Campos que se aceptan del formulario HTML (el token CSRF se retira aparte). */
    private const FORM_FIELDS = [
        'nombre_huesped', 'email_huesped', 'nombre_alojamiento',
        'fecha_entrada', 'fecha_salida', 'importe', 'notas',
    ];

    public function __construct(private readonly ReservaService $service, private readonly View $view)
    {
    }

    /** GET / */
    public function index(Request $request): Response
    {
        return $this->renderIndex($request);
    }

    /** GET /reservas/{id} */
    public function show(Request $request, array $params): Response
    {
        return $this->view->render('reservas/show.html.twig', [
            'reserva' => $this->service->obtener((int) $params['id']),
            'creada' => ($request->queryParams()['creada'] ?? null) === '1',
        ]);
    }

    /**
     * POST /reservas — alta desde el formulario clásico cuando no hay JS.
     * Patrón PRG: si va bien, redirección 303 al detalle.
     */
    public function store(Request $request): Response
    {
        $form = $request->formParams();
        $token = $form['_csrf'] ?? null;
        Csrf::verify(is_string($token) ? $token : null);

        // Solo los campos conocidos; los vacíos opcionales se descartan.
        $input = array_intersect_key($form, array_flip(self::FORM_FIELDS));

        try {
            $reserva = $this->service->crear($input);
        } catch (ValidationException $e) {
            return $this->renderIndex($request, $e->getFields(), $input, 422);
        }

        return new Response('', 303, ['Location' => $request->basePath . '/reservas/' . $reserva['id'] . '?creada=1']);
    }

    /**
     * @param array<string, string> $formErrors
     * @param array<string, mixed> $old Valores enviados, para no perderlos al mostrar errores.
     */
    private function renderIndex(Request $request, array $formErrors = [], array $old = [], int $status = 200): Response
    {
        $filterErrors = [];
        try {
            $filtro = $this->service->parseFiltros($request->queryParams());
        } catch (ValidationException $e) {
            // Filtros inválidos: se avisa y se muestra el listado sin filtrar.
            $filterErrors = $e->getFields();
            $filtro = new FiltroReservas();
            $status = $status === 200 ? 400 : $status;
        }

        $result = $this->service->listar($filtro);

        return $this->view->render('reservas/index.html.twig', [
            'reservas' => $result['items'],
            'total' => $result['total'],
            'filtro' => $filtro->toArray(),
            'filter_errors' => $filterErrors,
            'estados' => EstadoReserva::cases(),
            'form_errors' => $formErrors,
            'old' => array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $old),
            'max_lengths' => ReservaValidator::MAX_LENGTHS,
            'today' => date('Y-m-d'),
        ], $status);
    }
}
