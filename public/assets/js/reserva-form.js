// Alta de reservas: validación en cliente (espejo de ReservaValidator) y envío por la API.
// La validación del servidor sigue siendo la que manda; esto solo mejora la experiencia.

import { api } from './api.js';
import { setBusy, showStatus } from './ui.js';

const form = document.getElementById('reserva-form');
const details = document.getElementById('nueva-reserva');
const submitButton = form.querySelector('button[type="submit"]');

const FIELDS = ['nombre_huesped', 'email_huesped', 'nombre_alojamiento', 'fecha_entrada', 'fecha_salida', 'importe', 'notas'];
const MAX_LENGTHS = { nombre_huesped: 150, email_huesped: 254, nombre_alojamiento: 200, notas: 1000 };
const REQUIRED = 'Este campo es obligatorio.';
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const IMPORTE_RE = /^\d{1,8}(\.\d{1,2})?$/;
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

function readForm() {
    const data = {};
    FIELDS.forEach((name) => { data[name] = String(form.elements[name].value).trim(); });
    return data;
}

function validate(data) {
    const errors = {};

    ['nombre_huesped', 'email_huesped', 'nombre_alojamiento'].forEach((name) => {
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
    if (!errors.fecha_entrada && !errors.fecha_salida && data.fecha_salida <= data.fecha_entrada) {
        errors.fecha_salida = 'La fecha de salida debe ser posterior a la de entrada.';
    }

    if (data.importe === '') errors.importe = REQUIRED;
    else if (Number(data.importe) < 0) errors.importe = 'El importe no puede ser negativo.';
    else if (!IMPORTE_RE.test(data.importe)) {
        errors.importe = 'Debe tener como máximo 2 decimales y un valor inferior a 100.000.000.';
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
    first?.focus();
}

// Al cambiar la entrada, la salida no puede ser anterior (ayuda del selector nativo).
form.elements.fecha_entrada.addEventListener('change', (event) => {
    form.elements.fecha_salida.min = event.target.value;
});

form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearErrors();

    const data = readForm();
    const errors = validate(data);
    if (Object.keys(errors).length > 0) {
        showErrors(errors);
        showStatus('Revisa los campos marcados.', 'error');
        return;
    }

    // Solo se envían los campos del formulario; las notas vacías no se mandan.
    const payload = { ...data };
    if (payload.notas === '') delete payload.notas;

    setBusy(submitButton, true, 'Guardando…');
    form.setAttribute('aria-busy', 'true');
    try {
        const { data: reserva } = await api.createReserva(payload);
        form.reset();
        details.open = false;
        showStatus(`Reserva #${reserva.id} creada para ${reserva.nombre_huesped}.`);
        document.dispatchEvent(new CustomEvent('reserva:creada', { detail: reserva }));
    } catch (error) {
        if (error.status === 422) showErrors(error.fields);
        showStatus(error.message, 'error');
    } finally {
        setBusy(submitButton, false);
        form.setAttribute('aria-busy', 'false');
    }
});
