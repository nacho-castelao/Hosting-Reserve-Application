# Gestor de reservas de alojamientos

Aplicación web para gestionar reservas de alojamientos: listado con filtros combinables, alta de reservas, detalle con historial de cambios y cancelación. Está construida en **PHP 8.1+ nativo, sin framework**, con arquitectura MVC, PDO + MySQL/MariaDB, vistas **Twig** y **JavaScript vanilla** (módulos ES + `fetch`).

**Demo desplegada:** <https://217-154-98-238.sslip.io/>

---

## Índice

1. [Requisitos e instalación local](#1-requisitos-e-instalación-local)
2. [Estructura del proyecto](#2-estructura-del-proyecto)
3. [Referencia de la API](#3-referencia-de-la-api)
4. [Decisiones técnicas](#4-decisiones-técnicas)
5. [Medidas de seguridad](#5-medidas-de-seguridad)
6. [Uso de `|raw`](#6-uso-de-raw)
7. [Tests](#7-tests)
8. [Despliegue](#8-despliegue)
9. [Qué haría diferente con más tiempo](#9-qué-haría-diferente-con-más-tiempo)
10. [Escalabilidad: 1000 reservas/hora](#10-escalabilidad-1000-reservashora)

---

## 1. Requisitos e instalación local

**Requisitos:** PHP ≥ 8.1 con `pdo_mysql` y `mbstring`, Composer 2, y MySQL ≥ 8.0.16 o MariaDB ≥ 10.2 (necesarios para que se apliquen los `CHECK`).

```bash
# 1. Dependencias (Twig, PHPUnit y el autoloader PSR-4)
composer install

# 2. Crear la base de datos
mysql -u root -p -e "CREATE DATABASE reservas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# 3. Importar el esquema y los datos de ejemplo (18 reservas con su historial)
mysql -u root -p reservas < database/schema.sql

# 4. Configuración
cp .env.example .env      # y ajusta DB_* (en local: APP_ENV=local, APP_DEBUG=true)

# 5. Arrancar
php -S localhost:8000 -t public     # o: composer serve
```

Abre <http://localhost:8000>.

**Con phpMyAdmin (MAMP/WAMP/XAMPP):** crea la base de datos `reservas` con cotejamiento `utf8mb4_unicode_ci`, selecciónala, entra en *Importar*, elige `database/schema.sql` y ejecuta. El script es idempotente (`DROP TABLE IF EXISTS`), así que se puede reimportar para volver a los datos iniciales.

**Con MAMP/Apache:** apunta el *DocumentRoot* a la carpeta `public/`. Si no puedes, el `.htaccess` de la raíz reescribe a `public/` y bloquea el resto (ver [despliegue](#82-hosting-compartido)).

### Variables de `.env`

| Variable | Descripción |
|---|---|
| `APP_ENV` | `production` o `local` |
| `APP_DEBUG` | `true` solo en local: añade detalles de la excepción a los 500 y desactiva la caché de Twig |
| `APP_TIMEZONE` | Opcional, por defecto `Europe/Madrid` |
| `BASE_URL` | Opcional. Vacío = se autodetecta (sirve para la raíz o una subcarpeta) |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | Conexión a la base de datos |

Una variable de entorno real tiene prioridad sobre el `.env`.

---

## 2. Estructura del proyecto

```
public/                  Única carpeta pública: index.php (front controller), .htaccess, assets/
src/
  Core/                  App (arranque y rutas), Router, Request, Response/JsonResponse, Config,
                         Database (PDO), View (Twig), ErrorHandler, Session, Csrf, SecurityHeaders
  Controller/Api/        ReservaController → JSON
  Controller/Web/        ReservaController → HTML (Twig)
  Service/               ReservaService: reglas de negocio, transiciones y transacciones
  Repository/            Único sitio con SQL (+ interfaces y TransactionManager)
  Validation/            ReservaValidator: cuerpo de las escrituras y filtros del listado
  Domain/                EstadoReserva (enum con la máquina de estados), NuevaReserva, FiltroReservas
  Exception/             HttpException y sus subclases (Validation, NotFound, Conflict, MethodNotAllowed)
templates/               base.html.twig, reservas/, partials/, errors/
database/schema.sql      DDL + datos de ejemplo en un solo fichero
tests/                   PHPUnit (validador, máquina de estados y servicio con fakes en memoria)
```

**Flujo de una petición:** `public/index.php` → `App` (configuración, manejador de errores, cabeceras) → `Router` → **Controller** (lee la petición y devuelve la respuesta) → **Service** (valida y aplica las reglas) → **Repository** (SQL). Cualquier excepción sube hasta el `ErrorHandler`, que la traduce al código HTTP correspondiente.

---

## 3. Referencia de la API

Base: `/api`. Todas las respuestas son `application/json; charset=utf-8`.

- Éxito: `{"data": ...}`. El listado añade `"meta": {"total": n, "count": n, "limit": n}`.
- Error: `{"error": {"message": "...", "fields": {"campo": "mensaje"}}}`. `fields` siempre está presente (vacío si no aplica).

| Método | URL | Descripción | Éxito |
|---|---|---|---|
| GET | `/api/reservas?estado=&desde=&hasta=&q=&limit=` | Listado con filtros combinables | 200 |
| GET | `/api/reservas/resumen` | Totales por estado y facturación prevista (importes no cancelados) | 200 |
| GET | `/api/reservas/{id}` | Detalle + historial (`eventos`) | 200 |
| POST | `/api/reservas` | Crear (empieza en `pendiente`) | 201 + `Location` |
| PATCH | `/api/reservas/{id}/estado` | Cambiar estado: `{"estado":"confirmada"\|"cancelada"}` | 200 |

**Códigos de error:** 400 JSON mal formado o filtros inválidos · 403 token CSRF ausente o incorrecto · 404 id o ruta inexistente · 405 método no permitido (con cabecera `Allow`) · 409 transición de estado no permitida · 413 cuerpo demasiado grande (> 16 KB) · 415 escritura sin `Content-Type: application/json` · 422 errores de validación · 500 error interno (mensaje genérico).

**Filtros del listado:**

| Parámetro | Regla |
|---|---|
| `estado` | `pendiente`, `confirmada` o `cancelada` |
| `desde`, `hasta` | Fecha `AAAA-MM-DD`. Devuelve las reservas cuya estancia **se solapa** con el rango (ver [decisiones](#4-decisiones-técnicas)) |
| `q` | Busca en `nombre_huesped` (contiene, sin distinguir mayúsculas ni tildes). `%` y `_` se buscan literalmente. Máx. 100 caracteres |
| `limit` | Entero ≥ 1. Por defecto 50 y como máximo 100 (un valor mayor se recorta a 100) |

Los parámetros desconocidos se ignoran.

### Ejemplos con curl

Las lecturas no necesitan nada más:

```bash
curl -s "http://localhost:8000/api/reservas?estado=confirmada&desde=2026-10-01&hasta=2026-10-31&q=garc"
curl -s "http://localhost:8000/api/reservas/5"
```

Las escrituras exigen la cookie de sesión y el token CSRF que la página publica en `<meta name="csrf-token">`:

```bash
# Obtener cookie de sesión + token
TOKEN=$(curl -s -c cookies.txt http://localhost:8000/ \
  | grep -o 'name="csrf-token" content="[a-f0-9]*' | sed 's/.*content="//')

# Crear una reserva → 201 y cabecera Location: /api/reservas/19
curl -s -i -b cookies.txt -X POST http://localhost:8000/api/reservas \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN" \
  -d '{"nombre_huesped":"Ana López","email_huesped":"ana@example.com",
       "nombre_alojamiento":"Hotel Gran Vía","fecha_entrada":"2027-03-01",
       "fecha_salida":"2027-03-04","importe":"359.90","notas":"Llegada tarde"}'

# Confirmar y después cancelar
curl -s -b cookies.txt -X PATCH http://localhost:8000/api/reservas/19/estado \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN" -d '{"estado":"confirmada"}'
curl -s -b cookies.txt -X PATCH http://localhost:8000/api/reservas/19/estado \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN" -d '{"estado":"cancelada"}'

# Volver a confirmar una cancelada → 409
# {"error":{"message":"No se puede pasar una reserva de cancelada a confirmada.","fields":{}}}
```

Ejemplo de 422 (se devuelven **todos** los errores a la vez):

```json
{"error": {"message": "Los datos enviados no son válidos.", "fields": {
  "estado": "Este campo lo asigna el servidor y no se puede enviar.",
  "email_huesped": "Introduce un email válido.",
  "fecha_entrada": "Debe ser una fecha válida con formato AAAA-MM-DD.",
  "importe": "Este campo es obligatorio."
}}}
```

### Reglas de validación (servidor)

- Obligatorios: `nombre_huesped` (≤ 150), `email_huesped` (≤ 254, `FILTER_VALIDATE_EMAIL`), `nombre_alojamiento` (≤ 200), `fecha_entrada`, `fecha_salida`, `importe`. Opcional: `notas` (≤ 1000). Las longitudes coinciden con el DDL.
- Los textos se recortan (`trim`), un texto vacío cuenta como ausente y se rechazan los caracteres de control.
- Fechas en `Y-m-d` estricto con `DateTimeImmutable::createFromFormat` **y comprobación de ida y vuelta**, que rechaza `2026-02-30`. Además `fecha_salida > fecha_entrada`, y en el alta `fecha_entrada` no puede ser anterior a hoy.
- `importe`: numérico, ≥ 0, con 2 decimales como máximo y que quepa en `DECIMAL(10,2)`. Se normaliza a `"359.90"`.
- **Sin asignación masiva:** solo se aceptan los campos de la lista blanca. `id`, `estado` y `fecha_creacion` dan error explícito y cualquier otro campo desconocido también, para que el cliente sepa que lo ha enviado mal en vez de que se pierda en silencio.

---

## 4. Decisiones técnicas

**Capas (Controller → Service → Repository).** Los controladores solo traducen HTTP; el servicio concentra las reglas (validación, máquina de estados, transacciones) sin una línea de SQL; los repositorios son el único lugar con SQL y no deciden nada de negocio. Los repositorios y la transacción están detrás de interfaces, así que el servicio se prueba con implementaciones en memoria. El "contenedor" es un puñado de métodos perezosos en `App`: un 404 no llega a abrir conexión con la base de datos.

**MySQL/MariaDB.** Es lo que pide la prueba y lo que hay en cualquier hosting compartido. Con InnoDB tengo transacciones, claves foráneas, `ENUM` y `CHECK` para que la base de datos también defienda la integridad (fechas coherentes, importe ≥ 0, estado válido).

**422 para validación, 400 para peticiones mal formadas.** El enunciado menciona 400; he optado por distinguir:
- **400**: no se puede interpretar la petición (JSON roto, que no sea un objeto, o filtros de la query string con valores imposibles).
- **422**: el JSON es correcto pero su contenido no cumple las reglas de negocio.

Así el cliente sabe si el fallo está en *cómo* envía o en *qué* envía, y el formulario puede pintar los `fields` del 422 junto a cada campo. El cuerpo de error tiene la misma forma en los dos casos, por lo que cambiar a 400 sería una línea (`ValidationException`).

**Filtro de fechas por solapamiento.** Una estancia ocupa las noches `[fecha_entrada, fecha_salida)`, porque el día de salida queda libre. Con `desde`/`hasta` se devuelven las reservas que tienen **alguna noche dentro del rango**: `fecha_entrada <= hasta AND fecha_salida > desde`. Responde a la pregunta útil ("¿quién está alojado esta semana?"), algo que "entrada dentro del rango" no resolvería. Con un solo extremo la condición es la mitad correspondiente.

**Máquina de estados.** Vive en el enum `EstadoReserva` (`allowedTransitions()` / `canTransitionTo()`): `pendiente → confirmada`, `pendiente → cancelada`, `confirmada → cancelada`. `cancelada` es final. Repetir el estado actual o cualquier otra transición devuelve **409 Conflict**: la petición es válida, pero choca con el estado actual del recurso.

**Historial en la misma transacción.** Crear una reserva y registrar su evento `creacion`, o cambiar el estado y registrar `cambio_estado`, ocurre dentro de una única transacción: si falla el evento, no queda un cambio sin historial. En el cambio de estado la fila se lee con `SELECT … FOR UPDATE`, de modo que dos peticiones simultáneas (confirmar y cancelar a la vez) no pueden validar la transición sobre el mismo estado de partida.

**CSRF.** La API es *same-origin* y usa la cookie de sesión, así que hace falta protección CSRF:
- token aleatorio de 256 bits en la sesión, publicado en `<meta name="csrf-token">`;
- el JS lo envía en la cabecera `X-CSRF-Token` y el servidor lo compara con `hash_equals`;
- el formulario sin JS lo envía como campo oculto `_csrf`.

Como defensa adicional, las escrituras de la API exigen `Content-Type: application/json`: un formulario de otra web no puede enviarlo sin un *preflight* CORS, y la API no devuelve cabeceras CORS. La cookie además es `SameSite=Lax`.

**Mejora progresiva.** El primer render es en servidor y funciona sin JS: los filtros son un `GET` y el alta es un formulario clásico con PRG (303 al detalle; si hay errores, se vuelve a pintar con 422 y se conservan los valores). Con JS, los filtros se aplican en vivo (debounce de 300 ms, cancelación de la petición anterior con `AbortController` y URL sincronizada con `history.replaceState`), el alta (en un panel lateral que sin JS se abre con `:target`) y los cambios de estado van por la API sin recargar, y aparecen los botones *Confirmar* y *Cancelar*, que necesitan PATCH y piden confirmación con un `<dialog>` modal. El tema claro/oscuro sigue al sistema y se puede fijar con el botón de la cabecera; los iconos salen de un único sprite SVG que comparten Twig y JS.

**Importes como cadena.** En el JSON `importe` es una cadena decimal (`"1190.50"`), igual que lo devuelve `DECIMAL`, para no introducir errores de coma flotante.

---

## 5. Medidas de seguridad

**Base de datos**
- PDO con `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false` (sentencias preparadas reales), `FETCH_ASSOC` y `charset=utf8mb4` en el DSN.
- Sentencias preparadas en todas las consultas. Solo se concatenan fragmentos fijos de SQL, nunca valores.
- `LIKE` con `%` y `_` escapados (`ESCAPE '!'`, independiente del modo `NO_BACKSLASH_ESCAPES`).
- Parámetros de ruta restringidos por regex a enteros positivos (`[1-9][0-9]{0,9}`) y convertidos a `int`.
- Usuario de base de datos recomendado con permisos solo sobre su esquema.

**Entrada**
- Validación completa en servidor (la del cliente es solo para la experiencia de uso). Lista blanca de campos, sin asignación masiva.
- Cuerpo limitado a 16 KB (413), `json_decode` con `JSON_THROW_ON_ERROR` y profundidad 32 (400), y `Content-Type: application/json` obligatorio en escrituras (415).
- Parámetros de la query string con forma de array (`?estado[]=x`) rechazados.

**Salida / XSS**
- Twig con `autoescape: 'html'` y sin `|raw`.
- El JS construye el DOM con `createElement` / `textContent`. **No hay `innerHTML`** con datos.
- JSON con `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT` y `X-Content-Type-Options: nosniff`.

**Cabeceras HTTP** (`SecurityHeaders`)
- CSP: `default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'`. Sin scripts ni estilos inline, lo que de paso garantiza que no hay manejadores `onclick`.
- `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`.
- `Cache-Control: no-store` en respuestas dinámicas (llevan token CSRF y datos personales).
- `Strict-Transport-Security` cuando la petición es HTTPS. Se elimina `X-Powered-By`.
- Sin cabeceras CORS: la API es *same-origin*.

**Sesión y CSRF**
- Cookie `HttpOnly`, `SameSite=Lax` y `Secure` en HTTPS, con `path` limitado a la aplicación.
- `session.use_strict_mode=1` y `use_only_cookies=1`.
- Token CSRF por sesión verificado con `hash_equals` en todas las escrituras (API y formulario).

**Errores**
- `ErrorHandler` central (`set_exception_handler`, `set_error_handler` que convierte warnings en excepciones, y `register_shutdown_function` para fatales).
- Las `HttpException` muestran su mensaje, que es seguro por diseño. Cualquier otra excepción (`PDOException` incluida) devuelve un 500 con mensaje genérico, y el detalle con su traza se registra en `var/log/app.log`.
- `APP_DEBUG=true` (solo en local) añade clase, mensaje y fichero a la respuesta 500.
- En la web, 404 y error propios que extienden `base.html.twig`.

**Configuración y despliegue**
- Credenciales en `.env`, ignorado por Git. Solo se versiona `.env.example`.
- Solo `public/` es accesible. `.htaccess` deniega ficheros ocultos y el listado de directorios.
- El `.htaccess` raíz para hostings bloquea `src/`, `templates/`, `vendor/`, `database/`, `tests/`, `var/`, `.env` y `composer.*`.

**Pendiente (fuera de alcance):** autenticación/autorización y rate limiting (ver punto 9).

---

## 6. Uso de `|raw`

**No se usa.** Todas las variables pasan por el escapado automático de Twig. Los fragmentos de HTML reutilizables (etiqueta de estado, importe, error de campo) son macros e *includes*, no cadenas con HTML.

---

## 7. Tests

```bash
composer test
```

49 tests unitarios (PHPUnit 10):
- **`ReservaValidatorTest`**: payload válido y normalizado, todos los obligatorios a la vez, email inválido, salida ≤ entrada, fechas imposibles o mal formadas (`2027-02-30`, `2027-13-01`…), entrada en el pasado, importes inválidos, campos del servidor o desconocidos, longitudes, filtros (400) y límite máximo.
- **`EstadoReservaTest`**: matriz completa de transiciones permitidas y prohibidas.
- **`RouterTest` / `RequestTest`**: ids no numéricos → 404, 405 con `Allow`, HEAD, y cuerpos rechazados (400 JSON roto o no objeto, 413, 415).
- **`ReservaServiceTest`**: con repositorios y transacción en memoria. Comprueba el evento al crear y al cambiar de estado, que no se toca nada si la validación falla, y los casos 409 (mismo estado o estado final) y 404.

Además, durante el desarrollo se probaron manualmente con curl todos los códigos de la API (200, 201, 400, 403, 404, 405, 409, 413, 415, 422, 500), además del flujo en navegador (filtros, alta, cancelación y XSS).

---

## 8. Despliegue

Hay dos formas de desplegar la aplicación. La demo pública usa la primera.

### 8.1 VPS con Ubuntu 24.04 (la demo)

Servidor IONOS VPS Linux S+ (1 vCore, 2 GB RAM, 60 GB NVMe), Ubuntu 24.04.5 LTS, datacenter en la UE. Acceso root por SSH con clave, sin contraseña.

**Pila:** Nginx con el DocumentRoot en `public/`, PHP 8.3-FPM, MariaDB 10.11, certificado HTTPS de Let's Encrypt renovado automáticamente con certbot. Cortafuegos UFW (solo SSH, HTTP y HTTPS), actualizaciones de seguridad automáticas y 1 GB de swap.

**Pasos resumidos:**

1. Crear la base de datos `reservas` (utf8mb4) y un usuario con privilegios solo sobre ella. Importar `database/schema.sql`.
2. Clonar el repositorio en `/var/www/reservas` y ejecutar `composer install --no-dev --optimize-autoloader`.
3. Crear `.env` a partir de `.env.example` con `APP_ENV=production`, `APP_DEBUG=false` y las credenciales de la base de datos. Dar permisos `640` al `.env`.
4. Dar escritura a `www-data` sobre `var/` y apuntar el `root` de Nginx a `public/`. Denegar el acceso a ficheros ocultos.
5. Obtener el certificado con `certbot --nginx` y redirigir HTTP a HTTPS.

Para actualizar: `git pull --ff-only` dentro de la carpeta y, si cambian las dependencias, `composer install --no-dev --optimize-autoloader`.

### 8.2 Hosting compartido

Pensado para hostings gratuitos con PHP + MySQL (InfinityFree, AwardSpace…), donde normalmente **no hay Composer ni SSH** y **no se puede cambiar el DocumentRoot**.

1. En local, instala las dependencias de producción: `composer install --no-dev --optimize-autoloader`.
2. Crea la base de datos desde el panel del hosting y anota host, nombre, usuario y contraseña.
3. En su phpMyAdmin, selecciona la base de datos → *Importar* → `database/schema.sql`. El script no hace `CREATE DATABASE`, porque los hostings no lo permiten.
4. Crea el `.env` a partir de `.env.example` con esos datos, `APP_ENV=production` y `APP_DEBUG=false`.
5. Sube por FTP **todo el proyecto, incluida `vendor/`**, a `htdocs/` (o a una subcarpeta). No hace falta subir `tests/` ni `.git/`.
   - El `.htaccess` de la raíz reescribe cada petición a `public/` y bloquea el código, las dependencias y el `.env`.
   - La ruta base se autodetecta. Si el hosting hace algo raro con las rutas, fíjala con `BASE_URL=/subcarpeta`.
6. Comprueba que `var/` es escribible (logs y caché de Twig). Si no, crea `var/log` y `var/cache` con permisos 775.
7. Verifica que `https://tu-dominio/.env` y `https://tu-dominio/src/Core/App.php` devuelven **403**.

Si el hosting **sí** permite apuntar el DocumentRoot a `public/`, es preferible: entonces el `.htaccess` raíz no interviene.

---

## 9. Qué haría diferente con más tiempo

- **Autenticación y autorización** (gestores con login y roles) y **rate limiting** en las escrituras.
- **Paginación** real con `offset`/cursor (ahora hay `limit` ≤ 100 y `meta.total`) y ordenación configurable.
- **Tests de integración** de repositorios contra una base de datos efímera y **tests end-to-end** de la API y del front (Playwright).
- **Logging estructurado** con Monolog (con id de petición) y métricas.
- **Docker Compose** (PHP-FPM + Nginx + MySQL) para que el entorno sea reproducible, y **CI** con PHPStan/Psalm, PHP-CS-Fixer y los tests en cada push.
- **Migraciones** versionadas en lugar de un único `schema.sql`.
- Contenedor de dependencias y router con middlewares (CSRF, cabeceras), en lugar del cableado manual de `App`.
- Edición de reservas y comprobación de **disponibilidad/overbooking** por alojamiento (requiere modelar alojamientos como entidad).
- Internacionalización de mensajes y documentación OpenAPI de la API.

---

## 10. Escalabilidad: 1000 reservas/hora

Siendo honestos, 1000 reservas/hora son **unas 0,3 escrituras por segundo**: una carga baja para un solo MySQL bien indexado. Incluso con picos de 10× y varias lecturas por cada alta, un servidor modesto lo absorbe. Aun así, esto es lo que cuidaría para crecer sin sorpresas:

- **Índices.** Ya hay índices en `estado`, `(fecha_entrada, fecha_salida)`, `fecha_salida`, `nombre_huesped` y `(reserva_id, fecha_creacion)` en eventos. Revisaría los planes con `EXPLAIN` sobre datos reales. Para la búsqueda `LIKE '%texto%'`, que no usa índice, a gran escala pasaría a un índice `FULLTEXT` o a un motor de búsqueda.
- **Conexiones.** Una conexión PDO por petición, que solo se abre si la ruta la necesita. Con PHP-FPM, dimensionaría `pm.max_children` frente a `max_connections` de MySQL y, si hiciera falta, metería un proxy de conexiones (ProxySQL).
- **Lecturas.** El listado es lo más consultado. Lo serviría desde **réplicas de lectura** y/o con una **caché** corta (Redis/APCu) invalidada al escribir. Las escrituras y el `SELECT … FOR UPDATE` siempre van al primario.
- **Efectos secundarios fuera de la petición.** Emails de confirmación, avisos o integraciones irían a una **cola** (Redis/RabbitMQ/SQS) con workers y reintentos, para que la petición no dependa de un SMTP lento. El evento se registraría en la misma transacción (patrón *outbox*) para no perder ninguno.
- **Idempotencia.** Una cabecera `Idempotency-Key` en el `POST` (guardada con restricción `UNIQUE`) evita reservas duplicadas por reintentos o dobles clics en redes inestables.
- **Concurrencia y overbooking.** Los cambios de estado ya bloquean la fila. Para impedir dobles reservas del mismo alojamiento habría que modelar el alojamiento como entidad y, en la transacción del alta, bloquear sus reservas solapadas (`SELECT … FOR UPDATE` por alojamiento y rango) o usar una tabla de noches con `UNIQUE (alojamiento_id, noche)`, que deja la garantía en manos de la base de datos.
- **Escalado horizontal.** Varias instancias de PHP tras un balanceador. Para que sean intercambiables, las **sesiones** (y con ellas el token CSRF) se guardarían en Redis o en la base de datos en vez de en ficheros locales, o se usaría un token firmado sin estado.
- **Observabilidad.** Logs estructurados centralizados, métricas (latencia p95, tasa de 5xx, tiempo de consultas, conexiones), *slow query log* y alertas. Sin medir no se sabe cuándo escalar.
- **Datos.** Copias de seguridad automáticas con restauración probada y, a largo plazo, archivado de reservas antiguas y de su historial.
