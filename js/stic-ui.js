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
            form.addEventListener('submit', function (e) {
                // Si otro handler canceló el envío (p. ej. onsubmit de validación
                // que devuelve false), el overlay no debe quedarse bloqueando.
                if (e.defaultPrevented) { return; }
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
                // Sincroniza el estado ARIA de las pestañas con la vista activa.
                var tabs = container.querySelectorAll('[data-auth-toggle]');
                Array.prototype.forEach.call(tabs, function (t) {
                    t.setAttribute('aria-selected', t.getAttribute('data-auth-toggle') === mode ? 'true' : 'false');
                });
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
    // Flag barato para que el listener de scroll no consulte el DOM si no hay
    // ningún panel abierto (el caso del 99% de los scrolls).
    var moreIsOpen = false;
    function closeMore(wrap) {
        wrap.classList.remove('is-open');
        var btn = wrap.querySelector('.stic-nav-more');
        if (btn) { btn.setAttribute('aria-expanded', 'false'); }
    }
    function closeAllMore() {
        if (!moreIsOpen) { return; }
        var open = document.querySelectorAll('.stic-nav-more-wrap.is-open');
        Array.prototype.forEach.call(open, closeMore);
        moreIsOpen = false;
    }
    function openMore(wrap) {
        var btn = wrap.querySelector('.stic-nav-more');
        var menu = wrap.querySelector('.stic-nav-more-menu');
        if (!btn || !menu) { return; }
        wrap.classList.add('is-open');
        btn.setAttribute('aria-expanded', 'true');
        moreIsOpen = true;
        // Anclar el panel (position: fixed) bajo el botón, alineado a su derecha.
        var r = btn.getBoundingClientRect();
        menu.style.top = (r.bottom + 6) + 'px';
        menu.style.right = Math.max(8, window.innerWidth - r.right) + 'px';
        menu.style.left = 'auto';
        // El foco entra en el panel: sin esto, un usuario de teclado tenía que
        // "tabular a ciegas" por el resto de la página hasta llegar al menú.
        var first = menu.querySelector('a, button');
        if (first) { first.focus(); }
    }

    // Reparte los items: los que no caben en una línea se mueven al panel "Más".
    // Lecturas y escrituras de layout van por LOTES: antes el bucle leía
    // scrollWidth después de cada insertBefore y forzaba un reflow por item
    // (layout thrashing); ahora se mide todo una vez y se mueve todo de golpe.
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

            // 2) MEDIR (una sola pasada): ancho disponible, ancho de cada item
            //    y ancho del botón "Más" (visible solo para medirlo).
            if (list.scrollWidth <= list.clientWidth + 1) { return; }
            wrap.hidden = false;
            var available = list.clientWidth;
            var moreWidth = wrap.offsetWidth;
            var items = [];
            var child = list.firstElementChild;
            while (child) {
                if (child !== wrap && child.classList.contains('stic-nav-item') &&
                    !child.classList.contains('stic-nav-logout-item')) {
                    items.push({ el: child, width: child.offsetWidth });
                }
                child = child.nextElementSibling;
            }
            var gap = parseFloat(getComputedStyle(list).columnGap || getComputedStyle(list).gap) || 0;

            // 3) CALCULAR cuántos items caben dejando sitio al botón "Más".
            var used = moreWidth;
            var fit = items.length;
            for (var i = 0; i < items.length; i++) {
                used += items[i].width + gap;
                if (used > available) { fit = i; break; }
            }

            // 4) ESCRIBIR: mover el resto al panel en un solo lote.
            for (var j = items.length - 1; j >= fit; j--) {
                menuUl.insertBefore(items[j].el, menuUl.firstElementChild);
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
        window.addEventListener('scroll', closeAllMore, { capture: true, passive: true });
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
       El tooltip se saca a <body> (PORTAL) la primera vez que se usa:
       los <li> del formulario pueden tener transform (animaciones) y un
       ancestro con transform rompe position:fixed (lo ancla al li, no al
       viewport — así salían descolocados). Desde body, el fixed es real y
       el clamping al viewport funciona siempre. Hover/focus lo muestran;
       el tap lo fija (is-sticky). Escape, scroll o tocar fuera lo cierran. */
    function infoTipFor(info) {
        if (info._sticTip) { return info._sticTip; }
        var tip = info.querySelector('.stic-info-tip');
        if (!tip) { return null; }
        document.body.appendChild(tip);   // portal
        tip._sticInfo = info;
        info._sticTip = tip;
        return tip;
    }

    var anyTipVisible = false; // early-return barato en el listener de scroll

    function showInfoTip(info, sticky) {
        var tip = infoTipFor(info);
        if (!tip) { return; }
        anyTipVisible = true;
        // Posicionar antes de mostrar (medible: display block, visibility hidden).
        var w = tip.offsetWidth;
        var h = tip.offsetHeight;
        var r = info.getBoundingClientRect();
        var left = Math.round(r.left + r.width / 2 - w / 2);
        left = Math.max(12, Math.min(left, window.innerWidth - w - 12));
        var top = Math.round(r.top - h - 9);              // preferencia: encima
        if (top < 12) { top = Math.round(r.bottom + 9); } // sin sitio: debajo
        tip.style.left = left + 'px';
        tip.style.top = top + 'px';
        tip.classList.add('is-visible');
        tip.classList.toggle('is-sticky', !!sticky);
        info.classList.toggle('is-open', !!sticky);
    }

    function hideInfoTip(tip, force) {
        if (!tip || (!force && tip.classList.contains('is-sticky'))) { return; }
        tip.classList.remove('is-visible', 'is-sticky');
        if (tip._sticInfo) { tip._sticInfo.classList.remove('is-open'); }
    }

    function closeAllInfoTips(exceptInfo) {
        if (!anyTipVisible) { return; }
        var tips = document.querySelectorAll('.stic-info-tip.is-visible');
        Array.prototype.forEach.call(tips, function (tip) {
            if (!exceptInfo || tip._sticInfo !== exceptInfo) { hideInfoTip(tip, true); }
        });
        if (!exceptInfo) { anyTipVisible = false; }
    }

    function bindInfoTips() {
        // Hover de escritorio (delegado).
        document.addEventListener('mouseover', function (e) {
            var info = e.target.closest ? e.target.closest('.stic-info') : null;
            if (info && !(info._sticTip && info._sticTip.classList.contains('is-visible'))) {
                showInfoTip(info, false);
            }
        });
        document.addEventListener('mouseout', function (e) {
            var info = e.target.closest ? e.target.closest('.stic-info') : null;
            if (info && !(e.relatedTarget && info.contains(e.relatedTarget))) {
                hideInfoTip(info._sticTip, false);
            }
        });
        // Foco por teclado.
        document.addEventListener('focusin', function (e) {
            if (e.target.classList && e.target.classList.contains('stic-info')) {
                showInfoTip(e.target, false);
            }
        });
        document.addEventListener('focusout', function (e) {
            if (e.target.classList && e.target.classList.contains('stic-info')) {
                hideInfoTip(e.target._sticTip, false);
            }
        });
        // Tap/click: fijar (móvil). Solo un tooltip fijado a la vez.
        document.addEventListener('click', function (e) {
            var info = e.target.closest ? e.target.closest('.stic-info') : null;
            if (info) {
                // Dentro de un <label>, el click reenviaría el foco al input: no.
                e.preventDefault();
                e.stopPropagation();
                var tip = infoTipFor(info);
                var isSticky = tip && tip.classList.contains('is-sticky');
                closeAllInfoTips(info);
                if (isSticky) { hideInfoTip(tip, true); }
                else { showInfoTip(info, true); }
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
                var tip = e.target._sticTip;
                if (tip && tip.classList.contains('is-sticky')) { hideInfoTip(tip, true); }
                else { showInfoTip(e.target, true); }
            }
        });
        // El tooltip es fixed: al hacer scroll se cierra (no queda flotando).
        window.addEventListener('scroll', function () { closeAllInfoTips(null); }, { capture: true, passive: true });
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
    var partSwitchOpen = false; // early-return barato en el listener de scroll
    function closePartSwitch() {
        if (!partSwitchOpen) { return; }
        var open = document.querySelectorAll('.stic-part-switch.is-open');
        Array.prototype.forEach.call(open, function (el) {
            el.classList.remove('is-open');
            var btn = el.querySelector('.stic-part-switch-btn');
            if (btn) { btn.setAttribute('aria-expanded', 'false'); }
        });
        partSwitchOpen = false;
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
                    partSwitchOpen = true;
                    // Anclar el panel (position: fixed) bajo el botón: la barra
                    // tiene overflow hidden y un menú absolute quedaría cortado.
                    var menu = wrap.querySelector('.stic-part-switch-menu');
                    if (menu) {
                        var r = btn.getBoundingClientRect();
                        menu.style.top = (r.bottom + 8) + 'px';
                        menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 256)) + 'px';
                        // Foco al primer perfil del panel (patrón menu-button).
                        var first = menu.querySelector('a');
                        if (first) { first.focus(); }
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
        window.addEventListener('scroll', closePartSwitch, { capture: true, passive: true });
        // Al elegir participante, overlay de carga (la redirección tarda ~2s).
        document.addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('[data-part-switch-to]') : null;
            if (link) {
                showOverlay(link.getAttribute('data-part-switch-to') || 'Cambiando…', '');
            }
        });
    }

    /* -------- Secciones de formulario colapsables (con memoria) --------
       Cada <h5> de sección pliega/despliega su tarjeta <ul>. El estado se
       guarda en localStorage por página+sección, así el usuario se encuentra
       el formulario como lo dejó. Sin JS todo queda abierto (enhancement). */
    function bindCollapsibleSections() {
        var page = 'home';
        try {
            page = new URLSearchParams(window.location.search).get('internalpage') || 'home';
        } catch (err) { /* URLSearchParams no disponible: clave genérica */ }
        var chevron = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>';

        var forms = document.querySelectorAll('.stic-form > form');
        Array.prototype.forEach.call(forms, function (form) {
            var headers = form.querySelectorAll('h5');
            Array.prototype.forEach.call(headers, function (h5, idx) {
                // La tarjeta de la sección es la siguiente <ul> (sin pasarse a
                // otra cabecera ni tocar la botonera sticky).
                var ul = h5.nextElementSibling;
                while (ul) {
                    if (ul.tagName === 'H5') { ul = null; break; }
                    if (ul.tagName === 'UL' && !ul.classList.contains('stic-ctabs-list')) { break; }
                    ul = ul.nextElementSibling;
                }
                if (!ul) { return; }

                var key = 'sticpa-sec:' + page + ':' + (h5.id || h5.textContent.trim());
                h5.classList.add('stic-sec-toggle');

                // El interruptor es un <button> DENTRO del h5, no un role=button
                // sobre el propio h5: así la cabecera sigue siendo un heading para
                // el lector de pantalla (navegación por encabezados intacta).
                if (!ul.id) { ul.id = 'stic-sec-panel-' + idx + '-' + (h5.id || 'x'); }
                var toggleBtn = document.createElement('button');
                toggleBtn.type = 'button';
                toggleBtn.className = 'stic-sec-btn';
                toggleBtn.setAttribute('aria-controls', ul.id);
                while (h5.firstChild) { toggleBtn.appendChild(h5.firstChild); }
                h5.appendChild(toggleBtn);

                var badge = document.createElement('span');
                badge.className = 'stic-sec-chevron';
                badge.setAttribute('aria-hidden', 'true');
                badge.innerHTML = chevron;
                toggleBtn.appendChild(badge);

                function setCollapsed(collapsed, persist) {
                    h5.classList.toggle('is-collapsed', collapsed);
                    ul.classList.toggle('stic-sec-hidden', collapsed);
                    toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                    // Un campo required oculto bloquearía el submit sin que el
                    // usuario vea dónde: se desactiva mientras está plegado.
                    var inputs = ul.querySelectorAll('input, select, textarea');
                    Array.prototype.forEach.call(inputs, function (input) {
                        if (collapsed) {
                            if (input.hasAttribute('required')) {
                                input.setAttribute('data-sec-required', '1');
                                input.removeAttribute('required');
                            }
                        } else if (input.hasAttribute('data-sec-required')) {
                            input.setAttribute('required', '');
                            input.removeAttribute('data-sec-required');
                        }
                    });
                    if (persist) {
                        try { localStorage.setItem(key, collapsed ? 'closed' : 'open'); } catch (err) { /* modo privado */ }
                    }
                }

                var saved = null;
                try { saved = localStorage.getItem(key); } catch (err) { /* modo privado */ }
                setCollapsed(saved === 'closed', false);

                function toggle() { setCollapsed(!h5.classList.contains('is-collapsed'), true); }
                // Un <button> real ya gestiona Enter/Espacio y el foco por sí solo.
                toggleBtn.addEventListener('click', toggle);
            });
        });
    }

    /* -------- Conmutador de tema claro/oscuro (opt-in, plan 016) --------
       El servidor ya pinta data-stic-theme desde la cookie (sin flash). Aquí
       solo alternamos en vivo y persistimos: cookie (la lee PHP en la próxima
       carga) + localStorage (respaldo). Sin JS, el tema queda como estaba. */
    function applyTheme(theme) {
        var dark = theme === 'dark';
        var nodes = document.querySelectorAll('.stic-container, .stic-auth-shell');
        Array.prototype.forEach.call(nodes, function (el) {
            if (dark) { el.setAttribute('data-stic-theme', 'dark'); }
            else { el.removeAttribute('data-stic-theme'); }
        });
        // Los overlays/tooltips/modales se anclan a <body> (fuera de .stic-container):
        // estampamos también <html> para poder tematizarlos por descendencia.
        if (dark) { document.documentElement.setAttribute('data-stic-theme', 'dark'); }
        else { document.documentElement.removeAttribute('data-stic-theme'); }
        var btns = document.querySelectorAll('.stic-theme-toggle');
        Array.prototype.forEach.call(btns, function (b) {
            b.setAttribute('aria-pressed', dark ? 'true' : 'false');
        });
    }

    function bindThemeToggle() {
        var toggles = document.querySelectorAll('.stic-theme-toggle');
        if (!toggles.length) { return; }
        Array.prototype.forEach.call(toggles, function (btn) {
            btn.addEventListener('click', function () {
                var isDark = btn.getAttribute('aria-pressed') === 'true';
                var next = isDark ? 'light' : 'dark';
                applyTheme(next);
                // Cookie 1 año para que el render del servidor no parpadee.
                try {
                    document.cookie = 'sticpa_theme=' + (next === 'dark' ? 'dark' : '') +
                        ';path=/;max-age=' + (next === 'dark' ? 31536000 : 0) + ';samesite=lax';
                } catch (err) { /* nada */ }
                try { localStorage.setItem('sticpa-theme', next); } catch (err) { /* modo privado */ }
            });
        });
    }

    ready(function () {
        // Tema oscuro aparcado: forzamos claro y limpiamos cualquier rastro
        // (cookie/localStorage/atributo de pruebas anteriores) para que nadie
        // quede en oscuro tras la retirada del conmutador.
        applyTheme('light');
        try { localStorage.removeItem('sticpa-theme'); } catch (err) { /* nada */ }
        try { document.cookie = 'sticpa_theme=;path=/;max-age=0;samesite=lax'; } catch (err) { /* nada */ }
        bindLoadingForms();
        bindPasswordToggles();
        bindAuthToggle();
        bindNavToggle();
        bindNavMore();
        bindInfoTips();
        bindConditionalFields();
        bindPartSwitch();
        bindCollapsibleSections();
        layoutNav();
        // Reajuste tras cargar las fuentes (cambian anchos de los textos).
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(layoutNav);
        }
        setTimeout(layoutNav, 250);
    });
})();
