# Gestor de reservas de alojamientos

Aplicación web de reservas de alojamientos con tres papeles:

- **Huéspedes** (sin cuenta): eligen un alojamiento del catálogo público y sus fechas. El **precio lo calcula el servidor** (noches × precio por noche del alojamiento) y la reserva queda pendiente.
- **Anfitriones** (registro e inicio de sesión): publican sus alojamientos con su precio por noche y gestionan **solo** las reservas de sus alojamientos: listado con filtros combinables, detalle con historial, confirmación y cancelación.
- **Administrador**: ve y gestiona todo, más el listado de anfitriones. No se puede crear desde la web, solo con un script de consola.

Está construida en **PHP 8.1+ nativo, sin framework**, con arquitectura MVC, PDO + MySQL/MariaDB, vistas **Twig** y **JavaScript vanilla** (módulos ES + `fetch`).

**Demo desplegada:** <https://bookhaven.dev/> · Anfitriones de prueba: `lucia@example.com`, `jordi@example.com` y `marga@example.com`, todos con la contraseña `demo-anfitrion`.

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

# 3. Importar el esquema y los datos de ejemplo
#    (3 anfitriones, 10 alojamientos y 18 reservas con su historial)
mysql -u root -p reservas < database/schema.sql

# 4. Configuración
cp .env.example .env      # y ajusta DB_* (en local: APP_ENV=local, APP_DEBUG=true)

# 5. Crear tu cuenta de administrador (pide nombre, email y contraseña)
php bin/crear-admin.php

# 6. Arrancar
php -S localhost:8000 -t public     # o: composer serve
```

Abre <http://localhost:8000> (catálogo público) o <http://localhost:8000/login> (panel).

**Con phpMyAdmin (MAMP/WAMP/XAMPP):** crea la base de datos `reservas` con cotejamiento `utf8mb4_unicode_ci`, selecciónala, entra en *Importar*, elige `database/schema.sql` y ejecuta. El script es idempotente (`DROP TABLE IF EXISTS`), así que se puede reimportar para volver a los datos iniciales. **Reimportarlo borra también las cuentas**, incluida la de administrador: vuelve a ejecutar `php bin/crear-admin.php`.

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

### Cuentas y roles

| Rol | Cómo se obtiene | Qué puede hacer |
|---|---|---|
| Huésped | No tiene cuenta | Ver el catálogo y solicitar reservas. No puede consultar reservas después: el resumen se le muestra una sola vez |
| Anfitrión | `/registro` en la web | Crear y editar **sus** alojamientos; listar, ver, confirmar y cancelar las reservas de **sus** alojamientos |
| Administrador | `php bin/crear-admin.php` | Todo lo anterior sobre todos los alojamientos y reservas, y el listado de anfitriones (`/panel/usuarios`) |

`bin/crear-admin.php` se ejecuta en la consola del servidor, con el `.env` configurado. Si el email ya existe (por ejemplo, un anfitrión), promociona esa cuenta a administrador y le asigna la contraseña nueva. Solo funciona desde la línea de comandos (`PHP_SAPI === 'cli'`) y `bin/` no es accesible por HTTP.

---

## 2. Estructura del proyecto

```
public/                  Única carpeta pública: index.php (front controller), .htaccess, assets/
bin/crear-admin.php      Alta de administradores (solo consola)
src/
  Core/                  App (arranque, rutas y protecciones), Router, Request, Response/JsonResponse,
                         Config, Database (PDO), View (Twig), ErrorHandler, Session, Csrf, Auth,
                         SecurityHeaders
  Controller/Api/        ReservaController, AlojamientoController → JSON
  Controller/Web/        CatalogoController (público), AuthController (login/registro/logout),
                         ReservaController y PanelController (panel) → HTML (Twig)
  Service/               ReservaService, AlojamientoService, AuthService, RateLimiter
  Repository/            Único sitio con SQL (+ interfaces y TransactionManager)
  Validation/            ReservaValidator, AlojamientoValidator, AuthValidator (+ FieldRules común)
  Domain/                EstadoReserva (máquina de estados), Rol, UsuarioActual, Importe,
                         NuevaReserva, DatosAlojamiento, NuevoUsuario, FiltroReservas
  Exception/             HttpException y sus subclases (Validation, NotFound, Conflict, Unauthorized,
                         Forbidden, TooManyRequests, MethodNotAllowed)
templates/               base.html.twig, catalogo/, auth/, reservas/, panel/, partials/, errors/
database/schema.sql      DDL + datos de ejemplo en un solo fichero
tests/                   PHPUnit (validadores, dominio, servicios con fakes en memoria, router y request)
```

**Flujo de una petición:** `public/index.php` → `App` (configuración, manejador de errores, cabeceras) → `Router` → protecciones de la ruta (CSRF, sesión y rol, rate limiting) → **Controller** (lee la petición y devuelve la respuesta) → **Service** (valida, autoriza y aplica las reglas) → **Repository** (SQL). Cualquier excepción sube hasta el `ErrorHandler`, que la traduce al código HTTP correspondiente.

### Rutas web

| Ruta | Acceso | Página |
|---|---|---|
| `GET /` | Público | Catálogo de alojamientos activos |
| `GET /alojamientos/{id}` · `POST /alojamientos/{id}/reservas` | Público | Ficha y formulario de reserva (sin JS: POST clásico con PRG) |
| `GET /reserva-recibida` | Público | Resumen de la reserva recién hecha (un solo uso) |
| `GET/POST /login` · `GET/POST /registro` · `POST /logout` | Público | Acceso de anfitriones |
| `GET /panel` · `GET /panel/reservas/{id}` | Sesión | Listado y detalle de reservas |
| `GET/POST /panel/alojamientos` · `GET/POST /panel/alojamientos/{id}` | Sesión | Alta, listado y edición de alojamientos |
| `GET /panel/usuarios` | Administrador | Anfitriones registrados |

Sin sesión, las páginas del panel redirigen a `/login?next=…` y, tras entrar, se vuelve a la página pedida. `next` solo admite rutas del propio panel, así que el login no sirve de redirección abierta.

---

## 3. Referencia de la API

Base: `/api`. Todas las respuestas son `application/json; charset=utf-8`.

- Éxito: `{"data": ...}`. El listado añade `"meta": {"total": n, "count": n, "limit": n}`.
- Error: `{"error": {"message": "...", "fields": {"campo": "mensaje"}}}`. `fields` siempre está presente (vacío si no aplica).

| Método | URL | Acceso | Descripción | Éxito |
|---|---|---|---|---|
| GET | `/api/alojamientos` | Público | Alojamientos activos (`id`, `nombre`, `ciudad`, `precio_noche`) | 200 |
| POST | `/api/reservas` | Público + CSRF | Reserva del huésped. Empieza en `pendiente` y el servidor calcula el `importe` | 201 |
| GET | `/api/reservas?estado=&desde=&hasta=&q=&limit=` | Sesión | Listado con filtros combinables (el anfitrión solo ve las suyas) | 200 |
| GET | `/api/reservas/resumen` | Sesión | Totales por estado y facturación prevista (importes no cancelados) | 200 |
| GET | `/api/reservas/{id}` | Sesión | Detalle + historial (`eventos`, con el `usuario` que hizo cada cambio) | 200 |
| PATCH | `/api/reservas/{id}/estado` | Sesión + CSRF | Cambiar estado: `{"estado":"confirmada"\|"cancelada"}` | 200 |

**Códigos de error:** 400 JSON mal formado o filtros inválidos · 401 sin sesión · 403 token CSRF ausente o incorrecto, o rol insuficiente · 404 id o ruta inexistente, alojamiento inactivo, o reserva de otro anfitrión · 405 método no permitido (con cabecera `Allow`) · 409 transición de estado no permitida o fechas ya ocupadas · 413 cuerpo demasiado grande (> 16 KB) · 415 escritura sin `Content-Type: application/json` · 422 errores de validación · 429 demasiadas peticiones (con cabecera `Retry-After`) · 500 error interno (mensaje genérico).

**Filtros del listado:**

| Parámetro | Regla |
|---|---|
| `estado` | `pendiente`, `confirmada` o `cancelada` |
| `desde`, `hasta` | Fecha `AAAA-MM-DD`. Devuelve las reservas cuya estancia **se solapa** con el rango (ver [decisiones](#4-decisiones-técnicas)) |
| `q` | Busca en `nombre_huesped` (contiene, sin distinguir mayúsculas ni tildes). `%` y `_` se buscan literalmente. Máx. 100 caracteres |
| `limit` | Entero ≥ 1. Por defecto 50 y como máximo 100 (un valor mayor se recorta a 100) |

Los parámetros desconocidos se ignoran.

### Ejemplos con curl

El catálogo es público:

```bash
curl -s "http://localhost:8000/api/alojamientos"
```

Las escrituras exigen la cookie de sesión y el token CSRF que la página publica en `<meta name="csrf-token">`. Un huésped reserva así:

```bash
# Cookie de sesión + token
TOKEN=$(curl -s -c huesped.txt http://localhost:8000/ \
  | grep -o 'name="csrf-token" content="[a-f0-9]*' | sed 's/.*content="//')

# Reservar 3 noches en el alojamiento 2 (135,30 €/noche) → 201 con "importe":"405.90"
curl -s -b huesped.txt -X POST http://localhost:8000/api/reservas \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN" \
  -d '{"alojamiento_id":2,"nombre_huesped":"Ana López","email_huesped":"ana@example.com",
       "fecha_entrada":"2027-03-01","fecha_salida":"2027-03-04","notas":"Llegada tarde"}'

# Las mismas noches otra vez → 409
# {"error":{"message":"El alojamiento ya está reservado en alguna de esas noches. Prueba con otras fechas.","fields":{}}}
```

El panel necesita iniciar sesión. Al entrar cambian la cookie y el token, así que hay que volver a leerlo:

```bash
TOKEN=$(curl -s -c panel.txt http://localhost:8000/login \
  | grep -o 'name="csrf-token" content="[a-f0-9]*' | sed 's/.*content="//')
curl -s -b panel.txt -c panel.txt -X POST http://localhost:8000/login \
  --data-urlencode "_csrf=$TOKEN" -d 'email=lucia@example.com&password=demo-anfitrion'
TOKEN=$(curl -s -b panel.txt http://localhost:8000/panel \
  | grep -o 'name="csrf-token" content="[a-f0-9]*' | sed 's/.*content="//')

# Lucía solo ve las reservas de sus alojamientos
curl -s -b panel.txt "http://localhost:8000/api/reservas?estado=pendiente"

# Confirmar y después cancelar una de sus reservas
curl -s -b panel.txt -X PATCH http://localhost:8000/api/reservas/18/estado \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN" -d '{"estado":"confirmada"}'
curl -s -b panel.txt -X PATCH http://localhost:8000/api/reservas/18/estado \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $TOKEN" -d '{"estado":"cancelada"}'

# Una reserva de otro anfitrión → 404 (no se revela si existe)
curl -s -b panel.txt http://localhost:8000/api/reservas/2
```

Ejemplo de 422 (se devuelven **todos** los errores a la vez):

```json
{"error": {"message": "Los datos enviados no son válidos.", "fields": {
  "importe": "Este campo lo asigna el servidor y no se puede enviar.",
  "email_huesped": "Introduce un email válido.",
  "fecha_entrada": "Debe ser una fecha válida con formato AAAA-MM-DD.",
  "alojamiento_id": "Este campo es obligatorio."
}}}
```

### Reglas de validación (servidor)

- **Reserva.** Obligatorios: `alojamiento_id` (entero positivo), `nombre_huesped` (≤ 150), `email_huesped` (≤ 254, `FILTER_VALIDATE_EMAIL`), `fecha_entrada`, `fecha_salida`. Opcional: `notas` (≤ 1000). Las longitudes coinciden con el DDL.
- **Alojamiento.** `nombre` (≤ 200), `ciudad` (≤ 100), `precio_noche` (> 0, máx. 2 decimales, cabe en `DECIMAL(10,2)`), `activo` (casilla).
- **Registro.** `nombre` (≤ 150), `email` (se guarda en minúsculas y es único), contraseña de 10 caracteres como mínimo y 72 bytes como máximo (el límite de bcrypt; se rechaza en vez de truncarse en silencio), y su confirmación.
- Los textos se recortan (`trim`), un texto vacío cuenta como ausente y se rechazan los caracteres de control.
- Fechas en `Y-m-d` estricto con `DateTimeImmutable::createFromFormat` **y comprobación de ida y vuelta**, que rechaza `2026-02-30`. Además `fecha_salida > fecha_entrada`, la entrada no puede ser anterior a hoy y la estancia no puede superar las 90 noches (una reserva pública muy larga bloquearía el alojamiento durante meses).
- **Sin asignación masiva:** solo se aceptan los campos de la lista blanca. `id`, `estado`, `importe`, `fecha_creacion` y `anfitrion_id` dan error explícito y cualquier otro campo desconocido también, para que el cliente sepa que lo ha enviado mal en vez de que se pierda en silencio. Al registrarse no hay forma de elegir rol.

---

## 4. Decisiones técnicas

**Capas (Controller → Service → Repository).** Los controladores solo traducen HTTP; los servicios concentran las reglas (validación, autorización, máquina de estados, precio, transacciones) sin una línea de SQL; los repositorios son el único lugar con SQL y no deciden nada de negocio. Los repositorios y la transacción están detrás de interfaces, así que los servicios se prueban con implementaciones en memoria. El "contenedor" es un puñado de métodos perezosos en `App`: un 404 no llega a abrir conexión con la base de datos.

**El precio lo pone el servidor.** Que quien hace la reserva escribiera el importe no tenía sentido. Ahora el alojamiento es una entidad con `precio_noche` y su anfitrión, y el importe se calcula al reservar como noches × precio por noche (`Importe::porNoches`, en céntimos enteros para no arrastrar errores de coma flotante). Se guarda en la reserva como **foto del precio en ese momento**: si el anfitrión cambia después el precio, las reservas ya hechas no cambian. Si el cliente envía `importe`, recibe un 422.

**Sin solapamientos (overbooking).** Al reservar, el servicio bloquea la fila del alojamiento (`SELECT … FOR UPDATE`) y comprueba que ninguna reserva pendiente o confirmada ocupe alguna de esas noches. Si la hay, responde 409. El bloqueo serializa las altas de un mismo alojamiento, así que dos huéspedes no pueden pasar a la vez la comprobación con las mismas fechas. Las reservas canceladas liberan sus noches, y el día de salida de una estancia puede ser el de entrada de la siguiente.

**Autorización: 404 en vez de 403.** Un anfitrión que pide una reserva o un alojamiento de otro recibe 404, igual que si no existiera: así no puede averiguar qué ids existen. El ámbito se aplica en el servicio (`UsuarioActual::ambitoAnfitrion()` → `WHERE a.anfitrion_id = :anfitrion`), no en el controlador, para que ninguna ruta nueva pueda olvidarlo. El 403 queda para cuando el rol no alcanza (`/panel/usuarios` siendo anfitrión).

**Sesión con el id, no con el rol.** La sesión guarda solo el id del usuario; nombre y rol se leen de la base de datos en cada petición. Promocionar o borrar una cuenta tiene efecto inmediato, sin esperar a que caduque la sesión.

**Historial con autor.** Cada evento guarda `usuario_id`: `NULL` cuando el alta la hace un huésped desde la web pública y el id del anfitrión o administrador en los cambios de estado. El detalle muestra quién hizo cada cambio.

**MySQL/MariaDB.** Es lo que pide la prueba y lo que hay en cualquier hosting compartido. Con InnoDB tengo transacciones, claves foráneas, `ENUM` y `CHECK` para que la base de datos también defienda la integridad (fechas coherentes, importe ≥ 0, precio > 0, estado válido, email único).

**422 para validación, 400 para peticiones mal formadas.** El enunciado menciona 400; he optado por distinguir:
- **400**: no se puede interpretar la petición (JSON roto, que no sea un objeto, o filtros de la query string con valores imposibles).
- **422**: el JSON es correcto pero su contenido no cumple las reglas de negocio.

Así el cliente sabe si el fallo está en *cómo* envía o en *qué* envía, y el formulario puede pintar los `fields` del 422 junto a cada campo. El cuerpo de error tiene la misma forma en los dos casos, por lo que cambiar a 400 sería una línea (`ValidationException`).

**Filtro de fechas por solapamiento.** Una estancia ocupa las noches `[fecha_entrada, fecha_salida)`, porque el día de salida queda libre. Con `desde`/`hasta` se devuelven las reservas que tienen **alguna noche dentro del rango**: `fecha_entrada <= hasta AND fecha_salida > desde`. Responde a la pregunta útil ("¿quién está alojado esta semana?"), algo que "entrada dentro del rango" no resolvería. Con un solo extremo la condición es la mitad correspondiente.

**Máquina de estados.** Vive en el enum `EstadoReserva` (`allowedTransitions()` / `canTransitionTo()`): `pendiente → confirmada`, `pendiente → cancelada`, `confirmada → cancelada`. `cancelada` es final. Repetir el estado actual o cualquier otra transición devuelve **409 Conflict**: la petición es válida, pero choca con el estado actual del recurso.

**Historial en la misma transacción.** Crear una reserva y registrar su evento `creacion`, o cambiar el estado y registrar `cambio_estado`, ocurre dentro de una única transacción: si falla el evento, no queda un cambio sin historial. En el cambio de estado la fila se lee con `SELECT … FOR UPDATE`, de modo que dos peticiones simultáneas (confirmar y cancelar a la vez) no pueden validar la transición sobre el mismo estado de partida.

**Rate limiting en la base de datos.** Contadores de ventana fija en la tabla `limite_peticion`, actualizados con un único `INSERT … ON DUPLICATE KEY UPDATE` atómico. Funciona igual con varios procesos PHP-FPM, sobrevive a reinicios y no añade dependencias (Redis sería mejor a gran escala, ver punto 10). Las claves se guardan como `sha256(cubo + sujeto)`: la tabla no contiene IPs ni emails en claro.

**CSRF.** La API es *same-origin* y usa la cookie de sesión, así que hace falta protección CSRF:
- token aleatorio de 256 bits en la sesión, publicado en `<meta name="csrf-token">`;
- el JS lo envía en la cabecera `X-CSRF-Token` y el servidor lo compara con `hash_equals`;
- los formularios sin JS (reserva, login, registro, logout, alojamientos) lo envían como campo oculto `_csrf`;
- el token se **renueva al iniciar sesión**, de modo que uno capturado antes del login no sirve después.

Como defensa adicional, las escrituras de la API exigen `Content-Type: application/json`: un formulario de otra web no puede enviarlo sin un *preflight* CORS, y la API no devuelve cabeceras CORS. La cookie además es `SameSite=Lax`. El cierre de sesión es un `POST` con token, así que una imagen o un enlace de otra web no pueden cerrar la sesión de nadie.

**Mejora progresiva.** El primer render es en servidor y todo funciona sin JS: los filtros son un `GET`, y la reserva, el login, el registro y los alojamientos son formularios clásicos con PRG (303; si hay errores, se vuelve a pintar con 422 y se conservan los valores, salvo las contraseñas). Con JS, los filtros se aplican en vivo (debounce de 300 ms, cancelación de la petición anterior con `AbortController` y URL sincronizada con `history.replaceState`); la ficha del alojamiento muestra el total estimado al elegir fechas ("3 noches × 109,80 € = 329,40 €", calculado en céntimos como en el servidor) y la reserva va por la API sin recargar; y aparecen los botones *Confirmar* y *Cancelar*, que necesitan PATCH y piden confirmación con un `<dialog>` modal. Si la sesión caduca, una respuesta 401 de la API lleva al login y después de vuelta a la página. El tema claro/oscuro sigue al sistema y se puede fijar con el botón de la cabecera; los iconos salen de un único sprite SVG que comparten Twig y JS.

**Importes como cadena.** En el JSON `importe` y `precio_noche` son cadenas decimales (`"1190.50"`), igual que las devuelve `DECIMAL`, para no introducir errores de coma flotante.

---

## 5. Medidas de seguridad

**Autenticación**
- Contraseñas con `password_hash` (`PASSWORD_DEFAULT`, bcrypt) y `password_verify`. Si cambia el algoritmo o el coste por defecto de PHP, el hash se actualiza al iniciar sesión (`password_needs_rehash`).
- Mismo mensaje para "email inexistente" y "contraseña incorrecta". Cuando el email no existe se verifica igualmente contra un hash ficticio válido, para que el tiempo de respuesta tampoco delate qué cuentas existen.
- `session_regenerate_id(true)` al iniciar sesión (contra la fijación de sesión) y renovación del token CSRF.
- Caducidad por inactividad de 2 horas. El cierre de sesión vacía la sesión, la destruye y caduca la cookie.
- El registro público solo crea anfitriones. Los administradores solo se crean por consola (`bin/crear-admin.php`), nunca desde la web.

**Autorización**
- Todas las rutas del panel y de la API privada pasan por `App::authenticated()`: sin sesión → 401 (API) o redirección al login (web); con rol insuficiente → 403.
- Ámbito por anfitrión aplicado en los servicios: las reservas y alojamientos de otro responden 404.
- El anfitrión de un alojamiento es siempre el usuario autenticado: `anfitrion_id` no se acepta del formulario.
- El huésped no recibe ids internos ni historial, y no hay URL pública que muestre una reserva: el resumen tras reservar viaja en un *flash* de sesión de un solo uso.

**Rate limiting** (`RateLimiter`, respuesta 429 con `Retry-After`)

| Cubo | Límite |
|---|---|
| Inicio de sesión por IP | 10 cada 15 min |
| Inicio de sesión por email | 5 cada 15 min (se reinicia al entrar correctamente) |
| Registro por IP | 5 por hora |
| Reservas públicas por IP | 10 por hora |
| Escrituras de un usuario del panel | 60 por minuto |

La IP es `REMOTE_ADDR`: no se confía en `X-Forwarded-For`, que cualquiera puede falsear si no hay un proxy de confianza delante (en la demo, Nginx es el borde).

**Base de datos**
- PDO con `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false` (sentencias preparadas reales), `FETCH_ASSOC` y `charset=utf8mb4` en el DSN.
- Sentencias preparadas en todas las consultas. Solo se concatenan fragmentos fijos de SQL, nunca valores.
- `LIKE` con `%` y `_` escapados (`ESCAPE '!'`, independiente del modo `NO_BACKSLASH_ESCAPES`).
- Parámetros de ruta restringidos por regex a enteros positivos (`[1-9][0-9]{0,9}`) y convertidos a `int`.
- Claves foráneas con `ON DELETE RESTRICT`: no se puede borrar un usuario con alojamientos ni un alojamiento con reservas.
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
- Token CSRF por sesión verificado con `hash_equals` en todas las escrituras (API y formularios), renovado al iniciar sesión.
- El destino tras el login (`next`) solo admite rutas `/panel…`: no hay redirección abierta.

**Errores**
- `ErrorHandler` central (`set_exception_handler`, `set_error_handler` que convierte warnings en excepciones, y `register_shutdown_function` para fatales).
- Las `HttpException` muestran su mensaje, que es seguro por diseño. Cualquier otra excepción (`PDOException` incluida) devuelve un 500 con mensaje genérico, y el detalle con su traza se registra en `var/log/app.log`.
- `APP_DEBUG=true` (solo en local) añade clase, mensaje y fichero a la respuesta 500.
- En la web, 404 y error propios que extienden `base.html.twig`.

**Configuración y despliegue**
- Credenciales en `.env`, ignorado por Git. Solo se versiona `.env.example`. Ninguna contraseña de administrador está en el código ni en `schema.sql`.
- Solo `public/` es accesible. `.htaccess` deniega ficheros ocultos y el listado de directorios.
- El `.htaccess` raíz para hostings bloquea `bin/`, `src/`, `templates/`, `vendor/`, `database/`, `tests/`, `var/`, `.env` y `composer.*`.

---

## 6. Uso de `|raw`

**No se usa.** Todas las variables pasan por el escapado automático de Twig. Los fragmentos de HTML reutilizables (etiqueta de estado, importe, error de campo, campos del alojamiento) son macros e *includes*, no cadenas con HTML.

---

## 7. Tests

```bash
composer test
```

92 tests unitarios (PHPUnit 10):
- **`ReservaServiceTest`**: con repositorios y transacción en memoria. Importe calculado por el servidor (`89.90 × 3 = 269.70`), `importe` del cliente rechazado, alojamiento inexistente o inactivo (404), solapamientos (409; una reserva cancelada libera las noches y el día de salida puede ser el de entrada de otra), ámbito por anfitrión (otro anfitrión recibe 404, el administrador lo ve todo), autor de cada evento, transiciones (409) y resumen acotado.
- **`AuthServiceTest`**: el registro siempre crea anfitriones con la contraseña cifrada y sin poder elegir rol, email duplicado, reglas de contraseña, mismo error para email inexistente y contraseña incorrecta, hash ficticio válido, bloqueo tras 5 fallos por email (aunque cambie la IP), reinicio del contador al entrar, rehash y alta o promoción de administradores.
- **`RateLimiterTest`**: hasta el límite, 429 con `Retry-After`, reinicio de la ventana, cubos independientes y claves con hash.
- **`ReservaValidatorTest`**: payload válido y normalizado, todos los obligatorios a la vez, email inválido, salida ≤ entrada, máximo de noches, fechas imposibles o mal formadas, entrada en el pasado, `importe` como campo del servidor, ids de alojamiento inválidos, campos desconocidos, longitudes, filtros (400) y límite máximo.
- **`ImporteTest`**: cálculo exacto en céntimos, 0 noches y totales que no caben en la columna.
- **`EstadoReservaTest`**: matriz completa de transiciones permitidas y prohibidas.
- **`RouterTest` / `RequestTest`**: ids no numéricos → 404, 405 con `Allow`, HEAD, y cuerpos rechazados (400 JSON roto o no objeto, 413, 415).

Además se probó contra MariaDB con curl: todas las rutas públicas y privadas, 401 sin sesión, 403 sin CSRF o con un token anterior al login, 404 entre anfitriones, 409 por solapamiento, 422 al enviar `importe`, 429 con `Retry-After` tras varios logins fallidos, redirección abierta bloqueada, `rol=admin` ignorado en el registro y `anfitrion_id` ignorado al crear alojamientos. También en navegador: reserva con y sin JS, login, panel acotado y alojamientos.

---

## 8. Despliegue

Hay dos formas de desplegar la aplicación. La demo pública usa la primera.

### 8.1 VPS con Ubuntu 24.04 (la demo)

Servidor IONOS VPS Linux S+ (1 vCore, 2 GB RAM, 60 GB NVMe), Ubuntu 24.04.5 LTS, datacenter en la UE. Acceso root por SSH con clave, sin contraseña.

**Pila:** Nginx con el DocumentRoot en `public/`, PHP 8.3.6-FPM (la aplicación requiere PHP ≥ 8.1), MariaDB 10.11, certificado HTTPS de Let's Encrypt renovado automáticamente con certbot. Cortafuegos UFW (solo SSH, HTTP y HTTPS), actualizaciones de seguridad automáticas y 1 GB de swap.

**Pasos resumidos:**

1. Crear la base de datos `reservas` (utf8mb4) y un usuario con privilegios solo sobre ella. Importar `database/schema.sql`.
2. Clonar el repositorio en `/var/www/reservas` y ejecutar `composer install --no-dev --optimize-autoloader`.
3. Crear `.env` a partir de `.env.example` con `APP_ENV=production`, `APP_DEBUG=false` y las credenciales de la base de datos. Dar permisos `640` al `.env`.
4. Dar escritura a `www-data` sobre `var/` y apuntar el `root` de Nginx a `public/`. Denegar el acceso a ficheros ocultos.
5. Obtener el certificado con `certbot --nginx` y redirigir HTTP a HTTPS.
6. Crear el administrador: `cd /var/www/reservas && php bin/crear-admin.php`.

Para actualizar: `git pull --ff-only` dentro de la carpeta y, si cambian las dependencias, `composer install --no-dev --optimize-autoloader`. Si cambian las plantillas, vaciar `var/cache/twig`. Si cambia el esquema, reimportar `database/schema.sql` (la demo solo tiene datos de ejemplo) y volver a crear el administrador.

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
7. Sin consola en el servidor, crea el administrador ejecutando `php bin/crear-admin.php` **en local** con el `.env` apuntando a la base de datos remota (si el hosting permite conexiones externas), o regístrate como anfitrión y cambia el rol desde phpMyAdmin: `UPDATE usuario SET rol = 'admin' WHERE email = 'tu@email'`.
8. Verifica que `https://tu-dominio/.env`, `https://tu-dominio/src/Core/App.php` y `https://tu-dominio/bin/crear-admin.php` devuelven **403**.

Si el hosting **sí** permite apuntar el DocumentRoot a `public/`, es preferible: entonces el `.htaccess` raíz no interviene.

---

## 9. Qué haría diferente con más tiempo

- **Notificaciones por email**: al huésped con el resumen y un enlace firmado para consultar o cancelar su reserva, y al anfitrión cuando llega una nueva. Ahora el huésped no puede consultar su reserva después.
- **Gestión de cuentas**: recuperación de contraseña, cambio de contraseña y de email, y que el administrador pueda desactivar anfitriones.
- **Calendario de disponibilidad** en la ficha del alojamiento (ahora las fechas ocupadas se descubren con el 409) y precios por temporada.
- **Paginación** real con `offset`/cursor (ahora hay `limit` ≤ 100 y `meta.total`) y ordenación configurable.
- **Tests de integración** de repositorios contra una base de datos efímera y **tests end-to-end** de la API y del front (Playwright).
- **Logging estructurado** con Monolog (con id de petición) y métricas, incluido un registro de inicios de sesión.
- **Docker Compose** (PHP-FPM + Nginx + MySQL) para que el entorno sea reproducible, y **CI** con PHPStan/Psalm, PHP-CS-Fixer y los tests en cada push.
- **Migraciones** versionadas en lugar de un único `schema.sql`.
- Contenedor de dependencias y router con middlewares (CSRF, sesión, rate limiting, cabeceras), en lugar de los envoltorios de `App`.
- Internacionalización de mensajes y documentación OpenAPI de la API.

---

## 10. Escalabilidad: 1000 reservas/hora

Siendo honestos, 1000 reservas/hora son **unas 0,3 escrituras por segundo**: una carga baja para un solo MySQL bien indexado. Incluso con picos de 10× y varias lecturas por cada alta, un servidor modesto lo absorbe. Aun así, esto es lo que cuidaría para crecer sin sorpresas:

- **Índices.** Ya hay índices en `estado`, `(fecha_entrada, fecha_salida)`, `fecha_salida`, `nombre_huesped`, `(alojamiento_id, fecha_entrada, fecha_salida)` (que sirve para la comprobación de solapamiento), `anfitrion_id` en alojamientos y `(reserva_id, fecha_creacion)` en eventos. Revisaría los planes con `EXPLAIN` sobre datos reales. Para la búsqueda `LIKE '%texto%'`, que no usa índice, a gran escala pasaría a un índice `FULLTEXT` o a un motor de búsqueda.
- **Conexiones.** Una conexión PDO por petición, que solo se abre si la ruta la necesita. Con PHP-FPM, dimensionaría `pm.max_children` frente a `max_connections` de MySQL y, si hiciera falta, metería un proxy de conexiones (ProxySQL).
- **Lecturas.** El catálogo y el listado son lo más consultado. Los serviría desde **réplicas de lectura** y/o con una **caché** corta (Redis/APCu) invalidada al escribir. Las escrituras y los `SELECT … FOR UPDATE` siempre van al primario.
- **Efectos secundarios fuera de la petición.** Emails de confirmación, avisos o integraciones irían a una **cola** (Redis/RabbitMQ/SQS) con workers y reintentos, para que la petición no dependa de un SMTP lento. El evento se registraría en la misma transacción (patrón *outbox*) para no perder ninguno.
- **Idempotencia.** Una cabecera `Idempotency-Key` en el `POST` (guardada con restricción `UNIQUE`) evita reservas duplicadas por reintentos o dobles clics en redes inestables.
- **Concurrencia y overbooking.** Ya está resuelto bloqueando la fila del alojamiento durante el alta, lo que serializa las reservas de un mismo alojamiento (no las de alojamientos distintos). Con mucha contención en alojamientos muy demandados pasaría a una tabla de noches con `UNIQUE (alojamiento_id, noche)`, que deja la garantía en manos de la base de datos sin bloqueos largos.
- **Rate limiting.** Los contadores viven en MySQL: una escritura extra por petición limitada. A gran escala los movería a Redis (`INCR` + `EXPIRE`) y limpiaría periódicamente las filas caducadas.
- **Escalado horizontal.** Varias instancias de PHP tras un balanceador. Para que sean intercambiables, las **sesiones** (y con ellas el usuario y el token CSRF) se guardarían en Redis o en la base de datos en vez de en ficheros locales. Detrás de un balanceador habría que leer la IP real de su cabecera, confiando solo en la del proxy propio.
- **Observabilidad.** Logs estructurados centralizados, métricas (latencia p95, tasa de 5xx y de 429, tiempo de consultas, conexiones), *slow query log* y alertas. Sin medir no se sabe cuándo escalar.
- **Datos.** Copias de seguridad automáticas con restauración probada y, a largo plazo, archivado de reservas antiguas y de su historial.
