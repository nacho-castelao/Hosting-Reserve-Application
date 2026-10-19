// Tema claro/oscuro. Script clásico cargado en <head> (no módulo) para aplicar
// el tema guardado antes de pintar; sin elección guardada se sigue al sistema.
(() => {
    const KEY = 'theme';
    const root = document.documentElement;
    const media = window.matchMedia('(prefers-color-scheme: dark)');

    const stored = () => {
        try {
            const value = localStorage.getItem(KEY);
            return value === 'light' || value === 'dark' ? value : null;
        } catch {
            return null; // almacenamiento bloqueado (modo privado, políticas…)
        }
    };

    const apply = (theme) => {
        if (theme) root.dataset.theme = theme;
        else delete root.dataset.theme;
        // Tema efectivo: lo usa el CSS para elegir el icono del botón.
        root.dataset.themeResolved = theme ?? (media.matches ? 'dark' : 'light');
    };

    apply(stored());
    media.addEventListener('change', () => apply(stored()));

    document.addEventListener('DOMContentLoaded', () => {
        const button = document.getElementById('theme-toggle');
        if (!button) return;

        const label = () => {
            button.setAttribute(
                'aria-label',
                root.dataset.themeResolved === 'dark' ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro',
            );
        };

        button.hidden = false;
        label();
        button.addEventListener('click', () => {
            const next = root.dataset.themeResolved === 'dark' ? 'light' : 'dark';
            try {
                localStorage.setItem(KEY, next);
            } catch {
                // Sin almacenamiento el cambio dura hasta recargar.
            }
            apply(next);
            label();
        });
    });
})();
