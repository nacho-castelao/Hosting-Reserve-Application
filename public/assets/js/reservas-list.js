// Listado: filtrado en vivo vía API, URL sincronizada y cancelación por fila.

import { api, BASE_URL } from './api.js';
import { badge, cancelReserva, formatDate, formatMoney, showStatus } from './ui.js';

const form = document.getElementById('filtros');
const tbody = document.getElementById('reservas-body');
const counter = document.getElementById('results-count');
const clearLink = document.getElementById('filtros-limpiar');

const DEBOUNCE_MS = 300;
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
let debounceTimer;
let controller;

// La cancelación necesita JS: los botones se muestran solo si este módulo carga.
document.querySelectorAll('.js-cancel').forEach((button) => { button.hidden = false; });

function el(tag, { className, text, attrs } = {}) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    Object.entries(attrs ?? {}).forEach(([name, value]) => node.setAttribute(name, value));
    return node;
}

function cell(label, ...children) {
    const td = el('td', { attrs: { 'data-label': label } });
    td.append(...children);
    return td;
}

function timeEl(iso) {
    return el('time', { text: formatDate(iso), attrs: { datetime: iso } });
}

/** Misma estructura que templates/partials/_reserva_row.html.twig. */
function renderRow(reserva) {
    const tr = el('tr', { attrs: { 'data-id': String(reserva.id) } });

    const link = el('a', { text: reserva.nombre_huesped, attrs: { href: `${BASE_URL}/reservas/${reserva.id}` } });
    const email = el('span', { className: 'muted', text: reserva.email_huesped });

    const estadoCell = cell('Estado', badge(reserva.estado));
    estadoCell.dataset.cell = 'estado';

    const importeCell = cell('Importe', formatMoney(reserva.importe));
    importeCell.classList.add('num');

    const actions = cell('Acciones');
    actions.classList.add('actions');
    if (reserva.estado !== 'cancelada') {
        const button = el('button', {
            className: 'btn btn--danger btn--small js-cancel',
            text: 'Cancelar',
            attrs: { type: 'button', 'data-id': String(reserva.id) },
        });
        actions.append(button);
    }

    tr.append(
        cell('Huésped', link, email),
        cell('Alojamiento', reserva.nombre_alojamiento),
        cell('Entrada', timeEl(reserva.fecha_entrada)),
        cell('Salida', timeEl(reserva.fecha_salida)),
        estadoCell,
        importeCell,
        actions,
    );
    return tr;
}

function renderRows(reservas) {
    if (reservas.length === 0) {
        const tr = el('tr', { className: 'empty-row' });
        tr.append(el('td', { text: 'No hay reservas que coincidan con los filtros.', attrs: { colspan: '7' } }));
        tbody.replaceChildren(tr);
        return;
    }
    tbody.replaceChildren(...reservas.map(renderRow));
}

function renderCount({ total, count }) {
    const noun = total === 1 ? 'reserva' : 'reservas';
    counter.textContent = `${total} ${noun}${total > count ? ` (mostrando ${count})` : ''}`;
}

/** Parámetros no vacíos del formulario de filtros. */
function currentParams() {
    const params = new URLSearchParams();
    new FormData(form).forEach((value, key) => {
        const trimmed = String(value).trim();
        if (trimmed !== '') params.set(key, trimmed);
    });
    return params;
}

/** Validación previa (el servidor valida igualmente y respondería 400). */
function validateFilters(params) {
    const desde = params.get('desde');
    const hasta = params.get('hasta');
    if ((desde && !DATE_RE.test(desde)) || (hasta && !DATE_RE.test(hasta))) {
        return 'Las fechas deben tener formato AAAA-MM-DD.';
    }
    if (desde && hasta && hasta < desde) {
        return 'La fecha "hasta" no puede ser anterior a "desde".';
    }
    return null;
}

async function load() {
    const params = currentParams();
    const problem = validateFilters(params);
    if (problem) {
        showStatus(problem, 'error');
        return;
    }

    // Cancela la petición anterior para que una respuesta lenta no pise a una más nueva.
    controller?.abort();
    controller = new AbortController();

    const query = params.toString();
    history.replaceState(null, '', `${BASE_URL}/${query ? `?${query}` : ''}`);

    tbody.setAttribute('aria-busy', 'true');
    try {
        const { data, meta } = await api.listReservas(params, controller.signal);
        renderRows(data);
        renderCount(meta);
    } catch (error) {
        if (error.name === 'AbortError') return;
        showStatus(error.message, 'error');
    } finally {
        tbody.setAttribute('aria-busy', 'false');
    }
}

function scheduleLoad() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(load, DEBOUNCE_MS);
}

form.addEventListener('input', scheduleLoad);
form.addEventListener('change', scheduleLoad);
form.addEventListener('submit', (event) => {
    event.preventDefault();
    clearTimeout(debounceTimer);
    load();
});
clearLink.addEventListener('click', (event) => {
    event.preventDefault();
    form.reset();
    // reset() restaura los valores renderizados por el servidor; se vacían explícitamente.
    form.querySelectorAll('input, select').forEach((field) => { field.value = ''; });
    load();
});

// Delegación: un único listener para los botones de todas las filas (también las nuevas).
tbody.addEventListener('click', async (event) => {
    const button = event.target.closest('.js-cancel');
    if (!button) return;

    const reserva = await cancelReserva(button.dataset.id, button);
    if (!reserva) return;

    const row = button.closest('tr');
    row.querySelector('[data-cell="estado"]').replaceChildren(badge(reserva.estado));
    button.remove();
});

// El formulario de alta avisa al crear una reserva: se refresca el listado.
document.addEventListener('reserva:creada', () => load());
