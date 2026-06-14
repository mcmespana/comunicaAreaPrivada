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

    /* -------- Menú en una línea + overflow "Más" (priority navigation) -------- */
    function closeMore(wrap) {
        wrap.classList.remove('is-open');
        var btn = wrap.querySelector('.stic-nav-more');
        if (btn) { btn.setAttribute('aria-expanded', 'false'); }
    }
    function closeAllMore() {
        var open = document.querySelectorAll('.stic-nav-more-wrap.is-open');
        Array.prototype.forEach.call(open, closeMore);
    }
    function openMore(wrap) {
        var btn = wrap.querySelector('.stic-nav-more');
        var menu = wrap.querySelector('.stic-nav-more-menu');
        if (!btn || !menu) { return; }
        wrap.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        // Anclar el panel (position: fixed) bajo el botón, alineado a su derecha.
        var r = btn.getBoundingClientRect();
        menu.style.top = (r.bottom + 6) + 'px';
        menu.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
        menu.style.left = 'auto';
    }

    // Reparte los items: los que no caben en una línea se mueven al panel "Más".
    function layoutNav() {
        var lists = document.querySelectorAll('.stic-nav-list');
        Array.prototype.forEach.call(lists, function (list) {
            var wrap = list.querySelector('.stic-nav-more-wrap');
            var menuUl = wrap ? wrap.querySelector('.stic-nav-more-menu ul') : null;
            if (!wrap || !menuUl) { return; }

            // 1) Restaurar todos los items a la fila (en orden), cerrar y ocultar "Más".
            while (menuUl.firstElementChild) {
                list.insertBefore(menuUl.firstElementChild, wrap);
            }
            closeMore(wrap);
            wrap.hidden = true;

            // En móvil (drawer vertical) se muestran todos: nada que repartir.
            if (!window.matchMedia('(min-width: 768px)').matches) { return; }

            // 2) ¿Cabe todo en una línea?
            if (list.scrollWidth <= list.clientWidth + 1) { return; }

            // 3) Mostrar "Más" y mover los últimos items hasta que quepa.
            wrap.hidden = false;
            var guard = 0;
            while (list.scrollWidth > list.clientWidth + 1 && guard < 100) {
                guard++;
                var candidate = wrap.previousElementSibling;
                if (!candidate || !candidate.classList.contains('stic-nav-item') ||
                    candidate.classList.contains('stic-nav-logout-item') ||
                    candidate.classList.contains('stic-nav-more-wrap')) {
                    break;
                }
                menuUl.insertBefore(candidate, menuUl.firstElementChild);
            }
            if (!menuUl.firstElementChild) { wrap.hidden = true; }
        });
    }

    function bindNavMore() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.stic-nav-more') : null;
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                var wrap = btn.closest('.stic-nav-more-wrap');
                var isOpen = wrap.classList.contains('is-open');
                closeAllMore();
                if (!isOpen) { openMore(wrap); }
                return;
            }
            if (!(e.target.closest && e.target.closest('.stic-nav-more-menu'))) {
                closeAllMore();
            }
        });
        window.addEventListener('scroll', closeAllMore, true);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) { closeAllMore(); }
        });

        // Recalcular al redimensionar (con debounce).
        var t;
        window.addEventListener('resize', function () {
            clearTimeout(t);
            t = setTimeout(layoutNav, 120);
        });
    }

    ready(function () {
        bindLoadingForms();
        bindPasswordToggles();
        bindAuthToggle();
        bindNavToggle();
        bindNavMore();
        layoutNav();
        // Reajuste tras cargar las fuentes (cambian anchos de los textos).
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(layoutNav);
        }
        setTimeout(layoutNav, 250);
    });
})();
