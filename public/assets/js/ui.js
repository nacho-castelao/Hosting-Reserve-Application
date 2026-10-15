// Utilidades de interfaz compartidas. Nunca se usa innerHTML con datos: todo
// se construye con createElement/textContent, así que no hay riesgo de XSS.

import { api } from './api.js';

const statusBox = document.getElementById('status');
let statusTimer;

/**
 * Muestra un mensaje en la zona aria-live (lo leen los lectores de pantalla).
 * La región está siempre en el DOM; vacía se oculta por CSS (:empty).
 */
export function showStatus(message, type = 'success') {
    if (!statusBox) return;
    clearTimeout(statusTimer);
    statusBox.className = `status status--${type}`;
    statusBox.textContent = message;
    if (type === 'success') {
        statusTimer = setTimeout(() => { statusBox.textContent = ''; }, 6000);
    }
}

/** Estado de carga en un botón: deshabilitado, texto "Cargando…" y aria-busy. */
export function setBusy(button, busy, busyLabel = 'Cargando…') {
    if (busy) {
        button.dataset.label = button.textContent;
        button.textContent = busyLabel;
    } else if (button.dataset.label) {
        button.textContent = button.dataset.label;
    }
    button.disabled = busy;
    button.setAttribute('aria-busy', String(busy));
}

/** 'YYYY-MM-DD' → 'DD/MM/YYYY' (igual que |date('d/m/Y') en Twig). */
export function formatDate(isoDate) {
    const [y, m, d] = String(isoDate).slice(0, 10).split('-');
    return `${d}/${m}/${y}`;
}

/** 'YYYY-MM-DD HH:MM:SS' → 'DD/MM/YYYY HH:MM'. */
export function formatDateTime(value) {
    return `${formatDate(value)} ${String(value).slice(11, 16)}`;
}

/** '1190.5' → '1.190,50 €' (igual que number_format(2, ',', '.') en Twig). */
export function formatMoney(amount) {
    const [integer, decimals] = Number(amount).toFixed(2).split('.');
    return `${integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.')},${decimals} €`;
}

export function badge(estado) {
    const span = document.createElement('span');
    span.className = `badge badge--${estado}`;
    span.textContent = estado.charAt(0).toUpperCase() + estado.slice(1);
    return span;
}

/**
 * Pide confirmación y cancela la reserva vía API.
 * Devuelve la reserva actualizada, o null si el usuario no confirma o falla.
 */
export async function cancelReserva(id, button) {
    if (!window.confirm(`¿Seguro que quieres cancelar la reserva #${id}? Esta acción no se puede deshacer.`)) {
        return null;
    }

    setBusy(button, true, 'Cancelando…');
    try {
        const { data } = await api.cambiarEstado(id, 'cancelada');
        showStatus(`Reserva #${id} cancelada.`);
        return data;
    } catch (error) {
        showStatus(error.message, 'error');
        setBusy(button, false);
        return null;
    }
}
