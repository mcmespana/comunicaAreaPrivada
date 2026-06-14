/* ============================================================
   SinergiaCRM Private Area — UI helpers (stic-ui.js)
   ------------------------------------------------------------
   Mejoras de experiencia sin dependencias:
     · Overlay de carga al enviar formularios (login / enlace mágico)
     · Mostrar / ocultar contraseña
   Progressive enhancement: si JS falla, todo sigue funcionando.
   ============================================================ */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    /* -------- Overlay de carga reutilizable -------- */
    var overlay = null;

    function buildOverlay() {
        if (overlay) { return overlay; }
        overlay = document.createElement('div');
        overlay.className = 'stic-loading-overlay';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML =
            '<div class="stic-spinner"></div>' +
            '<div class="stic-loading-text"></div>' +
            '<div class="stic-loading-sub"></div>';
        document.body.appendChild(overlay);
        return overlay;
    }

    function showOverlay(text, sub) {
        var el = buildOverlay();
        el.querySelector('.stic-loading-text').textContent = text || 'Cargando…';
        el.querySelector('.stic-loading-sub').textContent = sub || '';
        // Forzar reflow para que la transición de opacidad se dispare.
        void el.offsetWidth;
        el.classList.add('is-active');
    }

    /* -------- Formularios que muestran carga al enviar -------- */
    function bindLoadingForms() {
        var forms = document.querySelectorAll('form.stic-loading-form');
        Array.prototype.forEach.call(forms, function (form) {
            form.addEventListener('submit', function () {
                // Solo si el formulario es válido (no atrapar errores de required).
                if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
                    return;
                }
                showOverlay(
                    form.getAttribute('data-loading-text') || 'Procesando…',
                    form.getAttribute('data-loading-sub') || ''
                );
            });
        });
    }

    /* -------- Mostrar / ocultar contraseña -------- */
    var EYE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
    var EYE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.9 4.24A10.6 10.6 0 0 1 12 4c6.5 0 10 7 10 7a16.6 16.6 0 0 1-3 3.7M6.2 6.2A16.4 16.4 0 0 0 2 11s3.5 7 10 7a10.5 10.5 0 0 0 4.3-.9"/><path d="M3 3l18 18"/></svg>';

    function bindPasswordToggles() {
        var toggles = document.querySelectorAll('[data-pass-toggle]');
        Array.prototype.forEach.call(toggles, function (btn) {
            btn.addEventListener('click', function () {
                var input = document.getElementById(btn.getAttribute('data-pass-toggle'));
                if (!input) { return; }
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.innerHTML = show ? EYE_OFF : EYE;
                btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
                input.focus();
            });
        });
    }

    /* -------- Conmutador login: enlace mágico <-> contraseña -------- */
    function bindAuthToggle() {
        var links = document.querySelectorAll('[data-auth-toggle]');
        Array.prototype.forEach.call(links, function (link) {
            link.addEventListener('click', function (e) {
                var container = link.closest ? link.closest('.stic-auth') : null;
                if (!container) { return; } // sin JS-closest: dejamos que el enlace navegue
                e.preventDefault();
                var mode = link.getAttribute('data-auth-toggle') === 'password' ? 'password' : 'magic';
                container.setAttribute('data-mode', mode);
                var view = container.querySelector(mode === 'password' ? '.stic-auth-login' : '.stic-auth-magic');
                if (view) {
                    var first = view.querySelector('input:not([type=hidden])');
                    if (first) {
                        // Al pasar a contraseña, el campo es "usuario": empieza limpio.
                        if (mode === 'password' && first.id === 'stic-username') { first.value = ''; }
                        try { first.focus({ preventScroll: false }); } catch (err) { first.focus(); }
                    }
                }
            });
        });
    }

    /* -------- Menú principal: hamburguesa colapsable (móvil) -------- */
    function closeAllNavs(except) {
        var open = document.querySelectorAll('.stic-nav.is-open');
        Array.prototype.forEach.call(open, function (nav) {
            if (nav === except) { return; }
            nav.classList.remove('is-open');
            var btn = nav.querySelector('.stic-nav-toggle');
            if (btn) { btn.setAttribute('aria-expanded', 'false'); }
        });
    }

    function bindNavToggle() {
        var toggles = document.querySelectorAll('.stic-nav-toggle');
        Array.prototype.forEach.call(toggles, function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var nav = btn.closest ? btn.closest('.stic-nav') : null;
                if (!nav) { return; }
                var willOpen = !nav.classList.contains('is-open');
                closeAllNavs(nav);
                nav.classList.toggle('is-open', willOpen);
                btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            });
        });

        // Cerrar al tocar fuera del menú.
        document.addEventListener('click', function (e) {
            if (e.target.closest && e.target.closest('.stic-nav')) { return; }
            closeAllNavs(null);
        });
        // Cerrar con la tecla Escape.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) { closeAllNavs(null); }
        });
    }

    ready(function () {
        bindLoadingForms();
        bindPasswordToggles();
        bindAuthToggle();
        bindNavToggle();
    });
})();
