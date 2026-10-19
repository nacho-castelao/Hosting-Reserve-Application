<?php

declare(strict_types=1);

namespace App\Controller\Web;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Domain\EstadoReserva;
use App\Domain\FiltroReservas;
use App\Domain\UsuarioActual;
use App\Exception\ValidationException;
use App\Service\ReservaService;

/**
 * Panel de reservas (requiere sesión). Funciona sin JavaScript; el JS lo
 * mejora después usando la API.
 */
final class ReservaController
{
    public function __construct(private readonly ReservaService $service, private readonly View $view)
    {
    }

    /** GET /panel */
    public function index(Request $request, array $params, UsuarioActual $usuario): Response
    {
        $status = 200;
        $filterErrors = [];
        try {
            $filtro = $this->service->parseFiltros($request->queryParams());
        } catch (ValidationException $e) {
            // Filtros inválidos: se avisa y se muestra el listado sin filtrar.
            $filterErrors = $e->getFields();
            $filtro = new FiltroReservas();
            $status = 400;
        }

        $result = $this->service->listar($filtro, $usuario);

        return $this->view->render('reservas/index.html.twig', [
            'reservas' => $result['items'],
            'total' => $result['total'],
            'resumen' => $this->service->resumen($usuario),
            'filtro' => $filtro->toArray(),
            'filter_errors' => $filterErrors,
            'estados' => EstadoReserva::cases(),
        ], $status);
    }

    /** GET /panel/reservas/{id} */
    public function show(Request $request, array $params, UsuarioActual $usuario): Response
    {
        return $this->view->render('reservas/show.html.twig', [
            'reserva' => $this->service->obtener((int) $params['id'], $usuario),
        ]);
    }
}
