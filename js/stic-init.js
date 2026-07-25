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
            function t(key, fallback) {
                return (vars && vars[key]) ? vars[key] : fallback;
            }
            // Destino de un evento: nuevo esquema extendedProps.href; si no, el
            // patrón antiguo por módulo (?internalpage=<módulo>&action=detail&id=<id>).
            function eventHref(ev) {
                var props = ev.extendedProps || {};
                if (props.href) { return props.href; }
                if (props.module) { return '?internalpage=' + props.module + '&action=detail&id=' + ev.id; }
                return null;
            }
            function ymd(d) {
                return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
            }

            /* ================================================================
               MÓVIL: patrón de calendario móvil de referencia (iOS / Google):
               rejilla COMPACTA con PUNTITOS por día + al tocar un día, sus
               actividades salen en una LISTA DEBAJO del calendario.
               Nada de barras dentro de celdas ni popovers flotantes: el detalle
               tiene sitio propio, se lee bien y se toca cómodo.
               ================================================================ */
            var dayPanel = null;      // contenedor de la lista del día (móvil)
            var selectedKey = null;   // 'YYYY-MM-DD' del día seleccionado
            var calendar = null;

            function ensureDayPanel() {
                if (dayPanel && dayPanel.parentNode) { return dayPanel; }
                dayPanel = document.createElement('div');
                dayPanel.className = 'stic-cal-day';
                dayPanel.setAttribute('role', 'region');
                dayPanel.setAttribute('aria-live', 'polite');
                calEl.parentNode.insertBefore(dayPanel, calEl.nextSibling);
                return dayPanel;
            }

            // Eventos que caen en un día (incluye los de RANGO en todos sus días).
            function eventsOfDay(dateStr) {
                if (!calendar) { return []; }
                return calendar.getEvents().filter(function (ev) {
                    var s = ev.start ? new Date(ev.start) : null;
                    if (!s) { return false; }
                    var e = ev.end ? new Date(ev.end) : null;
                    var startKey = ymd(s);
                    if (!e) { return startKey === dateStr; }
                    // Rango: incluye el día si cae entre inicio y fin (fin exclusivo en allDay).
                    var endKey = ymd(new Date(e.getTime() - (ev.allDay ? 86400000 : 0)));
                    return dateStr >= startKey && dateStr <= endKey;
                }).sort(function (a, b) { return (a.start || 0) - (b.start || 0); });
            }

            /* Puntitos: los pintamos NOSOTROS en cada celda (móvil) en vez de
               depender de cómo FullCalendar pinte los eventos. Así un evento de
               varios días marca TODOS sus días (antes solo el de inicio) y coincide
               exactamente con lo que muestra la lista de abajo. */
            function paintDots() {
                if (!isMobile() || !calendar) { return; }
                var cells = calEl.querySelectorAll('.fc-daygrid-day');
                Array.prototype.forEach.call(cells, function (cell) {
                    var old = cell.querySelector('.stic-day-dots');
                    if (old) { old.remove(); }
                    var date = cell.getAttribute('data-date');
                    if (!date) { return; }
                    var evs = eventsOfDay(date);
                    if (!evs.length) { return; }
                    var box = document.createElement('div');
                    box.className = 'stic-day-dots';
                    box.setAttribute('aria-hidden', 'true');
                    evs.slice(0, 4).forEach(function (ev) {
                        var s = document.createElement('span');
                        s.style.background = ev.backgroundColor || ev.borderColor || '';
                        box.appendChild(s);
                    });
                    var frame = cell.querySelector('.fc-daygrid-day-frame') || cell;
                    frame.appendChild(box);
                });
            }

            function renderDayList(dateStr) {
                if (!calendar) { return; }
                var panel = ensureDayPanel();
                var d = new Date(dateStr + 'T12:00:00');
                var evs = eventsOfDay(dateStr);

                var head = document.createElement('div');
                head.className = 'stic-cal-day-head';
                var htxt = d.toLocaleDateString(fcLang, { weekday: 'long', day: 'numeric', month: 'long' });
                head.textContent = htxt.charAt(0).toUpperCase() + htxt.slice(1);

                panel.innerHTML = '';
                panel.appendChild(head);

                if (!evs.length) {
                    var empty = document.createElement('p');
                    empty.className = 'stic-cal-day-empty';
                    empty.textContent = t('calNoEvents', 'No hay actividades este día');
                    panel.appendChild(empty);
                    return;
                }
                var ul = document.createElement('ul');
                ul.className = 'stic-cal-day-list';
                evs.forEach(function (ev) {
                    var li = document.createElement('li');
                    var href = eventHref(ev);
                    var row = document.createElement(href ? 'a' : 'div');
                    row.className = 'stic-cal-day-item';
                    if (href) { row.href = href; }
                    var dot = document.createElement('span');
                    dot.className = 'stic-cal-day-dot';
                    dot.style.background = ev.backgroundColor || ev.borderColor || '';
                    var body = document.createElement('span');
                    body.className = 'stic-cal-day-body';
                    var name = document.createElement('span');
                    name.className = 'stic-cal-day-name';
                    name.textContent = ev.title || '';
                    body.appendChild(name);
                    var metaTxt = '';
                    if (!ev.allDay && ev.start) {
                        metaTxt = ev.start.toLocaleTimeString(fcLang, { hour: '2-digit', minute: '2-digit' });
                    } else {
                        metaTxt = t('calAllDay', 'Todo el día');
                    }
                    var tip = ev.extendedProps && ev.extendedProps.tooltip;
                    if (tip && tip !== ev.title) { metaTxt += ' · ' + tip; }
                    var meta = document.createElement('span');
                    meta.className = 'stic-cal-day-meta';
                    meta.textContent = metaTxt;
                    body.appendChild(meta);
                    row.appendChild(dot); row.appendChild(body);
                    if (href) {
                        var chev = document.createElement('span');
                        chev.className = 'stic-cal-day-chev';
                        chev.setAttribute('aria-hidden', 'true');
                        chev.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 6 6 6-6 6"/></svg>';
                        row.appendChild(chev);
                    }
                    li.appendChild(row);
                    ul.appendChild(li);
                });
                panel.appendChild(ul);
            }

            function selectDay(dateStr) {
                selectedKey = dateStr;
                var cells = calEl.querySelectorAll('.fc-daygrid-day');
                Array.prototype.forEach.call(cells, function (c) {
                    c.classList.toggle('stic-day-selected', c.getAttribute('data-date') === dateStr);
                });
                renderDayList(dateStr);
            }

            // Tocar un DÍA (móvil) → su lista debajo. Listener DELEGADO propio en
            // vez de la opción dateClick: esta depende del plugin interaction, que
            // puede no estar en el bundle; el click en la celda siempre funciona.
            calEl.addEventListener('click', function (e) {
                if (!isMobile()) { return; }
                var cell = e.target.closest ? e.target.closest('.fc-daygrid-day') : null;
                if (!cell) { return; }
                var date = cell.getAttribute('data-date');
                if (date) { selectDay(date); }
            });

            // Tocar un EVENTO: en móvil seleccionamos su día (la lista de abajo
            // muestra el detalle y desde ahí se navega); en escritorio, al detalle.
            fcSettings.eventClick = function (arg) {
                if (arg.jsEvent) { arg.jsEvent.preventDefault(); }
                if (isMobile()) {
                    if (arg.event.start) { selectDay(ymd(new Date(arg.event.start))); }
                    return;
                }
                var href = eventHref(arg.event);
                if (href) { window.location.assign(href); }
            };

            // Render de cada evento en la rejilla: en MÓVIL solo un PUNTITO (el
            // texto vive en la lista de abajo); en escritorio, punto + título.
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
                    var txt = d.toLocaleDateString(fcLang, { month: 'long', year: 'numeric' });
                    txt = txt.replace(/\sde\s(\d{4})$/, ' $1');
                    txt = txt.charAt(0).toUpperCase() + txt.slice(1);
                    var el = arg.view.calendar.el.querySelector('.fc-toolbar-title');
                    if (el) { el.textContent = txt; }
                } catch (e) { /* si algo falla, se queda el título nativo */ }
                // Al cambiar de mes en móvil: seleccionar hoy si está en el mes
                // visible; si no, el día 1. Así la lista de abajo nunca queda huérfana.
                if (isMobile() && arg.view.type.indexOf('dayGrid') === 0) {
                    var today = new Date();
                    var inView = today >= arg.view.currentStart && today < arg.view.currentEnd;
                    var target = inView ? ymd(today) : ymd(arg.view.currentStart);
                    setTimeout(function () { paintDots(); selectDay(target); }, 0);
                }
            };
            // Rejilla COMPACTA: sin altura fija, FullCalendar estira las filas
            // para llenar el alto y las celdas quedan enormes en móvil.
            if (isMobile()) {
                fcSettings.contentHeight = 'auto';
                // OJO: dayMaxEvents 0 mandaría TODOS los eventos al "+N más" (y la
                // celda quedaría sin puntos). false = pintarlos todos; como en móvil
                // son puntitos de 6px con wrap, caben de sobra.
                fcSettings.dayMaxEvents = false;
                fcSettings.fixedWeekCount = false; // no pintar semanas de relleno
                fcSettings.showNonCurrentDates = false;
            }
            calendar = new FullCalendar.Calendar(calEl, fcSettings);
            calendar.render();
        }
    });
})();
