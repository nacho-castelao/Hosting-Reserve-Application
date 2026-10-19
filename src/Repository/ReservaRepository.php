<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\EstadoReserva;
use App\Domain\FiltroReservas;
use App\Domain\NuevaReserva;
use DateTimeImmutable;
use PDO;

/**
 * Único punto con SQL de reservas. Todas las consultas son sentencias
 * preparadas: los valores del usuario nunca se concatenan en el SQL.
 */
final class ReservaRepository implements ReservaRepositoryInterface
{
    private const COLUMNS = 'r.id, r.alojamiento_id, a.nombre AS nombre_alojamiento, a.anfitrion_id, r.nombre_huesped, '
        . 'r.email_huesped, r.fecha_entrada, r.fecha_salida, r.estado, r.importe, r.notas, r.fecha_creacion';

    private const FROM = ' FROM reserva r JOIN alojamiento a ON a.id = r.alojamiento_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function search(FiltroReservas $filtro): array
    {
        [$where, $params] = $this->buildWhere($filtro);

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . self::FROM . $where
            . ' ORDER BY r.fecha_entrada DESC, r.id DESC LIMIT :limit'
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

        $stmt = $this->pdo->prepare('SELECT COUNT(*)' . self::FROM . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function totalesPorEstado(?int $anfitrionId): array
    {
        $sql = 'SELECT r.estado, COUNT(*) AS total, SUM(r.importe) AS importe' . self::FROM;
        $params = [];
        if ($anfitrionId !== null) {
            $sql .= ' WHERE a.anfitrion_id = :anfitrion';
            $params[':anfitrion'] = $anfitrionId;
        }
        $stmt = $this->pdo->prepare($sql . ' GROUP BY r.estado');
        $stmt->execute($params);

        $totales = [];
        foreach ($stmt->fetchAll() as $row) {
            $totales[(string) $row['estado']] = ['total' => (int) $row['total'], 'importe' => (string) $row['importe']];
        }

        return $totales;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . self::FROM . ' WHERE r.id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByIdForUpdate(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . self::FROM . ' WHERE r.id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function haySolapamiento(int $alojamientoId, DateTimeImmutable $entrada, DateTimeImmutable $salida): bool
    {
        // Dos estancias [e1, s1) y [e2, s2) comparten alguna noche si e1 < s2 y s1 > e2.
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM reserva
             WHERE alojamiento_id = :alojamiento AND estado IN ('pendiente', 'confirmada')
               AND fecha_entrada < :salida AND fecha_salida > :entrada
             LIMIT 1"
        );
        $stmt->execute([
            ':alojamiento' => $alojamientoId,
            ':entrada' => $entrada->format('Y-m-d'),
            ':salida' => $salida->format('Y-m-d'),
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public function insert(NuevaReserva $reserva, string $importe): int
    {
        // estado y fecha_creacion toman su DEFAULT de la tabla: el cliente no los controla.
        $stmt = $this->pdo->prepare(
            'INSERT INTO reserva (alojamiento_id, nombre_huesped, email_huesped, fecha_entrada, fecha_salida, importe, notas)
             VALUES (:alojamiento_id, :nombre_huesped, :email_huesped, :fecha_entrada, :fecha_salida, :importe, :notas)'
        );
        $stmt->execute([
            ':alojamiento_id' => $reserva->alojamientoId,
            ':nombre_huesped' => $reserva->nombreHuesped,
            ':email_huesped' => $reserva->emailHuesped,
            ':fecha_entrada' => $reserva->fechaEntrada->format('Y-m-d'),
            ':fecha_salida' => $reserva->fechaSalida->format('Y-m-d'),
            ':importe' => $importe,
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
     * @return array{0: string, 1: array<string, string|int>}
     */
    private function buildWhere(FiltroReservas $filtro): array
    {
        $conditions = [];
        $params = [];

        // Un anfitrión solo ve las reservas de sus alojamientos.
        if ($filtro->anfitrionId !== null) {
            $conditions[] = 'a.anfitrion_id = :anfitrion';
            $params[':anfitrion'] = $filtro->anfitrionId;
        }

        if ($filtro->estado !== null) {
            $conditions[] = 'r.estado = :estado';
            $params[':estado'] = $filtro->estado->value;
        }

        // Solapamiento: la estancia ocupa las noches [fecha_entrada, fecha_salida).
        // Coincide con el rango [desde, hasta] si entra como tarde "hasta" y sale después de "desde".
        if ($filtro->hasta !== null) {
            $conditions[] = 'r.fecha_entrada <= :hasta';
            $params[':hasta'] = $filtro->hasta->format('Y-m-d');
        }
        if ($filtro->desde !== null) {
            $conditions[] = 'r.fecha_salida > :desde';
            $params[':desde'] = $filtro->desde->format('Y-m-d');
        }

        if ($filtro->q !== null) {
            // '!' como carácter de escape: no depende del modo NO_BACKSLASH_ESCAPES de MySQL.
            $conditions[] = "r.nombre_huesped LIKE :q ESCAPE '!'";
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
            'alojamiento_id' => (int) $row['alojamiento_id'],
            'nombre_alojamiento' => (string) $row['nombre_alojamiento'],
            'anfitrion_id' => (int) $row['anfitrion_id'],
            'nombre_huesped' => (string) $row['nombre_huesped'],
            'email_huesped' => (string) $row['email_huesped'],
            'fecha_entrada' => (string) $row['fecha_entrada'],
            'fecha_salida' => (string) $row['fecha_salida'],
            'estado' => (string) $row['estado'],
            'importe' => (string) $row['importe'],
            'notas' => $row['notas'] === null ? null : (string) $row['notas'],
            'fecha_creacion' => (string) $row['fecha_creacion'],
        ];
    }
}
