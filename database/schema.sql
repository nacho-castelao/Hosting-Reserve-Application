-- =====================================================================
-- Gestor de reservas de alojamientos — esquema + datos de ejemplo
-- Importación única: phpMyAdmin (pestaña "Importar") o CLI:
--   mysql -u USUARIO -p NOMBRE_BD < database/schema.sql
-- Se importa sobre una base de datos YA creada (los hostings compartidos
-- no permiten CREATE DATABASE). Es idempotente: borra y recrea las tablas.
-- Compatible con MySQL >= 8.0.16 y MariaDB >= 10.2 (CHECK aplicado).
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Primero la tabla hija para no romper la clave foránea.
DROP TABLE IF EXISTS reserva_evento;
DROP TABLE IF EXISTS reserva;

CREATE TABLE reserva (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_huesped     VARCHAR(150)  NOT NULL,
    email_huesped      VARCHAR(254)  NOT NULL,
    nombre_alojamiento VARCHAR(200)  NOT NULL,
    fecha_entrada      DATE          NOT NULL,
    fecha_salida       DATE          NOT NULL,
    estado             ENUM('pendiente', 'confirmada', 'cancelada') NOT NULL DEFAULT 'pendiente',
    importe            DECIMAL(10, 2) NOT NULL,
    notas              VARCHAR(1000) NULL,
    fecha_creacion     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_reserva_estado (estado),
    INDEX idx_reserva_fechas (fecha_entrada, fecha_salida),
    INDEX idx_reserva_fecha_salida (fecha_salida),
    INDEX idx_reserva_nombre_huesped (nombre_huesped),
    CONSTRAINT chk_reserva_fechas  CHECK (fecha_salida > fecha_entrada),
    CONSTRAINT chk_reserva_importe CHECK (importe >= 0)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE reserva_evento (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reserva_id     INT UNSIGNED NOT NULL,
    tipo           VARCHAR(50)  NOT NULL,
    descripcion    VARCHAR(255) NOT NULL,
    fecha_creacion TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_evento_reserva_fecha (reserva_id, fecha_creacion),
    CONSTRAINT fk_evento_reserva
        FOREIGN KEY (reserva_id) REFERENCES reserva (id)
        ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Datos de ejemplo: pasado, en curso y futuro; los tres estados.
-- Los correos usan dominios reservados (example.com / example.es).
-- ---------------------------------------------------------------------
INSERT INTO reserva
    (id, nombre_huesped, email_huesped, nombre_alojamiento, fecha_entrada, fecha_salida, estado, importe, notas, fecha_creacion)
VALUES
    (1,  'María García López',      'maria.garcia@example.com',     'Hotel Puerta del Sol · Madrid',            '2026-07-02', '2026-07-05', 'confirmada', 486.00,  NULL,                                              '2026-05-14 09:12:00'),
    (2,  'Javier Martínez Ruiz',    'javier.martinez@example.es',   'Apartamento Barceloneta Mar · Barcelona',  '2026-08-10', '2026-08-17', 'confirmada', 1190.50, 'Llegada tarde, sobre las 23:00.',                 '2026-05-20 18:40:00'),
    (3,  'Lucía Fernández Sánchez', 'lucia.fernandez@example.com',  'Hotel Patio de Triana · Sevilla',          '2026-09-04', '2026-09-07', 'cancelada',  315.75,  'Cancelada por cambio de fechas del congreso.',    '2026-06-02 11:05:00'),
    (4,  'Carlos Rodríguez Pérez',  'carlos.rodriguez@example.com', 'Apartamento Ruzafa Centro · Valencia',     '2026-09-18', '2026-09-21', 'confirmada', 270.00,  NULL,                                              '2026-08-01 08:30:00'),
    (5,  'Ana Gómez Navarro',       'ana.gomez@example.es',         'Villa Cala d''Or · Mallorca',              '2026-10-10', '2026-10-17', 'confirmada', 2380.00, 'Cuna para bebé y piscina climatizada.',           '2026-03-11 21:15:00'),
    (6,  'David López Jiménez',     'david.lopez@example.com',      'Hotel Gran Vía Suites · Madrid',           '2026-10-13', '2026-10-16', 'confirmada', 405.90,  'Viaje de trabajo, necesita factura a empresa.',   '2026-09-25 13:47:00'),
    (7,  'Laura Martín Romero',     'laura.martin@example.es',      'Apartamento Malvarrosa Playa · Valencia',  '2026-10-14', '2026-10-19', 'pendiente',  640.00,  NULL,                                              '2026-10-08 16:20:00'),
    (8,  'Pablo Sánchez Moreno',    'pablo.sanchez@example.com',    'Hotel Albaicín Mirador · Granada',         '2026-10-24', '2026-10-27', 'pendiente',  329.40,  'Pide habitación con vistas a la Alhambra.',       '2026-10-09 10:02:00'),
    (9,  'Elena Díaz Álvarez',      'elena.diaz@example.com',       'Apartamento Gòtic · Barcelona',            '2026-11-05', '2026-11-08', 'confirmada', 378.00,  NULL,                                              '2026-09-30 19:55:00'),
    (10, 'Sergio Muñoz Castro',     'sergio.munoz@example.es',      'Hotel Santa Cruz · Sevilla',               '2026-11-12', '2026-11-15', 'cancelada',  299.00,  NULL,                                              '2026-09-12 12:00:00'),
    (11, 'Marta Romero Ortiz',      'marta.romero@example.com',     'Finca Es Trenc · Mallorca',                '2026-12-01', '2026-12-08', 'pendiente',  1750.00, 'Grupo de 6 personas. Consultar traslado.',        '2026-10-12 09:18:00'),
    (12, 'Alejandro Torres Gil',    'alejandro.torres@example.com', 'Hotel Retiro Park · Madrid',               '2026-12-05', '2026-12-08', 'confirmada', 534.60,  'Puente de diciembre.',                            '2026-10-01 17:33:00'),
    (13, 'Carmen Ruiz Delgado',     'carmen.ruiz@example.es',       'Apartamento Malagueta · Málaga',           '2026-12-22', '2026-12-29', 'pendiente',  910.00,  NULL,                                              '2026-10-13 22:41:00'),
    (14, 'Miguel Hernández Vidal',  'miguel.hernandez@example.com', 'Hotel Ciutat Vella · Valencia',            '2026-12-30', '2027-01-02', 'confirmada', 612.00,  'Cena de Nochevieja incluida.',                    '2026-08-28 14:09:00'),
    (15, 'Isabel Moreno Serrano',   'isabel.moreno@example.es',     'Apartamento Eixample Diagonal · Barcelona','2027-01-15', '2027-01-20', 'cancelada',  725.25,  'El huésped canceló por motivos personales.',      '2026-09-05 10:44:00'),
    (16, 'Raúl Jiménez Prieto',     'raul.jimenez@example.com',     'Hotel Alameda · Sevilla',                  '2027-02-13', '2027-02-15', 'pendiente',  248.00,  'Escapada de San Valentín.',                       '2026-10-14 08:05:00'),
    (17, 'Nuria Castillo Ramos',    'nuria.castillo@example.com',   'Casa Port de Sóller · Mallorca',           '2027-04-01', '2027-04-06', 'confirmada', 1325.00, NULL,                                              '2026-10-02 20:27:00'),
    (18, 'Íñigo Etxeberria Alonso', 'inigo.etxeberria@example.es',  'Hostal Lavapiés · Madrid',                 '2027-05-14', '2027-05-16', 'pendiente',  0.00,    'Estancia de cortesía (canje de puntos).',         '2026-10-15 07:50:00');

INSERT INTO reserva_evento (reserva_id, tipo, descripcion, fecha_creacion) VALUES
    (1,  'creacion',      'Reserva creada con estado pendiente.',          '2026-05-14 09:12:00'),
    (1,  'cambio_estado', 'Estado cambiado de pendiente a confirmada.',    '2026-05-14 15:30:00'),
    (2,  'creacion',      'Reserva creada con estado pendiente.',          '2026-05-20 18:40:00'),
    (2,  'cambio_estado', 'Estado cambiado de pendiente a confirmada.',    '2026-05-21 09:02:00'),
    (3,  'creacion',      'Reserva creada con estado pendiente.',          '2026-06-02 11:05:00'),
    (3,  'cambio_estado', 'Estado cambiado de pendiente a confirmada.',    '2026-06-03 10:11:00'),
    (3,  'cambio_estado', 'Estado cambiado de confirmada a cancelada.',    '2026-08-20 17:45:00'),
    (4,  'creacion',      'Reserva creada con estado pendiente.',          '2026-08-01 08:30:00'),
    (4,  'cambio_estado', 'Estado cambiado de pendiente a confirmada.',    '2026-08-01 12:16:00'),
    (5,  'creacion',      'Reserva creada con estado pendiente.',          '2026-03-11 21:15:00'),
    (5,  'cambio_estado', 'Estado cambiado de pendiente a confirmada.',    '2026-03-12 09:00:00'),
    (6,  'creacion',      'Reserva creada con estado pendiente.',          '2026-09-25 13:47:00'),
    (6,  'cambio_estado', 'Estado cambiado de pendiente a confirmada.',    '2026-09-25 18:20:00'),
    (7,  'creacion',      'Reserva creada con estado pendiente.',          '2026-10-08 16:20:00'),
    (8,  'creacion',      'Reserva creada con estado pendiente.',          '2026-10-09 10:02:00'),
    (9,  'creacion',      'Reserva creada con estado pendiente.',          '2026-09-30 19:55:00'),
    (9,  'cambio_estado', 'Estado cambiado de pendiente a confirmada.',    '2026-10-01 08:41:00'),
    (10, 'creacion',      'Reserva creada con estado pendiente.',          '2026-09-12 12:00:00'),
    (10, 'cambio_estado', 'Estado cambiado de pendiente a cancelada.',     '2026-09-14 16:33:00'),
    (11, 'creacion',      'Reserva creada con estado pendiente.',          '2026-10-12 09:18:00'),
    (12, 'creacion',      'Reserva creada con estado pendiente.',          '2026-10-01 17:33:00'),
    (12, 'cambio_estado', 'Estado cambiado de pendiente a confirmada.',    '2026-10-02 10:05:00'),
    (13, 'creacion',      'Reserva creada con estado pendiente.',          '2026-10-13 22:41:00'),
    (14, 'creacion',      'Reserva creada con estado pendiente.',          '2026-08-28 14:09:00'),
    (14, 'cambio_estado', 'Estado cambiado de pendiente a confirmada.',    '2026-08-29 11:27:00'),
    (15, 'creacion',      'Reserva creada con estado pendiente.',          '2026-09-05 10:44:00'),
    (15, 'cambio_estado', 'Estado cambiado de pendiente a confirmada.',    '2026-09-05 19:10:00'),
    (15, 'cambio_estado', 'Estado cambiado de confirmada a cancelada.',    '2026-10-06 13:58:00'),
    (16, 'creacion',      'Reserva creada con estado pendiente.',          '2026-10-14 08:05:00'),
    (17, 'creacion',      'Reserva creada con estado pendiente.',          '2026-10-02 20:27:00'),
    (17, 'cambio_estado', 'Estado cambiado de pendiente a confirmada.',    '2026-10-03 09:14:00'),
    (18, 'creacion',      'Reserva creada con estado pendiente.',          '2026-10-15 07:50:00');
