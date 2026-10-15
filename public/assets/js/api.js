// Envoltorio de fetch para la API: cabeceras JSON + CSRF y errores normalizados.

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') ?? '';

export const BASE_URL = meta('base-url');
const CSRF_TOKEN = meta('csrf-token');

/**
 * Error de la API. `network` distingue "no hay conexión" de una respuesta 4xx/5xx.
 */
export class ApiError extends Error {
    constructor(message, { status = 0, fields = {}, network = false } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.fields = fields;
        this.network = network;
    }
}

async function request(path, { method = 'GET', body, signal } = {}) {
    const headers = { Accept: 'application/json' };
    const options = { method, headers, signal, credentials: 'same-origin' };

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-Token'] = CSRF_TOKEN;
        options.body = JSON.stringify(body);
    }

    let response;
    try {
        response = await fetch(BASE_URL + path, options);
    } catch (error) {
        if (error.name === 'AbortError') throw error; // petición sustituida por otra más reciente
        throw new ApiError('No se pudo conectar con el servidor. Comprueba tu conexión.', { network: true });
    }

    let payload = null;
    try {
        payload = await response.json();
    } catch {
        // Cuerpo vacío o no JSON (p. ej. un error del servidor web): se trata abajo.
    }

    if (!response.ok) {
        throw new ApiError(payload?.error?.message ?? `Error inesperado (${response.status}).`, {
            status: response.status,
            fields: payload?.error?.fields ?? {},
        });
    }

    return payload;
}

export const api = {
    listReservas: (params, signal) => request(`/api/reservas?${params.toString()}`, { signal }),
    getReserva: (id) => request(`/api/reservas/${encodeURIComponent(id)}`),
    createReserva: (data) => request('/api/reservas', { method: 'POST', body: data }),
    cambiarEstado: (id, estado) =>
        request(`/api/reservas/${encodeURIComponent(id)}/estado`, { method: 'PATCH', body: { estado } }),
};
