<?php

declare(strict_types=1);

namespace App\Validation;

use App\Domain\DatosAlojamiento;
use App\Exception\ValidationException;

/**
 * Alta y edición de alojamientos desde el panel.
 */
final class AlojamientoValidator
{
    use FieldRules;

    /** Longitudes máximas: coinciden con las columnas VARCHAR de database/schema.sql. */
    public const MAX_LENGTHS = ['nombre' => 200, 'ciudad' => 100];

    private const FIELDS = ['nombre', 'ciudad', 'precio_noche', 'activo'];

    /** El anfitrión es el usuario autenticado, nunca un dato del formulario. */
    private const SERVER_FIELDS = ['id', 'anfitrion_id', 'fecha_creacion'];

    /**
     * @param array<string, mixed> $input
     * @throws ValidationException
     */
    public function validate(array $input): DatosAlojamiento
    {
        $errors = $this->rejectUnexpectedFields($input, self::FIELDS, self::SERVER_FIELDS);

        $nombre = $this->requiredText($input, 'nombre', self::MAX_LENGTHS['nombre'], $errors);
        $ciudad = $this->requiredText($input, 'ciudad', self::MAX_LENGTHS['ciudad'], $errors);
        $precio = $this->requiredMoney($input, 'precio_noche', $errors);

        // Casilla de un formulario: presente ("1"/true) = activo; ausente = inactivo.
        $activo = $input['activo'] ?? false;
        if (!in_array($activo, [true, false, '1', '0', 1, 0], true)) {
            $errors['activo'] = 'Valor no válido.';
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new DatosAlojamiento($nombre, $ciudad, $precio, in_array($activo, [true, '1', 1], true));
    }
}
