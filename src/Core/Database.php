<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Factoría de la conexión PDO con opciones seguras.
 */
final class Database
{
    public static function connect(Config $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->get('DB_HOST', '127.0.0.1'),
            (int) $config->get('DB_PORT', '3306'),
            $config->get('DB_NAME'),
        );

        $pdo = new PDO($dsn, $config->get('DB_USER'), $config->get('DB_PASS'), [
            // Los errores SQL lanzan PDOException (el ErrorHandler las convierte en un 500 genérico).
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Sentencias preparadas reales en el servidor, no emuladas por el driver.
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // Alinea la zona horaria de la sesión SQL con la de PHP para que
        // CURRENT_TIMESTAMP y las fechas mostradas coincidan.
        $pdo->exec("SET time_zone = '" . date('P') . "'");

        return $pdo;
    }
}
