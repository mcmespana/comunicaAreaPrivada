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
            // En MÓVIL abrimos en Agenda (listMonth): la rejilla de mes en pantallas
            // pequeñas aprieta demasiado; la lista es clara y cómoda. En escritorio
            // se mantiene la vista Mes. El usuario puede cambiar con los botones.
            try {
                if (window.matchMedia && window.matchMedia('(max-width: 767px)').matches
                    && (!fcSettings.initialView || fcSettings.initialView === 'dayGridMonth')) {
                    fcSettings.initialView = 'listMonth';
                }
            } catch (e) { /* sin matchMedia: se queda la vista por defecto */ }
            // Clic en un evento → su destino. Prioridad: extendedProps.href (nuevo
            // esquema: sesión/evento/inscripción); si no, el patrón antiguo por
            // módulo (?internalpage=<módulo>&action=detail&id=<id>).
            fcSettings.eventClick = function (arg) {
                var props = arg.event.extendedProps || {};
                if (arg.jsEvent) { arg.jsEvent.preventDefault(); }
                if (props.href) {
                    window.location.assign(props.href);
                } else if (props.module) {
                    window.location.assign(
                        '?internalpage=' + props.module + '&action=detail&id=' + arg.event.id
                    );
                }
            };
            // Tooltip nativo (title) con el nombre + estado, para hover y lectores.
            fcSettings.eventDidMount = function (info) {
                var tip = info.event.extendedProps && info.event.extendedProps.tooltip;
                if (tip) { info.el.setAttribute('title', tip); }
            };
            // Título del mes limpio: "Octubre 2025" en vez de "Octubre De 2025"
            // (el locale da "octubre de 2025"; quitamos el "de" y capitalizamos).
            var fcLang = fcSettings.locale || 'es';
            fcSettings.datesSet = function (arg) {
                try {
                    // Mes y Agenda muestran un rango mensual: en ambos ponemos
                    // "Octubre 2025" (textContent REEMPLAZA, así que nunca duplica).
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
