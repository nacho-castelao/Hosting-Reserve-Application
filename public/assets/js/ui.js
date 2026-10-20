// Utilidades de interfaz compartidas. Nunca se usa innerHTML con datos: todo
// se construye con createElement/textContent, así que no hay riesgo de XSS.

import { api, BASE_URL } from './api.js';

const SVG_NS = 'http://www.w3.org/2000/svg';
const SPRITE = `${BASE_URL}/assets/img/icons.svg`;
const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const MAX_TOASTS = 3;

const statusBox = document.getElementById('status');

/** Crea un elemento con clase, texto y atributos opcionales. */
export function el(tag, { className, text, attrs } = {}) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    Object.entries(attrs ?? {}).forEach(([name, value]) => node.setAttribute(name, value));
    return node;
}

/** Icono del sprite (mismo marcado que la macro icon de Twig). */
export function icon(name, className = '') {
    const svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('class', `icon ${className}`.trim());
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const use = document.createElementNS(SVG_NS, 'use');
    use.setAttribute('href', `${SPRITE}#${name}`);
    svg.append(use);
    return svg;
}

function dismissToast(toast) {
    if (toast.classList.contains('is-leaving')) return;
    toast.classList.add('is-leaving');
    // animationend no llega con prefers-reduced-motion en algunos navegadores.
    const remove = () => toast.remove();
    toast.addEventListener('animationend', remove, { once: true });
    setTimeout(remove, 400);
}

const TOAST_TITLES = { success: 'Hecho', error: 'No se pudo completar' };

/**
 * Muestra una notificación en la zona aria-live (la leen los lectores de pantalla).
 * Las de éxito se cierran solas antes que las de error.
 */
export function showStatus(message, type = 'success', title = TOAST_TITLES[type]) {
    if (!statusBox) return;

    const toast = el('div', { className: `toast toast--${type}` });
    const close = el('button', { className: 'icon-btn icon-btn--small', attrs: { type: 'button', 'aria-label': 'Cerrar aviso' } });
    close.append(icon('x'));
    close.addEventListener('click', () => dismissToast(toast));
    const body = el('div', { className: 'toast__body' });
    body.append(
        el('p', { className: 'toast__title', text: title }),
        el('p', { className: 'toast__text', text: message }),
    );
    toast.append(icon(type === 'error' ? 'alert' : 'check-circle', 'toast__icon'), body, close);

    statusBox.append(toast);
    while (statusBox.children.length > MAX_TOASTS) statusBox.firstElementChild.remove();
    setTimeout(() => dismissToast(toast), type === 'error' ? 9000 : 5000);
}

const busyContent = new WeakMap();

/** Estado de carga en un botón: deshabilitado, spinner y aria-busy. Conserva su contenido. */
export function setBusy(button, busy, busyLabel = 'Cargando…') {
    if (busy) {
        busyContent.set(button, [...button.childNodes]);
        button.replaceChildren(el('span', { className: 'spinner', attrs: { 'aria-hidden': 'true' } }), busyLabel);
    } else if (busyContent.has(button)) {
        button.replaceChildren(...busyContent.get(button));
        busyContent.delete(button);
    }
    button.disabled = busy;
    button.setAttribute('aria-busy', String(busy));
}

/** 'YYYY-MM-DD' → '12 oct 2026' (igual que la macro fecha de Twig). */
export function formatDate(isoDate) {
    const [y, m, d] = String(isoDate).slice(0, 10).split('-').map(Number);
    return `${d} ${MESES[m - 1]} ${y}`;
}

/** 'YYYY-MM-DD HH:MM:SS' → '12 oct 2026 · 10:30'. */
export function formatDateTime(value) {
    return `${formatDate(value)} · ${String(value).slice(11, 16)}`;
}

/** Noches entre dos fechas 'YYYY-MM-DD' (la salida no cuenta). */
export function nights(entrada, salida) {
    const utc = (iso) => {
        const [y, m, d] = iso.split('-').map(Number);
        return Date.UTC(y, m - 1, d);
    };
    return Math.round((utc(salida) - utc(entrada)) / 86_400_000);
}

export function nightsLabel(n) {
    return `${n} ${n === 1 ? 'noche' : 'noches'}`;
}

/** '1190.5' → '1.190,50 €' (igual que number_format(2, ',', '.') en Twig). */
export function formatMoney(amount) {
    const [integer, decimals] = Number(amount).toFixed(2).split('.');
    return `${integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${decimals} €`;
}

const BADGE_ICONS = { pendiente: 'clock', confirmada: 'check', cancelada: 'x' };

/** Etiqueta de estado con icono (igual que la macro badge de Twig). */
export function badge(estado) {
    const span = el('span', { className: `badge badge--${estado}` });
    span.append(icon(BADGE_ICONS[estado] ?? 'clock'), estado.charAt(0).toUpperCase() + estado.slice(1));
    return span;
}

/** Iniciales sobre un color estable por id (igual que la macro avatar de Twig). */
export function avatar(nombre, id) {
    const initials = nombre.split(' ').filter(Boolean).slice(0, 2).map((p) => p.charAt(0).toUpperCase()).join('');
    return el('span', { className: `avatar avatar--${id % 6}`, text: initials, attrs: { 'aria-hidden': 'true' } });
}

/** Textos y estilos de cada cambio de estado que ofrece la interfaz. */
const ACCIONES = {
    confirmada: {
        icon: 'check',
        label: 'Confirmar',
        detailLabel: 'Confirmar reserva',
        className: { row: 'btn btn--success-soft btn--icon', detail: 'btn btn--primary' },
        busy: 'Confirmando…',
        title: (id) => `¿Confirmar la reserva #${id}?`,
        message: 'La reserva pasará a estado confirmada. Después solo podrá cancelarse.',
        confirmLabel: 'Confirmar reserva',
        cancelLabel: 'Volver',
        tone: 'success',
        doneTitle: 'Reserva confirmada',
        done: (id) => `La reserva #${id} ya está confirmada.`,
    },
    cancelada: {
        icon: 'x',
        label: 'Cancelar',
        detailLabel: 'Cancelar reserva',
        className: { row: 'btn btn--danger-soft btn--icon', detail: 'btn btn--secondary' },
        busy: 'Cancelando…',
        title: () => '¿Cancelar esta reserva?',
        message: (id) => `La reserva #${id} quedará cancelada. Esta acción no se puede deshacer.`,
        confirmLabel: 'Cancelar reserva',
        cancelLabel: 'Mantener reserva',
        tone: 'danger',
        doneTitle: 'Reserva cancelada',
        done: (id) => `La reserva #${id} se ha cancelado.`,
    },
};

/** Transiciones disponibles desde cada estado (espejo de EstadoReserva::allowedTransitions). */
const TRANSICIONES = { pendiente: ['confirmada', 'cancelada'], confirmada: ['cancelada'], cancelada: [] };

/**
 * Botones de cambio de estado para una reserva (mismo marcado que las plantillas).
 * @param {'row'|'detail'} variant
 */
export function estadoButtons(reserva, variant) {
    const estados = TRANSICIONES[reserva.estado];
    // En el detalle la acción principal (confirmar) va la última, como en el diseño.
    return (variant === 'detail' ? [...estados].reverse() : estados).map((estado) => {
        const accion = ACCIONES[estado];
        const button = el('button', {
            className: `${accion.className[variant]} js-estado`,
            attrs: { type: 'button', 'data-id': String(reserva.id), 'data-estado': estado },
        });
        if (variant === 'row') {
            // En la tabla, solo icono: el texto completo queda para lectores de pantalla.
            button.title = accion.label;
            button.append(icon(accion.icon), el('span', {
                className: 'visually-hidden',
                text: `${accion.label} reserva #${reserva.id}`,
            }));
        } else {
            // Cancelar es secundario en el detalle: sin icono, solo texto.
            if (estado !== 'cancelada') button.append(icon(accion.icon));
            button.append(el('span', { text: accion.detailLabel ?? accion.label }));
        }
        return button;
    });
}

/* ---------- Diálogo de confirmación ---------- */

const dialog = document.getElementById('confirm-dialog');

// Clic en el fondo (fuera del contenido) = cancelar.
dialog?.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close('cancel');
});

/**
 * Diálogo modal accesible en lugar de window.confirm. Resuelve true si se confirma.
 * @param {{title: string, message: string, confirmLabel: string, cancelLabel?: string, tone?: 'danger'|'success'}} options
 */
export function confirmDialog({ title, message, confirmLabel, cancelLabel = 'Volver', tone = 'danger' }) {
    if (!dialog || typeof dialog.showModal !== 'function') {
        return Promise.resolve(window.confirm(`${title}\n\n${message}`));
    }

    dialog.querySelector('#confirm-title').textContent = title;
    dialog.querySelector('#confirm-message').textContent = message;
    const iconBox = dialog.querySelector('[data-dialog-icon]');
    iconBox.className = `dialog__icon dialog__icon--${tone}`;
    iconBox.replaceChildren(icon(tone === 'danger' ? 'alert' : 'check-circle'));
    const confirmButton = dialog.querySelector('[data-dialog-confirm]');
    confirmButton.textContent = confirmLabel;
    dialog.querySelector('[value="cancel"]').textContent = cancelLabel;

    dialog.returnValue = '';
    dialog.showModal();
    // En acciones destructivas el foco empieza en "Volver".
    (tone === 'danger' ? dialog.querySelector('[value="cancel"]') : confirmButton).focus();

    return new Promise((resolve) => {
        dialog.addEventListener('close', () => resolve(dialog.returnValue === 'confirm'), { once: true });
    });
}

/**
 * Pide confirmación y cambia el estado vía API. Avisa con 'reserva:actualizada'.
 * Devuelve la reserva actualizada, o null si el usuario no confirma o falla.
 */
export async function changeEstado(id, estado, button) {
    const accion = ACCIONES[estado];
    const ok = await confirmDialog({
        title: accion.title(id),
        message: typeof accion.message === 'function' ? accion.message(id) : accion.message,
        confirmLabel: accion.confirmLabel,
        cancelLabel: accion.cancelLabel,
        tone: accion.tone,
    });
    if (!ok) return null;

    setBusy(button, true, accion.busy);
    try {
        const { data } = await api.cambiarEstado(id, estado);
        showStatus(accion.done(id), 'success', accion.doneTitle);
        document.dispatchEvent(new CustomEvent('reserva:actualizada', { detail: data }));
        return data;
    } catch (error) {
        showStatus(error.message, 'error', 'No se pudo cambiar el estado');
        setBusy(button, false);
        return null;
    }
}
