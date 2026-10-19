// Listado: filtrado en vivo vía API, URL sincronizada, resumen y cambios de estado por fila.

import { api, BASE_URL } from './api.js';
import {
    avatar, badge, changeEstado, el, estadoButtons, formatDate, formatMoney, icon, nights, nightsLabel, showStatus,
} from './ui.js';

const form = document.getElementById('filtros');
const tbody = document.getElementById('reservas-body');
const counter = document.getElementById('results-count');
const clearLink = document.getElementById('filtros-limpiar');
const stats = document.getElementById('stats');

const DEBOUNCE_MS = 300;
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
let debounceTimer;
let controller;

// Los cambios de estado necesitan JS: los botones se muestran solo si este módulo carga.
document.querySelectorAll('.js-estado').forEach((button) => { button.hidden = false; });
// Los filtros se aplican al escribir; el botón solo hace falta sin JS.
document.getElementById('filtros-aplicar').hidden = true;

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
    const href = `${BASE_URL}/reservas/${reserva.id}`;

    const guestText = el('span', { className: 'cell-guest__text' });
    guestText.append(
        el('a', { className: 'row-link', text: reserva.nombre_huesped, attrs: { href } }),
        el('span', { className: 'muted', text: reserva.email_huesped }),
    );
    const guestCell = cell('Huésped', avatar(reserva.nombre_huesped, reserva.id), guestText);
    guestCell.classList.add('cell-guest');

    const dates = el('span', { className: 'stay__dates' });
    dates.append(timeEl(reserva.fecha_entrada), icon('arrow-right', 'stay__arrow'), timeEl(reserva.fecha_salida));
    const stay = el('span', { className: 'stay' });
    stay.append(dates, el('span', { className: 'muted', text: nightsLabel(nights(reserva.fecha_entrada, reserva.fecha_salida)) }));

    const estadoCell = cell('Estado', badge(reserva.estado));
    estadoCell.dataset.cell = 'estado';

    const importeCell = cell('Importe', formatMoney(reserva.importe));
    importeCell.classList.add('num');

    const group = el('span', { className: 'actions__group', attrs: { 'data-cell': 'acciones' } });
    group.append(...estadoButtons(reserva, 'row'));
    const view = el('a', { className: 'icon-btn icon-btn--small', attrs: { href, 'aria-label': `Ver reserva #${reserva.id}` } });
    view.append(icon('chevron-right'));
    const actions = cell('Acciones', group, view);
    actions.classList.add('actions');

    tr.append(
        guestCell,
        cell('Alojamiento', reserva.nombre_alojamiento),
        cell('Estancia', stay),
        estadoCell,
        importeCell,
        actions,
    );
    return tr;
}

/** Misma estructura que templates/partials/_empty_row.html.twig. */
function renderEmpty() {
    const box = el('div', { className: 'empty-state' });
    const iconBox = el('span', { className: 'empty-state__icon' });
    iconBox.append(icon('inbox'));
    box.append(
        iconBox,
        el('p', { className: 'empty-state__title', text: 'No hay reservas que coincidan' }),
        el('p', { className: 'muted', text: 'Prueba con otros filtros o crea una reserva nueva.' }),
        el('a', { className: 'btn btn--secondary btn--small js-clear-filters', text: 'Limpiar filtros', attrs: { href: `${BASE_URL}/` } }),
    );
    const td = el('td', { attrs: { colspan: '6' } });
    td.append(box);
    const tr = el('tr', { className: 'empty-row' });
    tr.append(td);
    return tr;
}

function renderRows(reservas) {
    tbody.replaceChildren(...(reservas.length === 0 ? [renderEmpty()] : reservas.map(renderRow)));
}

function renderCount({ total, count }) {
    const noun = total === 1 ? 'reserva' : 'reservas';
    counter.textContent = `${total} ${noun}${total > count ? ` (mostrando ${count})` : ''}`;
}

/** Refresca las tarjetas del resumen tras crear o cambiar una reserva. */
async function refreshStats() {
    try {
        const { data } = await api.getResumen();
        stats.querySelectorAll('[data-stat]').forEach((node) => {
            const key = node.dataset.stat;
            const text = key === 'ingresos' ? formatMoney(data[key]) : String(data[key]);
            if (node.textContent.trim() === text) return;
            node.textContent = text;
            node.classList.add('is-updated');
            setTimeout(() => node.classList.remove('is-updated'), 1200);
        });
    } catch {
        // El resumen es secundario: si falla se queda el anterior sin molestar.
    }
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

function clearFilters(event) {
    event.preventDefault();
    // reset() restaura los valores renderizados por el servidor; se vacían explícitamente.
    form.querySelectorAll('input:not([type="radio"])').forEach((field) => { field.value = ''; });
    form.querySelector('input[name="estado"][value=""]').checked = true;
    clearTimeout(debounceTimer);
    load();
}

form.addEventListener('input', scheduleLoad);
form.addEventListener('change', scheduleLoad);
form.addEventListener('submit', (event) => {
    event.preventDefault();
    clearTimeout(debounceTimer);
    load();
});
clearLink.addEventListener('click', clearFilters);

// Delegación: un único listener para los botones de todas las filas (también las nuevas).
tbody.addEventListener('click', async (event) => {
    if (event.target.closest('.js-clear-filters')) {
        clearFilters(event);
        return;
    }

    const button = event.target.closest('.js-estado');
    if (!button) return;

    const reserva = await changeEstado(button.dataset.id, button.dataset.estado, button);
    if (!reserva) return;

    const row = button.closest('tr');
    const newBadge = badge(reserva.estado);
    newBadge.classList.add('is-new');
    row.querySelector('[data-cell="estado"]').replaceChildren(newBadge);
    row.querySelector('[data-cell="acciones"]').replaceChildren(...estadoButtons(reserva, 'row'));
    // El botón pulsado ya no existe: el foco pasa a la siguiente acción de la fila.
    row.querySelector('.js-estado, .actions .icon-btn')?.focus();
});

// Al crear o cambiar una reserva se refrescan el resumen y, si hace falta, el listado.
document.addEventListener('reserva:creada', () => {
    load();
    refreshStats();
});
document.addEventListener('reserva:actualizada', refreshStats);
