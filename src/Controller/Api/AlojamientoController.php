<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Core\JsonResponse;
use App\Service\AlojamientoService;

final class AlojamientoController
{
    public function __construct(private readonly AlojamientoService $service)
    {
    }

    /** GET /api/alojamientos — catálogo público: solo activos y sin datos del anfitrión. */
    public function index(): JsonResponse
    {
        $items = $this->service->catalogo();

        return JsonResponse::data($items, 200, ['total' => count($items)]);
    }
}
