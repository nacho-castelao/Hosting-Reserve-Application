<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\EstadoReserva;
use App\Domain\FiltroReservas;
use App\Domain\NuevaReserva;
use PDO;

/**
 * Único punto con SQL de reservas. Todas las consultas son sentencias
 * preparadas: los valores del usuario nunca se concatenan en el SQL.
 */
final class ReservaRepository implements ReservaRepositoryInterface
{
    private const COLUMNS = 'id, nombre_huesped, email_huesped, nombre_alojamiento, fecha_entrada, '
        . 'fecha_salida, estado, importe, notas, fecha_creacion';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function search(FiltroReservas $filtro): array
    {
        [$where, $params] = $this->buildWhere($filtro);

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM reserva' . $where
            . ' ORDER BY fecha_entrada DESC, id DESC LIMIT :limit'
        );
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':limit', $filtro->limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    public function count(FiltroReservas $filtro): int
    {
        [$where, $params] = $this->buildWhere($filtro);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM reserva' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM reserva WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM reserva WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function insert(NuevaReserva $reserva): int
    {
        // estado y fecha_creacion toman su DEFAULT de la tabla: el cliente no los controla.
        $stmt = $this->pdo->prepare(
            'INSERT INTO reserva (nombre_huesped, email_huesped, nombre_alojamiento, fecha_entrada, fecha_salida, importe, notas)
             VALUES (:nombre_huesped, :email_huesped, :nombre_alojamiento, :fecha_entrada, :fecha_salida, :importe, :notas)'
        );
        $stmt->execute([
            ':nombre_huesped' => $reserva->nombreHuesped,
            ':email_huesped' => $reserva->emailHuesped,
            ':nombre_alojamiento' => $reserva->nombreAlojamiento,
            ':fecha_entrada' => $reserva->fechaEntrada->format('Y-m-d'),
            ':fecha_salida' => $reserva->fechaSalida->format('Y-m-d'),
            ':importe' => $reserva->importe,
            ':notas' => $reserva->notas,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateEstado(int $id, EstadoReserva $estado): void
    {
        $stmt = $this->pdo->prepare('UPDATE reserva SET estado = :estado WHERE id = :id');
        $stmt->execute([':estado' => $estado->value, ':id' => $id]);
    }

    /**
     * Construye el WHERE a partir de los filtros. Solo se concatenan fragmentos
     * de SQL fijos; los valores van siempre como parámetros.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function buildWhere(FiltroReservas $filtro): array
    {
        $conditions = [];
        $params = [];

        if ($filtro->estado !== null) {
            $conditions[] = 'estado = :estado';
            $params[':estado'] = $filtro->estado->value;
        }

        // Solapamiento: la estancia ocupa las noches [fecha_entrada, fecha_salida).
        // Coincide con el rango [desde, hasta] si entra como tarde "hasta" y sale después de "desde".
        if ($filtro->hasta !== null) {
            $conditions[] = 'fecha_entrada <= :hasta';
            $params[':hasta'] = $filtro->hasta->format('Y-m-d');
        }
        if ($filtro->desde !== null) {
            $conditions[] = 'fecha_salida > :desde';
            $params[':desde'] = $filtro->desde->format('Y-m-d');
        }

        if ($filtro->q !== null) {
            // '!' como carácter de escape: no depende del modo NO_BACKSLASH_ESCAPES de MySQL.
            $conditions[] = "nombre_huesped LIKE :q ESCAPE '!'";
            $params[':q'] = '%' . self::escapeLike($filtro->q) . '%';
        }

        $where = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

        return [$where, $params];
    }

    /** Neutraliza los comodines de LIKE para que % y _ se busquen literalmente. */
    public static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'nombre_huesped' => (string) $row['nombre_huesped'],
            'email_huesped' => (string) $row['email_huesped'],
            'nombre_alojamiento' => (string) $row['nombre_alojamiento'],
            'fecha_entrada' => (string) $row['fecha_entrada'],
            'fecha_salida' => (string) $row['fecha_salida'],
            'estado' => (string) $row['estado'],
            'importe' => (string) $row['importe'],
            'notas' => $row['notas'] === null ? null : (string) $row['notas'],
            'fecha_creacion' => (string) $row['fecha_creacion'],
        ];
    }
}
