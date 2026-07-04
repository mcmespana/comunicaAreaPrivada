/* ============================================================
   SinergiaCRM Private Area — UI helpers (stic-ui.js)
   ------------------------------------------------------------
   Mejoras de experiencia sin dependencias:
     · Overlay de carga al enviar formularios (login / enlace mágico)
     · Mostrar / ocultar contraseña
     · Tooltips de ayuda ⓘ en formularios (.stic-info)
     · Campos condicionales ([data-visible-when="campo:v1|v2"])
     · Selector rápido de participante (familias)
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

            // Si la sección ACTIVA ha quedado escondida dentro de "Más", lo marcamos
            // con un puntito para que el usuario sepa dónde está.
            var activeHidden = !!menuUl.querySelector('.current-menu-item');
            wrap.classList.toggle('stic-nav-more--active', activeHidden);
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

    /* -------- Tooltips de ayuda ⓘ (.stic-info) --------
       Hover/focus los muestra por CSS; el click (móvil) los fija con .is-open.
       Solo un tooltip fijado a la vez; Escape o tocar fuera lo cierra. */
    function closeAllInfoTips(except) {
        var open = document.querySelectorAll('.stic-info.is-open');
        Array.prototype.forEach.call(open, function (tip) {
            if (tip !== except) { tip.classList.remove('is-open'); }
        });
    }

    function bindInfoTips() {
        document.addEventListener('click', function (e) {
            var info = e.target.closest ? e.target.closest('.stic-info') : null;
            if (info) {
                // Dentro de un <label>, el click reenviaría el foco al input: no.
                e.preventDefault();
                e.stopPropagation();
                var willOpen = !info.classList.contains('is-open');
                closeAllInfoTips(info);
                info.classList.toggle('is-open', willOpen);
                return;
            }
            closeAllInfoTips(null);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) { closeAllInfoTips(null); }
            // Enter/espacio con el foco en el icono = alternar (accesible por teclado).
            if ((e.key === 'Enter' || e.key === ' ') && e.target.classList &&
                e.target.classList.contains('stic-info')) {
                e.preventDefault();
                e.target.classList.toggle('is-open');
            }
        });
    }

    /* -------- Campos condicionales --------
       <li> (o el propio input) con data-visible-when="campo:valor1|valor2"
       solo se muestra cuando el <select>/<input> #campo vale valor1 o valor2.
       El motor de formularios pone el atributo en el input: subimos al <li>. */
    function bindConditionalFields() {
        var nodes = document.querySelectorAll('[data-visible-when]');
        if (!nodes.length) { return; }
        var watched = {};

        Array.prototype.forEach.call(nodes, function (node) {
            var spec = node.getAttribute('data-visible-when') || '';
            var parts = spec.split(':');
            if (parts.length !== 2) { return; }
            var fieldId = parts[0].trim();
            var values = parts[1].split('|').map(function (v) { return v.trim(); });
            var target = node.closest && node.closest('li') ? node.closest('li') : node;

            if (!watched[fieldId]) { watched[fieldId] = []; }
            watched[fieldId].push({ target: target, values: values });
        });

        Object.keys(watched).forEach(function (fieldId) {
            var source = document.getElementById(fieldId);
            if (!source) { return; }
            var apply = function () {
                watched[fieldId].forEach(function (rule) {
                    var visible = rule.values.indexOf(source.value) !== -1;
                    rule.target.style.display = visible ? '' : 'none';
                    // Un campo oculto no debe bloquear el submit por required.
                    var inputs = rule.target.querySelectorAll('input, select, textarea');
                    Array.prototype.forEach.call(inputs, function (input) {
                        if (visible) {
                            if (input.hasAttribute('data-was-required')) {
                                input.setAttribute('required', '');
                                input.removeAttribute('data-was-required');
                            }
                        } else if (input.hasAttribute('required')) {
                            input.setAttribute('data-was-required', '1');
                            input.removeAttribute('required');
                        }
                    });
                });
            };
            source.addEventListener('change', apply);
            apply();
        });
    }

    /* -------- Selector rápido de participante (familias) --------
       Desplegable en la barra de navegación (.stic-part-switch). El cambio
       real lo hace el enlace (admin-post): aquí solo abrimos/cerramos. */
    function closePartSwitch() {
        var open = document.querySelectorAll('.stic-part-switch.is-open');
        Array.prototype.forEach.call(open, function (el) {
            el.classList.remove('is-open');
            var btn = el.querySelector('.stic-part-switch-btn');
            if (btn) { btn.setAttribute('aria-expanded', 'false'); }
        });
    }

    function bindPartSwitch() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.stic-part-switch-btn') : null;
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                var wrap = btn.closest('.stic-part-switch');
                var willOpen = !wrap.classList.contains('is-open');
                closePartSwitch();
                wrap.classList.toggle('is-open', willOpen);
                btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
                if (willOpen) {
                    // Anclar el panel (position: fixed) bajo el botón: la barra
                    // tiene overflow hidden y un menú absolute quedaría cortado.
                    var menu = wrap.querySelector('.stic-part-switch-menu');
                    if (menu) {
                        var r = btn.getBoundingClientRect();
                        menu.style.top = (r.bottom + 8) + 'px';
                        menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 256)) + 'px';
                    }
                }
                return;
            }
            if (!(e.target.closest && e.target.closest('.stic-part-switch'))) {
                closePartSwitch();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' || e.keyCode === 27) { closePartSwitch(); }
        });
        // El panel va con position:fixed: al hacer scroll se cierra (como "Más").
        window.addEventListener('scroll', closePartSwitch, true);
        // Al elegir participante, overlay de carga (la redirección tarda ~2s).
        document.addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('[data-part-switch-to]') : null;
            if (link) {
                showOverlay(link.getAttribute('data-part-switch-to') || 'Cambiando…', '');
            }
        });
    }

    ready(function () {
        bindLoadingForms();
        bindPasswordToggles();
        bindAuthToggle();
        bindNavToggle();
        bindNavMore();
        bindInfoTips();
        bindConditionalFields();
        bindPartSwitch();
        layoutNav();
        // Reajuste tras cargar las fuentes (cambian anchos de los textos).
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(layoutNav);
        }
        setTimeout(layoutNav, 250);
    });
})();
