/**
 * PASAR LISTA — interacción de la pantalla de marcado.
 * ---------------------------------------------------------------------------
 * Sin dependencias (ni jQuery): se carga solo en las pantallas de Pasar Lista y
 * tiene que arrancar rápido en la webview de MCM App.
 *
 * Lo que hace:
 *   · Tocar una fila cicla sin marcar → vino → no vino.
 *   · Mantener pulsado abre la hoja con los cuatro estados del CRM.
 *   · "Han venido todos" marca todo de golpe.
 *   · Guardar manda TODA la lista en una sola petición.
 *
 * Nada se escribe hasta que se pulsa Guardar: es lo que permite marcar sin
 * cobertura y lo que evita dejar media lista escrita si algo falla a mitad.
 *
 * Diseño: docs/comunica/PASAR-LISTA.md §6.3
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-pl-marcar]');
    if (!root) {
        return;
    }

    /* El ciclo del toque simple: solo los tres frecuentes. Desde un estado que
       únicamente se alcanza manteniendo pulsado (parcial, justificada), el toque
       lleva a "vino", que es lo que espera quien toca una fila ya marcada.
       Mismo orden que sticpa_pl_next_state() en PHP: si cambia uno, cambia el
       otro. */
    function nextState(current) {
        switch (current) {
            case '': return 'yes';
            case 'yes': return 'no_unjustified';
            case 'no_unjustified': return '';
            default: return 'yes';
        }
    }

    var COUNTS = { yes: true, partial: true };   // cuentan como asistencia
    var HOLD_MS = 500;                            // umbral del gesto largo
    var HOLD_SLOP = 10;                           // px de movimiento que lo cancelan

    var rows = Array.prototype.slice.call(root.querySelectorAll('.pl-row'));
    var saveBtn = root.querySelector('[data-pl-save]');
    var counts = {
        yes: root.querySelector('[data-pl-count-yes]'),
        no: root.querySelector('[data-pl-count-no]'),
        none: root.querySelector('[data-pl-count-none]'),
        noneWrap: root.querySelector('[data-pl-count-none-wrap]')
    };
    var sheet = document.querySelector('[data-pl-sheet]');
    var veil = document.querySelector('[data-pl-veil]');
    var form = root.querySelector('[data-pl-form]');
    var marksInput = root.querySelector('[data-pl-marks]');

    // ---- Estado en memoria ------------------------------------------------

    function getState(row) {
        return row.getAttribute('data-state') || '';
    }

    function setState(row, value) {
        row.setAttribute('data-state', value);
        var note = row.querySelector('[data-pl-state-note]');
        if (note) {
            // Los dos estados del gesto largo se dicen con palabras bajo el
            // nombre: ver "Parcial" escrito en una fila enseña que existen.
            var label = (value === 'partial' || value === 'no_justified')
                ? (row.getAttribute('data-label-' + value) || '')
                : '';
            note.textContent = label;
            note.hidden = (label === '');
        }
        refresh();
    }

    function refresh() {
        var nYes = 0, nNo = 0, nNone = 0;
        rows.forEach(function (row) {
            var s = getState(row);
            if (s === '') { nNone++; } else if (COUNTS[s]) { nYes++; } else { nNo++; }
        });
        if (counts.yes) { counts.yes.textContent = nYes; }
        if (counts.no) { counts.no.textContent = nNo; }
        if (counts.none) { counts.none.textContent = nNone; }
        if (counts.noneWrap) { counts.noneWrap.hidden = (nNone === 0); }

        // Si queda gente sin marcar, el botón lo dice en vez de callárselo.
        if (saveBtn) {
            var tpl = nNone > 0
                ? (saveBtn.getAttribute('data-label-partial') || 'Guardar ({n} sin marcar)')
                : (saveBtn.getAttribute('data-label-full') || 'Guardar lista');
            saveBtn.textContent = tpl.replace('{n}', nNone);
        }
    }

    // ---- Toque y gesto largo ---------------------------------------------

    /* El gesto largo en web: pointerdown arranca un temporizador y lo cancelan
       soltar, salirse o mover el dedo más de unos píxeles (para no confundirlo
       con un scroll). Si el temporizador llega, se marca el evento como
       consumido para que el click que viene detrás no cicle también.
       El contextmenu se anula porque en Android el pulsado largo abre el menú
       del navegador y se lleva el gesto. */
    rows.forEach(function (row) {
        var timer = null;
        var startX = 0, startY = 0;
        var consumed = false;

        function cancel() {
            if (timer) { clearTimeout(timer); timer = null; }
            row.classList.remove('is-holding');
        }

        row.addEventListener('pointerdown', function (ev) {
            if (ev.button && ev.button !== 0) { return; }
            consumed = false;
            startX = ev.clientX;
            startY = ev.clientY;
            row.classList.add('is-holding');
            timer = setTimeout(function () {
                timer = null;
                consumed = true;
                row.classList.remove('is-holding');
                openSheet(row);
            }, HOLD_MS);
        });

        row.addEventListener('pointermove', function (ev) {
            if (!timer) { return; }
            if (Math.abs(ev.clientX - startX) > HOLD_SLOP || Math.abs(ev.clientY - startY) > HOLD_SLOP) {
                cancel();
            }
        });

        row.addEventListener('pointerup', cancel);
        row.addEventListener('pointercancel', function () { cancel(); consumed = true; });
        row.addEventListener('pointerleave', cancel);

        // En escritorio, el clic derecho abre la misma hoja: es el equivalente
        // razonable del pulsado largo con ratón.
        row.addEventListener('contextmenu', function (ev) {
            ev.preventDefault();
            cancel();
            consumed = true;
            openSheet(row);
        });

        row.addEventListener('click', function (ev) {
            if (ev.target.closest('[data-pl-detail]')) {
                return;     // la flecha abre la ficha, no marca
            }
            if (consumed) {
                consumed = false;
                return;
            }
            setState(row, nextState(getState(row)));
        });

        // Teclado: espacio/enter ciclan, con la tecla de menú para los cuatro.
        row.addEventListener('keydown', function (ev) {
            if (ev.key === ' ' || ev.key === 'Enter') {
                ev.preventDefault();
                setState(row, nextState(getState(row)));
            } else if (ev.key === 'ContextMenu' || (ev.shiftKey && ev.key === 'F10')) {
                ev.preventDefault();
                openSheet(row);
            }
        });
    });

    // ---- "Han venido todos" ----------------------------------------------

    var allBtn = root.querySelector('[data-pl-all-present]');
    if (allBtn) {
        allBtn.addEventListener('click', function () {
            rows.forEach(function (row) { setState(row, 'yes'); });
        });
    }

    // ---- La hoja de los cuatro estados -----------------------------------

    var sheetRow = null;

    function openSheet(row) {
        if (!sheet) { return; }
        sheetRow = row;

        var name = sheet.querySelector('[data-pl-sheet-name]');
        var initials = sheet.querySelector('[data-pl-sheet-initials]');
        if (name) { name.textContent = row.getAttribute('data-name') || ''; }
        if (initials) { initials.textContent = row.getAttribute('data-initials') || ''; }

        var current = getState(row);
        Array.prototype.forEach.call(sheet.querySelectorAll('.pl-opt'), function (opt) {
            opt.setAttribute('aria-checked', opt.getAttribute('data-value') === current ? 'true' : 'false');
        });

        sheet.classList.add('is-open');
        if (veil) { veil.classList.add('is-open'); }
        sheet.setAttribute('aria-hidden', 'false');

        // Un golpecito háptico, donde exista: confirma que el gesto ha ido bien
        // sin tener que mirar la pantalla.
        if (navigator.vibrate) {
            try { navigator.vibrate(12); } catch (e) { /* da igual */ }
        }
    }

    function closeSheet() {
        if (!sheet) { return; }
        sheet.classList.remove('is-open');
        if (veil) { veil.classList.remove('is-open'); }
        sheet.setAttribute('aria-hidden', 'true');
        sheetRow = null;
    }

    if (sheet) {
        Array.prototype.forEach.call(sheet.querySelectorAll('.pl-opt'), function (opt) {
            opt.addEventListener('click', function () {
                if (sheetRow) { setState(sheetRow, opt.getAttribute('data-value') || ''); }
                closeSheet();
            });
        });
        var clear = sheet.querySelector('[data-pl-sheet-clear]');
        if (clear) {
            clear.addEventListener('click', function () {
                if (sheetRow) { setState(sheetRow, ''); }
                closeSheet();
            });
        }
    }
    if (veil) { veil.addEventListener('click', closeSheet); }
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape') { closeSheet(); }
    });

    // ---- Guardar ----------------------------------------------------------

    /* Toda la lista en una petición. Los estados viajan en un campo oculto como
       JSON en vez de un input por fila: así el guardado es uno, y el día que
       haya modo sin conexión lo único que cambia es dónde se deja ese JSON
       mientras no hay red. */
    if (form) {
        form.addEventListener('submit', function () {
            var marks = {};
            rows.forEach(function (row) {
                var id = row.getAttribute('data-contact');
                if (id) { marks[id] = getState(row); }
            });
            if (marksInput) { marksInput.value = JSON.stringify(marks); }
            if (saveBtn) { saveBtn.disabled = true; }
        });
    }

    refresh();
}());
