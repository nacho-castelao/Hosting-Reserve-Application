-- =====================================================================
-- Gestor de reservas de alojamientos — esquema + datos de ejemplo
-- Importación única: phpMyAdmin (pestaña "Importar") o CLI:
--   mysql -u USUARIO -p NOMBRE_BD < database/schema.sql
-- Se importa sobre una base de datos YA creada (los hostings compartidos
-- no permiten CREATE DATABASE). Es idempotente: borra y recrea las tablas.
-- Compatible con MySQL >= 8.0.16 y MariaDB >= 10.2 (CHECK aplicado).
--
-- No incluye ningún administrador: se crea con  php bin/crear-admin.php
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- Primero las tablas hijas para no romper las claves foráneas.
DROP TABLE IF EXISTS reserva_evento;
DROP TABLE IF EXISTS reserva;
DROP TABLE IF EXISTS alojamiento;
DROP TABLE IF EXISTS usuario;
DROP TABLE IF EXISTS limite_peticion;

-- Cuentas del panel. Los huéspedes no tienen cuenta.
CREATE TABLE usuario (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre         VARCHAR(150) NOT NULL,
    email          VARCHAR(254) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    rol            ENUM('anfitrion', 'admin') NOT NULL DEFAULT 'anfitrion',
    fecha_creacion TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuario_email (email)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE alojamiento (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    anfitrion_id   INT UNSIGNED  NOT NULL,
    nombre         VARCHAR(200)  NOT NULL,
    ciudad         VARCHAR(100)  NOT NULL,
    precio_noche   DECIMAL(10, 2) NOT NULL,
    activo         TINYINT(1)    NOT NULL DEFAULT 1,
    fecha_creacion TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_alojamiento_anfitrion (anfitrion_id),
    CONSTRAINT chk_alojamiento_precio CHECK (precio_noche > 0),
    -- RESTRICT: no se puede borrar un usuario que tenga alojamientos (y con ellos, reservas).
    CONSTRAINT fk_alojamiento_anfitrion
        FOREIGN KEY (anfitrion_id) REFERENCES usuario (id)
        ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- importe es una foto del precio en el momento de reservar (noches × precio_noche),
-- calculada por el servidor: si después cambia el precio, la reserva no cambia.
CREATE TABLE reserva (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    alojamiento_id     INT UNSIGNED NOT NULL,
    nombre_huesped     VARCHAR(150)  NOT NULL,
    email_huesped      VARCHAR(254)  NOT NULL,
    fecha_entrada      DATE          NOT NULL,
    fecha_salida       DATE          NOT NULL,
    estado             ENUM('pendiente', 'confirmada', 'cancelada') NOT NULL DEFAULT 'pendiente',
    importe            DECIMAL(10, 2) NOT NULL,
    notas              VARCHAR(1000) NULL,
    fecha_creacion     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- También sirve para la comprobación de solapamiento al reservar.
    INDEX idx_reserva_alojamiento_fechas (alojamiento_id, fecha_entrada, fecha_salida),
    INDEX idx_reserva_estado (estado),
    INDEX idx_reserva_fechas (fecha_entrada, fecha_salida),
    INDEX idx_reserva_fecha_salida (fecha_salida),
    INDEX idx_reserva_nombre_huesped (nombre_huesped),
    CONSTRAINT chk_reserva_fechas  CHECK (fecha_salida > fecha_entrada),
    CONSTRAINT chk_reserva_importe CHECK (importe >= 0),
    CONSTRAINT fk_reserva_alojamiento
        FOREIGN KEY (alojamiento_id) REFERENCES alojamiento (id)
        ON DELETE RESTRICT
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE reserva_evento (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reserva_id     INT UNSIGNED NOT NULL,
    -- Quién hizo el cambio. NULL = el huésped desde la web pública.
    usuario_id     INT UNSIGNED NULL,
    tipo           VARCHAR(50)  NOT NULL,
    descripcion    VARCHAR(255) NOT NULL,
    fecha_creacion TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_evento_reserva_fecha (reserva_id, fecha_creacion),
    CONSTRAINT fk_evento_reserva
        FOREIGN KEY (reserva_id) REFERENCES reserva (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_evento_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuario (id)
        ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Rate limiting de ventana fija. clave = sha256(cubo + sujeto): no guarda IPs ni emails en claro.
CREATE TABLE limite_peticion (
    clave    CHAR(64)     NOT NULL,
    intentos INT UNSIGNED NOT NULL,
    inicio   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (clave)
) ENGINE = InnoDB DEFAULT CHARSET = ascii COLLATE = ascii_bin;

-- ---------------------------------------------------------------------
-- Datos de ejemplo: tres anfitriones, sus alojamientos y 18 reservas
-- (pasado, en curso y futuro; los tres estados). Los importes son
-- noches × precio por noche. Los correos usan dominios reservados.
--
-- Contraseña de los tres anfitriones de demostración: demo-anfitrion
-- ---------------------------------------------------------------------
INSERT INTO usuario (id, nombre, email, password_hash, rol, fecha_creacion) VALUES
    (1, 'Lucía Navarro', 'lucia@example.com', '$2y$10$B7Fh391mm.SHwMLkgfukJun9dK3gooRJOCuoOGojPOIOprzayQZgy', 'anfitrion', '2026-01-10 10:00:00'),
    (2, 'Jordi Puig',    'jordi@example.com', '$2y$10$B7Fh391mm.SHwMLkgfukJun9dK3gooRJOCuoOGojPOIOprzayQZgy', 'anfitrion', '2026-01-12 16:30:00'),
    (3, 'Marga Ferrer',  'marga@example.com', '$2y$10$B7Fh391mm.SHwMLkgfukJun9dK3gooRJOCuoOGojPOIOprzayQZgy', 'anfitrion', '2026-02-03 09:15:00');

INSERT INTO alojamiento (id, anfitrion_id, nombre, ciudad, precio_noche, activo, fecha_creacion) VALUES
    (1,  1, 'Hotel Puerta del Sol',          'Madrid',    162.00, 1, '2026-01-10 10:20:00'),
    (2,  1, 'Hotel Gran Vía Suites',         'Madrid',    135.30, 1, '2026-01-10 10:25:00'),
    (3,  1, 'Hotel Patio de Triana',         'Sevilla',   105.25, 1, '2026-01-11 12:00:00'),
    (4,  2, 'Apartamento Barceloneta Mar',   'Barcelona', 170.00, 1, '2026-01-12 17:00:00'),
    (5,  2, 'Apartamento Ruzafa Centro',     'Valencia',   90.00, 1, '2026-01-12 17:10:00'),
    (6,  2, 'Apartamento Malvarrosa Playa',  'Valencia',  128.00, 1, '2026-01-13 09:00:00'),
    (7,  3, 'Villa Cala d''Or',              'Mallorca',  340.00, 1, '2026-02-03 09:30:00'),
    (8,  3, 'Hotel Albaicín Mirador',        'Granada',   109.80, 1, '2026-02-03 09:40:00'),
    (9,  3, 'Apartamento Malagueta',         'Málaga',    130.00, 1, '2026-02-04 11:00:00'),
    (10, 3, 'Casa rural Sierra de Cazorla',  'Jaén',       95.00, 0, '2026-02-05 18:00:00');

INSERT INTO reserva
    (id, alojamiento_id, nombre_huesped, email_huesped, fecha_entrada, fecha_salida, estado, importe, notas, fecha_creacion)
VALUES
    (1,  1, 'María García López',      'maria.garcia@example.com',     '2026-07-02', '2026-07-05', 'confirmada',  486.00, NULL,                                            '2026-05-14 09:12:00'),
    (2,  4, 'Javier Martínez Ruiz',    'javier.martinez@example.es',   '2026-08-10', '2026-08-17', 'confirmada', 1190.00, 'Llegada tarde, sobre las 23:00.',               '2026-05-20 18:40:00'),
    (3,  3, 'Lucía Fernández Sánchez', 'lucia.fernandez@example.com',  '2026-09-04', '2026-09-07', 'cancelada',   315.75, 'Cancelada por cambio de fechas del congreso.',  '2026-06-02 11:05:00'),
    (4,  5, 'Carlos Rodríguez Pérez',  'carlos.rodriguez@example.com', '2026-09-18', '2026-09-21', 'confirmada',  270.00, NULL,                                            '2026-08-01 08:30:00'),
    (5,  7, 'Ana Gómez Navarro',       'ana.gomez@example.es',         '2026-10-10', '2026-10-17', 'confirmada', 2380.00, 'Cuna para bebé y piscina climatizada.',         '2026-03-11 21:15:00'),
    (6,  2, 'David López Jiménez',     'david.lopez@example.com',      '2026-10-13', '2026-10-16', 'confirmada',  405.90, 'Viaje de trabajo, necesita factura a empresa.', '2026-09-25 13:47:00'),
    (7,  6, 'Laura Martín Romero',     'laura.martin@example.es',      '2026-10-14', '2026-10-19', 'pendiente',   640.00, NULL,                                            '2026-10-08 16:20:00'),
    (8,  8, 'Pablo Sánchez Moreno',    'pablo.sanchez@example.com',    '2026-10-24', '2026-10-27', 'pendiente',   329.40, 'Pide habitación con vistas a la Alhambra.',     '2026-10-09 10:02:00'),
    (9,  4, 'Elena Díaz Álvarez',      'elena.diaz@example.com',       '2026-11-05', '2026-11-08', 'confirmada',  510.00, NULL,                                            '2026-09-30 19:55:00'),
    (10, 3, 'Sergio Muñoz Castro',     'sergio.munoz@example.es',      '2026-11-12', '2026-11-15', 'cancelada',   315.75, NULL,                                            '2026-09-12 12:00:00'),
    (11, 7, 'Marta Romero Ortiz',      'marta.romero@example.com',     '2026-12-01', '2026-12-08', 'pendiente',  2380.00, 'Grupo de 6 personas. Consultar traslado.',      '2026-10-12 09:18:00'),
    (12, 1, 'Alejandro Torres Gil',    'alejandro.torres@example.com', '2026-12-05', '2026-12-08', 'confirmada',  486.00, 'Puente de diciembre.',                          '2026-10-01 17:33:00'),
    (13, 9, 'Carmen Ruiz Delgado',     'carmen.ruiz@example.es',       '2026-12-22', '2026-12-29', 'pendiente',   910.00, NULL,                                            '2026-10-13 22:41:00'),
    (14, 5, 'Miguel Hernández Vidal',  'miguel.hernandez@example.com', '2026-12-30', '2027-01-02', 'confirmada',  270.00, 'Cena de Nochevieja incluida.',                  '2026-08-28 14:09:00'),
    (15, 4, 'Isabel Moreno Serrano',   'isabel.moreno@example.es',     '2027-01-15', '2027-01-20', 'cancelada',   850.00, 'El huésped canceló por motivos personales.',    '2026-09-05 10:44:00'),
    (16, 3, 'Raúl Jiménez Prieto',     'raul.jimenez@example.com',     '2027-02-13', '2027-02-15', 'pendiente',   210.50, 'Escapada de San Valentín.',                     '2026-10-14 08:05:00'),
    (17, 7, 'Nuria Castillo Ramos',    'nuria.castillo@example.com',   '2027-04-01', '2027-04-06', 'confirmada', 1700.00, NULL,                                            '2026-10-02 20:27:00'),
    (18, 2, 'Íñigo Etxeberria Alonso', 'inigo.etxeberria@example.es',  '2027-05-14', '2027-05-16', 'pendiente',   270.60, 'Necesita plaza de aparcamiento.',               '2026-10-15 07:50:00');

-- Las altas las hace el huésped (usuario_id NULL); los cambios de estado, el anfitrión del alojamiento.
INSERT INTO reserva_evento (reserva_id, usuario_id, tipo, descripcion, fecha_creacion) VALUES
    (1,  NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-05-14 09:12:00'),
    (1,  1,    'cambio_estado', 'Estado cambiado de pendiente a confirmada.', '2026-05-14 15:30:00'),
    (2,  NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-05-20 18:40:00'),
    (2,  2,    'cambio_estado', 'Estado cambiado de pendiente a confirmada.', '2026-05-21 09:02:00'),
    (3,  NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-06-02 11:05:00'),
    (3,  1,    'cambio_estado', 'Estado cambiado de pendiente a confirmada.', '2026-06-03 10:11:00'),
    (3,  1,    'cambio_estado', 'Estado cambiado de confirmada a cancelada.', '2026-08-20 17:45:00'),
    (4,  NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-08-01 08:30:00'),
    (4,  2,    'cambio_estado', 'Estado cambiado de pendiente a confirmada.', '2026-08-01 12:16:00'),
    (5,  NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-03-11 21:15:00'),
    (5,  3,    'cambio_estado', 'Estado cambiado de pendiente a confirmada.', '2026-03-12 09:00:00'),
    (6,  NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-09-25 13:47:00'),
    (6,  1,    'cambio_estado', 'Estado cambiado de pendiente a confirmada.', '2026-09-25 18:20:00'),
    (7,  NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-10-08 16:20:00'),
    (8,  NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-10-09 10:02:00'),
    (9,  NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-09-30 19:55:00'),
    (9,  2,    'cambio_estado', 'Estado cambiado de pendiente a confirmada.', '2026-10-01 08:41:00'),
    (10, NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-09-12 12:00:00'),
    (10, 1,    'cambio_estado', 'Estado cambiado de pendiente a cancelada.',  '2026-09-14 16:33:00'),
    (11, NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-10-12 09:18:00'),
    (12, NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-10-01 17:33:00'),
    (12, 1,    'cambio_estado', 'Estado cambiado de pendiente a confirmada.', '2026-10-02 10:05:00'),
    (13, NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-10-13 22:41:00'),
    (14, NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-08-28 14:09:00'),
    (14, 2,    'cambio_estado', 'Estado cambiado de pendiente a confirmada.', '2026-08-29 11:27:00'),
    (15, NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-09-05 10:44:00'),
    (15, 2,    'cambio_estado', 'Estado cambiado de pendiente a confirmada.', '2026-09-05 19:10:00'),
    (15, 2,    'cambio_estado', 'Estado cambiado de confirmada a cancelada.', '2026-10-06 13:58:00'),
    (16, NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-10-14 08:05:00'),
    (17, NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-10-02 20:27:00'),
    (17, 3,    'cambio_estado', 'Estado cambiado de pendiente a confirmada.', '2026-10-03 09:14:00'),
    (18, NULL, 'creacion',      'Reserva creada con estado pendiente.',       '2026-10-15 07:50:00');
