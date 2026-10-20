// Reserva del huésped en la ficha del alojamiento: validación en cliente (espejo de
// ReservaValidator), total estimado en vivo y envío por la API. El importe real lo
// calcula siempre el servidor; aquí solo se muestra como anticipo.

import { api, BASE_URL } from './api.js';
import { badge, el, formatDate, formatMoney, icon, nights, nightsLabel, setBusy, showStatus } from './ui.js';

const form = document.getElementById('reserva-form');
const card = document.getElementById('reserva-card');
const submitButton = form.querySelector('button[type="submit"]');
const staySummary = document.getElementById('estancia-resumen');
const formAlert = form.querySelector('[data-form-alert]');

const ALOJAMIENTO_ID = Number(form.dataset.alojamientoId);
const PRECIO_NOCHE = form.dataset.precioNoche;
const MAX_NOCHES = Number(form.dataset.maxNoches);

const FIELDS = ['nombre_huesped', 'email_huesped', 'fecha_entrada', 'fecha_salida', 'notas'];
const MAX_LENGTHS = { nombre_huesped: 150, email_huesped: 254, notas: 1000 };
const REQUIRED = 'Este campo es obligatorio.';
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const CONTROL_RE = /[\u0000-\u001F\u007F]/;

// Con JS activo se usan nuestros mensajes en lugar de los nativos del navegador.
form.noValidate = true;

/** Fecha estricta YYYY-MM-DD que además exista (rechaza 2026-02-30). */
function isValidDate(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
    const [y, m, d] = value.split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d));
    return date.getUTCFullYear() === y && date.getUTCMonth() === m - 1 && date.getUTCDate() === d;
}

function todayIso() {
    const now = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** Precio × noches en céntimos, como Importe::porNoches en el servidor (sin errores de coma flotante). */
function totalEstimado(n) {
    const [entero, decimales = ''] = PRECIO_NOCHE.split('.');
    const centimos = Number(entero) * 100 + Number(decimales.padEnd(2, '0'));
    return (centimos * n) / 100;
}

function readForm() {
    const data = {};
    FIELDS.forEach((name) => { data[name] = String(form.elements[name].value).trim(); });
    return data;
}

function validate(data) {
    const errors = {};

    ['nombre_huesped', 'email_huesped'].forEach((name) => {
        if (data[name] === '') errors[name] = REQUIRED;
        else if (CONTROL_RE.test(data[name])) errors[name] = 'Contiene caracteres no permitidos.';
        else if (data[name].length > MAX_LENGTHS[name]) errors[name] = `No puede superar los ${MAX_LENGTHS[name]} caracteres.`;
    });
    if (!errors.email_huesped && !EMAIL_RE.test(data.email_huesped)) {
        errors.email_huesped = 'Introduce un email válido.';
    }

    ['fecha_entrada', 'fecha_salida'].forEach((name) => {
        if (data[name] === '') errors[name] = REQUIRED;
        else if (!isValidDate(data[name])) errors[name] = 'Debe ser una fecha válida con formato AAAA-MM-DD.';
    });
    if (!errors.fecha_entrada && data.fecha_entrada < todayIso()) {
        errors.fecha_entrada = 'La fecha de entrada no puede ser anterior a hoy.';
    }
    if (!errors.fecha_entrada && !errors.fecha_salida) {
        if (data.fecha_salida <= data.fecha_entrada) {
            errors.fecha_salida = 'La fecha de salida debe ser posterior a la de entrada.';
        } else if (nights(data.fecha_entrada, data.fecha_salida) > MAX_NOCHES) {
            errors.fecha_salida = `La estancia no puede superar las ${MAX_NOCHES} noches.`;
        }
    }

    if (data.notas.length > MAX_LENGTHS.notas) {
        errors.notas = `No puede superar los ${MAX_LENGTHS.notas} caracteres.`;
    }

    return errors;
}

function clearErrors() {
    form.querySelectorAll('[data-error-for]').forEach((p) => {
        p.textContent = '';
        p.hidden = true;
    });
    form.querySelectorAll('[aria-invalid]').forEach((input) => input.removeAttribute('aria-invalid'));
    formAlert.hidden = true;
}

function showAlert(message) {
    formAlert.querySelector('[data-form-alert-text]').textContent = message;
    formAlert.hidden = false;
}

/** Pinta cada error junto a su campo y lleva el foco al primero. */
function showErrors(errors) {
    let first = null;
    Object.entries(errors).forEach(([name, message]) => {
        const target = form.querySelector(`[data-error-for="${CSS.escape(name)}"]`);
        const input = form.elements[name];
        if (!target || !input) return; // p. ej. un campo no permitido: se avisa en general
        target.textContent = message;
        target.hidden = false;
        input.setAttribute('aria-invalid', 'true');
        first ??= input;
    });
    showAlert('Revisa los campos marcados.');
    first?.focus();
}

/* ---------- Total estimado ---------- */

function updateStaySummary() {
    const { fecha_entrada: entrada, fecha_salida: salida } = form.elements;
    if (!isValidDate(entrada.value) || !isValidDate(salida.value) || salida.value <= entrada.value) {
        staySummary.hidden = true;
        return;
    }
    const n = nights(entrada.value, salida.value);
    staySummary.textContent = `${nightsLabel(n)} × ${formatMoney(PRECIO_NOCHE)} = ${formatMoney(totalEstimado(n))}`;
    staySummary.hidden = false;
}

// Al cambiar la entrada, la salida no puede ser anterior (ayuda del selector nativo).
form.elements.fecha_entrada.addEventListener('change', (event) => {
    form.elements.fecha_salida.min = event.target.value;
});
['fecha_entrada', 'fecha_salida'].forEach((name) => {
    form.elements[name].addEventListener('input', updateStaySummary);
});
updateStaySummary();

/* ---------- Confirmación ---------- */

function detail(iconName, label, value, className = '') {
    const item = el('div', { className: 'details__item' });
    const iconBox = el('span', { className: 'details__icon' });
    iconBox.append(icon(iconName));
    const body = el('div');
    body.append(el('dt', { text: label }), el('dd', { className, text: value }));
    item.append(iconBox, body);
    return item;
}

/** Misma estructura que templates/catalogo/recibida.html.twig. */
function renderRecibida(reserva) {
    const section = el('section', { className: 'card confirmation', attrs: { 'aria-labelledby': 'confirmacion-titulo' } });
    const iconBox = el('span', { className: 'error-page__icon confirmation__icon' });
    iconBox.append(icon('check-circle'));

    const n = nights(reserva.fecha_entrada, reserva.fecha_salida);
    const details = el('dl', { className: 'details confirmation__details' });
    details.append(
        detail('home', 'Alojamiento', reserva.nombre_alojamiento),
        detail('calendar', 'Estancia', `${formatDate(reserva.fecha_entrada)} → ${formatDate(reserva.fecha_salida)} (${nightsLabel(n)})`),
        detail('user', 'A nombre de', reserva.nombre_huesped),
        detail('euro', 'Importe total', formatMoney(reserva.importe), 'details__amount'),
    );

    const ref = el('p', { className: 'confirmation__ref muted', text: `Referencia #${reserva.id} · Estado: ` });
    ref.append(badge(reserva.estado));

    const back = el('a', { className: 'btn btn--secondary', attrs: { href: `${BASE_URL}/` } });
    back.append(icon('arrow-left'), ' Ver más alojamientos');
    const backP = el('p');
    backP.append(back);

    const title = el('h1', { text: '¡Solicitud enviada!', attrs: { id: 'confirmacion-titulo', tabindex: '-1' } });
    section.append(
        iconBox,
        title,
        el('p', { className: 'muted', text: 'El anfitrión revisará tu reserva y la confirmará. Guarda este resumen.' }),
        details,
        ref,
        backP,
    );

    // La ficha y el formulario dejan paso a la confirmación.
    card.closest('.booking').replaceWith(section);
    title.focus();
}

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearErrors();

    const data = readForm();
    const errors = validate(data);
    if (Object.keys(errors).length > 0) {
        showErrors(errors);
        return;
    }

    // Solo los campos del formulario más el alojamiento; las notas vacías no se mandan.
    const payload = { alojamiento_id: ALOJAMIENTO_ID, ...data };
    if (payload.notas === '') delete payload.notas;

    setBusy(submitButton, true, 'Enviando…');
    form.setAttribute('aria-busy', 'true');
    try {
        const { data: reserva } = await api.createReserva(payload);
        renderRecibida(reserva);
        showStatus(`Referencia #${reserva.id}. El anfitrión la revisará pronto.`, 'success', 'Solicitud enviada');
    } catch (error) {
        if (error.status === 422) showErrors(error.fields);
        else showAlert(error.message); // 404, 409 (fechas ocupadas), 429, red…
        showStatus(error.message, 'error', 'No se pudo enviar la reserva');
        setBusy(submitButton, false);
    } finally {
        form.setAttribute('aria-busy', 'false');
    }
});
