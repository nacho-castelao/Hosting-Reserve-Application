// Detalle: confirmación y cancelación sin recargar, actualizando la etiqueta, las acciones y el historial.

import { badge, changeEstado, el, estadoButtons, formatDateTime, icon } from './ui.js?v=2026-10-20';

const article = document.getElementById('reserva-detalle');
const timeline = document.getElementById('timeline');
const actions = article?.querySelector('[data-cell="acciones"]');

const TONO_ICONS = { creacion: 'plus', confirmada: 'check', cancelada: 'x' };

/** Color del punto según el estado al que lleva el evento (igual que la plantilla). */
function tono(evento) {
    if (evento.descripcion.endsWith('confirmada.')) return 'confirmada';
    if (evento.descripcion.endsWith('cancelada.')) return 'cancelada';
    return 'creacion';
}

/** Misma estructura que el historial de templates/reservas/show.html.twig. */
function renderTimeline(eventos) {
    const items = eventos.map((evento) => {
        const t = tono(evento);
        const li = el('li', { className: `timeline__item timeline__item--${t}` });

        const dot = el('span', { className: 'timeline__dot' });
        dot.append(icon(TONO_ICONS[t]));

        const body = el('div');
        body.append(
            el('p', { className: 'timeline__text', text: evento.descripcion }),
            el('time', { text: formatDateTime(evento.fecha_creacion), attrs: { datetime: evento.fecha_creacion.replace(' ', 'T') } }),
            el('span', { className: 'timeline__actor', text: evento.usuario ?? 'Huésped (web pública)' }),
        );

        li.append(dot, body);
        return li;
    });
    items.at(-1)?.classList.add('is-new');
    timeline.replaceChildren(...items);
}

function renderActions(reserva) {
    const buttons = estadoButtons(reserva, 'detail');
    actions.replaceChildren(...(buttons.length > 0
        ? buttons
        : [el('p', { className: 'summary__note', text: 'Reserva cancelada · sin más acciones' })]));
}

if (actions) {
    actions.querySelectorAll('.js-estado').forEach((button) => { button.hidden = false; });

    actions.addEventListener('click', async (event) => {
        const button = event.target.closest('.js-estado');
        if (!button) return;

        const reserva = await changeEstado(button.dataset.id, button.dataset.estado, button);
        if (!reserva) return;

        const newBadge = badge(reserva.estado);
        newBadge.classList.add('is-new');
        article.querySelector('[data-cell="estado"]').replaceChildren(newBadge);
        renderActions(reserva);
        renderTimeline(reserva.eventos);
        (actions.querySelector('.js-estado') ?? article.querySelector('h1'))?.focus?.();
    });
}
