/*
 * stic-init.js — init de widgets dirigida por atributos data-* (plan 021).
 *
 * Sustituye a los <script> inline que emitían los listados y el calendario:
 *   · <table data-dt-settings='{...}'>  → jQuery(el).DataTable(settings)
 *     (el objeto `language` localizado llega UNA vez vía stic_script_vars.dtLanguage,
 *      ver inc/stic-script-vars.php; incluye el placeholder del buscador, plan 019).
 *   · <... data-fc-settings='{...}'>    → new FullCalendar.Calendar(el, settings)
 *     (el eventClick no es serializable a JSON: se añade aquí — navega al detalle
 *      con ?internalpage=<módulo>&action=detail&id=<id>, como el inline anterior).
 *
 * Este archivo es estático y cacheable; las dependencias (jQuery, DataTables,
 * FullCalendar) las garantiza el enqueue condicional de dcms_insertar_js.
 * Páginas nuevas con widgets JS: seguir este patrón data-*, no <script> inline.
 */
(function () {
    'use strict';

    function parseSettings(el, attr) {
        try {
            return JSON.parse(el.getAttribute(attr)) || {};
        } catch (err) {
            return {};
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var vars = (typeof stic_script_vars !== 'undefined') ? stic_script_vars : {};

        /* ---- DataTables: listados (inc/stic-listController.php) ---- */
        if (typeof jQuery !== 'undefined' && jQuery.fn && jQuery.fn.DataTable) {
            jQuery('table[data-dt-settings]').each(function () {
                var settings = parseSettings(this, 'data-dt-settings');
                if (!settings.language && vars.dtLanguage) {
                    settings.language = vars.dtLanguage;
                }
                jQuery(this).DataTable(settings);
            });
        }

        /* ---- FullCalendar: pages/single_stic_activities_calendar.php ---- */
        var calEl = document.querySelector('[data-fc-settings]');
        if (calEl && typeof FullCalendar !== 'undefined') {
            var fcSettings = parseSettings(calEl, 'data-fc-settings');
            var fcLang = fcSettings.locale || 'es';
            function isMobile() {
                try { return window.matchMedia && window.matchMedia('(max-width: 767px)').matches; }
                catch (e) { return false; }
            }
            // Destino de un evento: nuevo esquema extendedProps.href; si no, el
            // patrón antiguo por módulo (?internalpage=<módulo>&action=detail&id=<id>).
            function eventHref(ev) {
                var props = ev.extendedProps || {};
                if (props.href) { return props.href; }
                if (props.module) { return '?internalpage=' + props.module + '&action=detail&id=' + ev.id; }
                return null;
            }

            /* ---- Popover al TOCAR un evento (móvil): nombre + "Ir al evento" ----
               En móvil no navegamos de golpe (un toque accidental en una barrita no
               debe sacarte de la pantalla): mostramos un globo con el nombre, el
               estado y un botón para ir. Se cierra al tocar fuera / Escape / scroll. */
            var openPop = null;
            function closePop() {
                if (openPop) { openPop.remove(); openPop = null; }
                document.removeEventListener('keydown', popEsc, true);
                window.removeEventListener('scroll', closePop, true);
                document.removeEventListener('click', popOutside, true);
            }
            function popEsc(e) { if (e.key === 'Escape' || e.keyCode === 27) { closePop(); } }
            function popOutside(e) { if (openPop && !openPop.contains(e.target)) { closePop(); } }
            function showEventPopover(arg) {
                closePop();
                var ev = arg.event;
                var href = eventHref(ev);
                var color = ev.backgroundColor || ev.borderColor || '';
                var sub = (ev.extendedProps && ev.extendedProps.tooltip) ? ev.extendedProps.tooltip : '';
                var pop = document.createElement('div');
                pop.className = 'stic-fc-pop';
                pop.setAttribute('role', 'dialog');
                pop.setAttribute('aria-label', ev.title || '');
                var head = document.createElement('div');
                head.className = 'stic-fc-pop-head';
                var dot = document.createElement('span');
                dot.className = 'stic-fc-pop-dot';
                if (color) { dot.style.background = color; }
                var h = document.createElement('span');
                h.className = 'stic-fc-pop-title';
                h.textContent = ev.title || '';
                head.appendChild(dot); head.appendChild(h);
                pop.appendChild(head);
                if (sub && sub !== ev.title) {
                    var s = document.createElement('p');
                    s.className = 'stic-fc-pop-sub';
                    s.textContent = sub;
                    pop.appendChild(s);
                }
                if (href) {
                    var go = document.createElement('a');
                    go.className = 'stic-fc-pop-go';
                    go.href = href;
                    go.textContent = (vars && vars.calGoEvent) ? vars.calGoEvent : 'Ir al evento';
                    pop.appendChild(go);
                }
                document.body.appendChild(pop);
                openPop = pop;
                // Anclar (fixed) al evento, con clamp al viewport para que no se corte.
                var anchor = arg.el || (arg.jsEvent && arg.jsEvent.target);
                var r = anchor ? anchor.getBoundingClientRect() : { left: 20, right: 40, top: 80, bottom: 90, width: 20, height: 10 };
                var w = pop.offsetWidth, ph = pop.offsetHeight;
                var left = Math.round(r.left + r.width / 2 - w / 2);
                left = Math.max(10, Math.min(left, window.innerWidth - w - 10));
                var top = Math.round(r.bottom + 8);
                if (top + ph > window.innerHeight - 10) { top = Math.round(r.top - ph - 8); }
                if (top < 10) { top = 10; }
                pop.style.left = left + 'px';
                pop.style.top = top + 'px';
                setTimeout(function () {
                    document.addEventListener('click', popOutside, true);
                    document.addEventListener('keydown', popEsc, true);
                    window.addEventListener('scroll', closePop, true);
                    var f = pop.querySelector('.stic-fc-pop-go') || pop;
                    if (f.focus) { f.focus(); }
                }, 0);
            }

            fcSettings.eventClick = function (arg) {
                if (arg.jsEvent) { arg.jsEvent.preventDefault(); }
                var href = eventHref(arg.event);
                if (isMobile()) { showEventPopover(arg); }
                else if (href) { window.location.assign(href); }
            };

            // Render propio de cada evento en la rejilla (Mes): barra de color
            // unificada + título. El CSS decide: en móvil solo la barra (sin texto),
            // en escritorio punto + título. En Agenda se deja el render por defecto.
            fcSettings.eventContent = function (arg) {
                if (arg.view.type.indexOf('dayGrid') !== 0) { return undefined; }
                var color = arg.event.backgroundColor || arg.event.borderColor || '';
                var wrap = document.createElement('div');
                wrap.className = 'stic-fc-chip';
                var bar = document.createElement('span');
                bar.className = 'stic-fc-chip-bar';
                if (color) { bar.style.background = color; }
                var title = document.createElement('span');
                title.className = 'stic-fc-chip-title';
                if (arg.timeText) {
                    var tm = document.createElement('span');
                    tm.className = 'stic-fc-chip-time';
                    tm.textContent = arg.timeText + ' ';
                    title.appendChild(tm);
                }
                title.appendChild(document.createTextNode(arg.event.title));
                wrap.appendChild(bar); wrap.appendChild(title);
                return { domNodes: [wrap] };
            };

            // Tooltip nativo (title) con el nombre + estado, para hover y lectores.
            fcSettings.eventDidMount = function (info) {
                var tip = info.event.extendedProps && info.event.extendedProps.tooltip;
                if (tip) { info.el.setAttribute('title', tip); }
            };
            // Título del mes limpio: "Octubre 2025" (el locale da "octubre de 2025").
            fcSettings.datesSet = function (arg) {
                try {
                    var d = arg.view.currentStart;
                    var t = d.toLocaleDateString(fcLang, { month: 'long', year: 'numeric' });
                    t = t.replace(/\sde\s(\d{4})$/, ' $1');
                    t = t.charAt(0).toUpperCase() + t.slice(1);
                    var el = arg.view.calendar.el.querySelector('.fc-toolbar-title');
                    if (el) { el.textContent = t; }
                } catch (e) { /* si algo falla, se queda el título nativo */ }
            };
            new FullCalendar.Calendar(calEl, fcSettings).render();
        }
    });
})();
