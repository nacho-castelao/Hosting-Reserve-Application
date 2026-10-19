<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Core\JsonResponse;
use App\Core\Request;
use App\Domain\UsuarioActual;
use App\Service\ReservaService;

/**
 * Controlador fino de la API: traduce HTTP <-> servicio. Las excepciones
 * (validación, 404, 409…) las convierte en respuesta el ErrorHandler.
 * Todo salvo store() exige sesión (lo comprueba App antes de llegar aquí).
 */
final class ReservaController
{
    public function __construct(private readonly ReservaService $service)
    {
    }

    /** GET /api/reservas?estado=&desde=&hasta=&q=&limit= */
    public function index(Request $request, array $params, UsuarioActual $usuario): JsonResponse
    {
        $filtro = $this->service->parseFiltros($request->queryParams());
        $result = $this->service->listar($filtro, $usuario);

        return JsonResponse::data($result['items'], 200, [
            'total' => $result['total'],
            'count' => count($result['items']),
            'limit' => $filtro->limit,
        ]);
    }

    /** GET /api/reservas/resumen */
    public function resumen(Request $request, array $params, UsuarioActual $usuario): JsonResponse
    {
        return JsonResponse::data($this->service->resumen($usuario));
    }

    /** GET /api/reservas/{id} */
    public function show(Request $request, array $params, UsuarioActual $usuario): JsonResponse
    {
        return JsonResponse::data($this->service->obtener((int) $params['id'], $usuario));
    }

    /**
     * POST /api/reservas — alta pública del huésped. Sin cabecera Location: el
     * detalle de una reserva solo lo pueden consultar su anfitrión y el administrador.
     */
    public function store(Request $request): JsonResponse
    {
        return JsonResponse::data($this->service->crear($request->json()), 201);
    }

    /** PATCH /api/reservas/{id}/estado */
    public function updateEstado(Request $request, array $params, UsuarioActual $usuario): JsonResponse
    {
        return JsonResponse::data($this->service->cambiarEstado((int) $params['id'], $request->json(), $usuario));
    }
}
