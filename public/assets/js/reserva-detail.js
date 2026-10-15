// Detalle: cancelación sin recargar, actualizando la etiqueta y el historial.

import { badge, cancelReserva, formatDateTime } from './ui.js';

const article = document.getElementById('reserva-detalle');
const timeline = document.getElementById('timeline');
const button = article?.querySelector('.js-cancel');

function renderTimeline(eventos) {
    const items = eventos.map((evento) => {
        const li = document.createElement('li');
        li.className = `timeline__item timeline__item--${evento.tipo}`;

        const time = document.createElement('time');
        time.dateTime = evento.fecha_creacion.replace(' ', 'T');
        time.textContent = formatDateTime(evento.fecha_creacion);

        const text = document.createElement('p');
        text.textContent = evento.descripcion;

        li.append(time, text);
        return li;
    });
    timeline.replaceChildren(...items);
}

if (button) {
    button.hidden = false;
    button.addEventListener('click', async () => {
        const reserva = await cancelReserva(button.dataset.id, button);
        if (!reserva) return;

        article.querySelector('[data-cell="estado"]').replaceChildren(badge(reserva.estado));
        renderTimeline(reserva.eventos);
        button.remove();
    });
}
