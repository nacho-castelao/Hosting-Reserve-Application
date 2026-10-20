// Listado: filtrado en vivo vía API, URL sincronizada, resumen y cambios de estado por tarjeta.

import { api, BASE_URL } from './api.js';
import {
    badge, changeEstado, el, estadoButtons, formatMoney, formatRange, icon, nights, nightsLabel, showStatus,
} from './ui.js';

const form = document.getElementById('filtros');
const list = document.getElementById('reservas-body');
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

const SKELETON_DELAY_MS = 200;
const SKELETON_COUNT = 3;

function line(iconName, text) {
    const p = el('p', { className: 'booking-card__line' });
    p.append(icon(iconName), el('span', { text }));
    return p;
}

/** Misma estructura que templates/partials/_reserva_card.html.twig. */
function renderCard(reserva) {
    const li = el('li', { className: 'booking-card', attrs: { 'data-id': String(reserva.id) } });

    const title = el('h3', { className: 'booking-card__guest' });
    title.append(el('a', {
        className: 'booking-card__link',
        text: reserva.nombre_huesped,
        attrs: { href: `${BASE_URL}/panel/reservas/${reserva.id}` },
    }));
    const estado = el('span', { attrs: { 'data-cell': 'estado' } });
    estado.append(badge(reserva.estado));
    const head = el('div', { className: 'booking-card__head' });
    head.append(title, estado);

    const n = nights(reserva.fecha_entrada, reserva.fecha_salida);
    const group = el('span', { className: 'actions__group', attrs: { 'data-cell': 'acciones' } });
    group.append(...estadoButtons(reserva, 'row'));
    const foot = el('div', { className: 'booking-card__foot' });
    foot.append(
        el('span', { className: 'booking-card__total-label', text: 'Total' }),
        group,
        el('span', { className: 'booking-card__amount', text: formatMoney(reserva.importe) }),
    );

    li.append(
        head,
        line('bed', reserva.nombre_alojamiento),
        line('calendar', `${formatRange(reserva.fecha_entrada, reserva.fecha_salida)} · ${nightsLabel(n)}`),
        foot,
    );
    return li;
}

/** Misma estructura que templates/partials/_empty_card.html.twig. */
function renderEmpty() {
    const box = el('div', { className: 'empty-state' });
    const iconBox = el('span', { className: 'empty-state__icon' });
    iconBox.append(icon('logo'));
    box.append(
        iconBox,
        el('p', { className: 'empty-state__title', text: 'No hay reservas que coincidan' }),
        el('p', { className: 'muted', text: 'Prueba con otro nombre, estado o rango de fechas.' }),
        el('a', { className: 'btn btn--secondary js-clear-filters', text: 'Limpiar filtros', attrs: { href: `${BASE_URL}/panel` } }),
    );
    const li = el('li', { className: 'booking-empty' });
    li.append(box);
    return li;
}

/** Skeleton/Booking Card: huecos con la forma de la tarjeta mientras llega la respuesta. */
function renderSkeletons() {
    const cards = Array.from({ length: SKELETON_COUNT }, () => {
        const li = el('li', { className: 'booking-card booking-card--skeleton', attrs: { 'aria-hidden': 'true' } });
        ['skeleton--title', 'skeleton--line', 'skeleton--line skeleton--short', 'skeleton--foot'].forEach((mod) => {
            li.append(el('span', { className: `skeleton ${mod}` }));
        });
        return li;
    });
    list.replaceChildren(...cards);
}

function renderCards(reservas) {
    list.replaceChildren(...(reservas.length === 0 ? [renderEmpty()] : reservas.map(renderCard)));
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
        showStatus(problem, 'error', 'Filtros no válidos');
        return;
    }

    // Cancela la petición anterior para que una respuesta lenta no pise a una más nueva.
    controller?.abort();
    controller = new AbortController();

    const query = params.toString();
    history.replaceState(null, '', `${BASE_URL}/panel${query ? `?${query}` : ''}`);

    list.setAttribute('aria-busy', 'true');
    // Solo si la respuesta tarda: así una búsqueda rápida no parpadea.
    const skeletonTimer = setTimeout(renderSkeletons, SKELETON_DELAY_MS);
    try {
        const { data, meta } = await api.listReservas(params, controller.signal);
        renderCards(data);
        renderCount(meta);
    } catch (error) {
        if (error.name === 'AbortError') return;
        showStatus(error.message, 'error', 'No se pudo cargar el listado');
    } finally {
        clearTimeout(skeletonTimer);
        list.setAttribute('aria-busy', 'false');
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

// Delegación: un único listener para los botones de todas las tarjetas (también las nuevas).
list.addEventListener('click', async (event) => {
    if (event.target.closest('.js-clear-filters')) {
        clearFilters(event);
        return;
    }

    const button = event.target.closest('.js-estado');
    if (!button) return;

    const reserva = await changeEstado(button.dataset.id, button.dataset.estado, button);
    if (!reserva) return;

    const card = button.closest('.booking-card');
    const newBadge = badge(reserva.estado);
    newBadge.classList.add('is-new');
    card.querySelector('[data-cell="estado"]').replaceChildren(newBadge);
    card.querySelector('[data-cell="acciones"]').replaceChildren(...estadoButtons(reserva, 'row'));
    // El botón pulsado ya no existe: el foco pasa a la siguiente acción de la tarjeta.
    card.querySelector('.js-estado, .booking-card__link')?.focus();
});

// Al cambiar el estado de una reserva se refresca el resumen.
document.addEventListener('reserva:actualizada', refreshStats);
