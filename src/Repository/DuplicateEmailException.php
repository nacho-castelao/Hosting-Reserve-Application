<?php

declare(strict_types=1);

namespace App\Repository;

use RuntimeException;

/**
 * El email ya existe (restricción UNIQUE). Cubre la carrera entre la
 * comprobación previa del servicio y el INSERT.
 */
final class DuplicateEmailException extends RuntimeException
{
}
