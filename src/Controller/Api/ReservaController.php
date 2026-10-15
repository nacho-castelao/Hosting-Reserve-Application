<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Service\ReservaService;

/**
 * Controlador fino de la API: traduce HTTP <-> servicio. Las excepciones
 * (validación, 404, 409…) las convierte en respuesta el ErrorHandler.
 */
final class ReservaController
{
    public function __construct(private readonly ReservaService $service)
    {
    }

    /** GET /api/reservas?estado=&desde=&hasta=&q=&limit= */
    public function index(Request $request): JsonResponse
    {
        $filtro = $this->service->parseFiltros($request->queryParams());
        $result = $this->service->listar($filtro);

        return JsonResponse::data($result['items'], 200, [
            'total' => $result['total'],
            'count' => count($result['items']),
            'limit' => $filtro->limit,
        ]);
    }

    /** GET /api/reservas/{id} */
    public function show(Request $request, array $params): JsonResponse
    {
        return JsonResponse::data($this->service->obtener((int) $params['id']));
    }

    /** POST /api/reservas */
    public function store(Request $request): JsonResponse
    {
        $reserva = $this->service->crear($request->json());

        return JsonResponse::data($reserva, 201, null, [
            'Location' => $request->basePath . '/api/reservas/' . $reserva['id'],
        ]);
    }

    /** PATCH /api/reservas/{id}/estado */
    public function updateEstado(Request $request, array $params): JsonResponse
    {
        return JsonResponse::data($this->service->cambiarEstado((int) $params['id'], $request->json()));
    }
}
