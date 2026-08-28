/**
 * PASAR LISTA — interacción de la pantalla de marcado.
 * ===========================================================================
 * Sin dependencias (ni jQuery): se carga solo en las pantallas de Pasar Lista y
 * tiene que arrancar rápido en la webview de MCM App.
 *
 * Lo que hace:
 *   · Tocar una fila cicla sin marcar → vino → no vino.
 *   · Mantener pulsado abre la hoja con los cuatro estados, con un anillo que
 *     se va llenando mientras aguantas: el gesto se VE avanzar.
 *   · La hoja se arrastra con el dedo, con muelle e interrumpible.
 *   · "Han venido todos" marca todo de golpe.
 *   · Guardar manda TODA la lista en una sola petición.
 *   · Lo marcado se guarda en el móvil y se reenvía solo si no hay cobertura.
 *
 * PRINCIPIOS DE MOVIMIENTO (los del diseño fluido de Apple, aplicados aquí):
 *   1. Respuesta al PULSAR, no al soltar. Nada espera al `click`.
 *   2. Feedback CONTINUO durante el gesto, no solo al final.
 *   3. Todo es interrumpible: la hoja se puede agarrar en pleno vuelo y se
 *      sigue desde donde está, nunca desde el valor de destino.
 *   4. Al soltar, el movimiento CONTINÚA a la velocidad del dedo. Sin costura
 *      entre arrastrar y animar.
 *   5. Solo se animan `transform` y `opacity`, que son las que no repintan.
 *
 * Diseño: docs/comunica/PASAR-LISTA.md §6.3
 */
(function () {
    'use strict';

    /* =====================================================================
     * Utilidades comunes
     * ===================================================================== */

    var REDUCED = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /**
     * Un muelle mínimo sobre requestAnimationFrame.
     *
     * Se escribe a mano en vez de traer una librería porque es la única
     * animación de la pantalla que lo necesita y son treinta líneas. Los
     * parámetros son los de Apple —amortiguación y "respuesta"— y no
     * masa/rigidez/fricción, porque son los que se pueden razonar: la
     * amortiguación decide si rebota y la respuesta, lo rápido que llega.
     *
     * Empieza SIEMPRE desde el valor actual y acepta una velocidad inicial:
     * eso es lo que permite interrumpirlo y lo que hace que al soltar el dedo
     * no se note el salto.
     */
    function spring(opts) {
        var value = opts.from;
        var velocity = opts.velocity || 0;
        var target = opts.to;
        var onUpdate = opts.onUpdate;
        var onRest = opts.onRest;

        // damping 1 = sin rebote; 0.8 = un rebote pequeño (el de los cajones).
        var damping = (opts.damping === undefined) ? 1 : opts.damping;
        var response = (opts.response === undefined) ? 0.35 : opts.response;

        // De (amortiguación, respuesta) a los coeficientes del muelle.
        var omega = (2 * Math.PI) / response;
        var zeta = damping;

        var raf = null;
        var last = null;
        var stopped = false;

        function step(now) {
            if (stopped) { return; }
            if (last === null) { last = now; }
            // Paso de tiempo acotado: si la pestaña se queda atrás, un dt enorme
            // haría explotar la integración y el elemento saltaría.
            var dt = Math.min((now - last) / 1000, 1 / 30);
            last = now;

            var distance = value - target;
            var accel = (-omega * omega * distance) - (2 * zeta * omega * velocity);
            velocity += accel * dt;
            value += velocity * dt;

            onUpdate(value);

            // Descansa cuando ya no se distingue del destino ni se mueve.
            if (Math.abs(value - target) < 0.4 && Math.abs(velocity) < 12) {
                value = target;
                onUpdate(value);
                stopped = true;
                if (onRest) { onRest(); }
                return;
            }
            raf = requestAnimationFrame(step);
        }

        raf = requestAnimationFrame(step);

        return {
            /** Redirige sin cortar: la velocidad actual se conserva. */
            retarget: function (next) { target = next; },
            /** Para y devuelve dónde estaba, para poder seguir desde ahí. */
            stop: function () {
                stopped = true;
                if (raf) { cancelAnimationFrame(raf); }
                return { value: value, velocity: velocity };
            },
            get value() { return value; },
            get velocity() { return velocity; }
        };
    }

    /**
     * Dónde acabaría algo que se suelta a esta velocidad.
     *
     * Es la proyección de inercia de iOS (decaimiento exponencial), no la
     * fórmula de libro `v²/2a`. Sirve para decidir si un empujón corto pero
     * rápido tiene que cerrar la hoja aunque el dedo apenas se haya movido:
     * se mira a dónde IBA el gesto, no dónde acabó.
     */
    function project(velocity, decelerationRate) {
        var d = decelerationRate || 0.998;
        return (velocity / 1000) * d / (1 - d);
    }

    /** Resistencia progresiva al pasarse de un borde, en vez de tope seco. */
    function rubberband(overshoot, dimension, constant) {
        var c = constant || 0.55;
        return (overshoot * dimension * c) / (dimension + c * Math.abs(overshoot));
    }

    /** Un golpecito háptico, donde exista. Nunca debe romper nada. */
    function haptic(ms) {
        if (!navigator.vibrate) { return; }
        try { navigator.vibrate(ms); } catch (e) { /* da igual */ }
    }

    /* =====================================================================
     * Persistencia local: borrador y cola de envío
     * ---------------------------------------------------------------------
     * Se pasa lista en patios y sótanos. Dos cosas distintas:
     *
     *   BORRADOR — lo marcado se guarda en el móvil a cada toque. Si la app se
     *   cierra, se recarga o se va la luz, al volver está todo. Es la que
     *   quita el miedo de "he pasado lista y se ha perdido".
     *
     *   COLA — si al guardar no hay cobertura, el envío se guarda y se reintenta
     *   al recuperar red. El monitor se va a casa con la lista puesta.
     *
     * localStorage y no IndexedDB porque son cuatro kilobytes (una sesión, doce
     * participantes) y el acceso es sincrónico, que es justo lo que quieres en
     * el manejador de un toque.
     * ===================================================================== */

    var STORE_DRAFT = 'sticpa_pl_draft_';
    var STORE_QUEUE = 'sticpa_pl_queue';
    var STORE_HINT = 'sticpa_pl_hold_seen';

    function lsGet(key) {
        try { return window.localStorage.getItem(key); } catch (e) { return null; }
    }
    function lsSet(key, value) {
        try { window.localStorage.setItem(key, value); return true; } catch (e) { return false; }
    }
    function lsDel(key) {
        try { window.localStorage.removeItem(key); } catch (e) { /* nada */ }
    }

    function readJson(key, fallback) {
        var raw = lsGet(key);
        if (!raw) { return fallback; }
        try {
            var parsed = JSON.parse(raw);
            return (parsed === null) ? fallback : parsed;
        } catch (e) {
            lsDel(key);     // basura: se tira, no se arrastra
            return fallback;
        }
    }

    /* ---- Cola de envíos pendientes -------------------------------------- */

    function queueRead() {
        var q = readJson(STORE_QUEUE, []);
        return Array.isArray(q) ? q : [];
    }

    function queuePush(entry) {
        var q = queueRead();
        // Una entrada por sesión y grupo: si se guarda dos veces sin cobertura,
        // manda la última. Reenviar las dos escribiría lo viejo encima.
        q = q.filter(function (e) {
            return !(e.session === entry.session && e.group === entry.group);
        });
        q.push(entry);
        lsSet(STORE_QUEUE, JSON.stringify(q));
    }

    function queueRemove(entry) {
        var q = queueRead().filter(function (e) {
            return !(e.session === entry.session && e.group === entry.group);
        });
        if (q.length) { lsSet(STORE_QUEUE, JSON.stringify(q)); } else { lsDel(STORE_QUEUE); }
    }

    /* Un intento fallido no se tira: se cuenta. Un nonce caducado devuelve 200
       con un aviso, así que sin contar los intentos la entrada se reenviaría
       para siempre sin que nadie se enterase. */
    function queueBumpTries(entry) {
        var q = queueRead().map(function (e) {
            if (e.session === entry.session && e.group === entry.group) {
                e.tries = (e.tries || 0) + 1;
            }
            return e;
        });
        lsSet(STORE_QUEUE, JSON.stringify(q));
    }

    var QUEUE_MAX_TRIES = 5;

    /** ¿Hay algo en la cola que ya no hay forma de enviar solo? */
    function queueStuck() {
        return queueRead().some(function (e) { return (e.tries || 0) >= QUEUE_MAX_TRIES; });
    }

    /**
     * Intenta enviar lo que haya en la cola.
     *
     * Va a la MISMA url y con los mismos campos que el formulario, así que el
     * servidor no distingue un reenvío de un envío normal: no hay una segunda
     * ruta de guardado que pueda quedarse desincronizada de la primera.
     */
    function queueFlush(onDone) {
        var q = queueRead();
        if (!q.length || !navigator.onLine || !window.fetch) {
            if (onDone) { onDone(0, q.length); }
            return;
        }
        var pending = q.length;
        var sent = 0;

        q.forEach(function (entry) {
            var body = new URLSearchParams();
            body.set('pl_action', entry.action || 'save');
            body.set('pl_nonce', entry.nonce || '');
            body.set('pl_marks', entry.marks || '{}');
            // Los motivos van igual que en el envío normal. Una entrada de la
            // cola de antes de que esto existiera no los trae, y entonces se
            // manda vacío: se guardan los estados y no se pierde la lista.
            body.set('pl_notes', entry.notes || '{}');

            fetch(entry.url, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            }).then(function (res) {
                // `res.ok` NO vale como prueba: un nonce caducado o un fallo del
                // CRM contestan 200 con un aviso, y la entrada se daba por
                // enviada — la lista del monitor se perdía en silencio. La
                // prueba es el atributo que el servidor pinta SOLO cuando ha
                // releído el CRM y lo ha comprobado.
                if (!res.ok) { return null; }
                return res.text();
            }).then(function (html) {
                if (html === null) { return; }
                if (html.indexOf('data-pl-saved-ok') !== -1) {
                    queueRemove(entry);
                    sent++;
                } else {
                    queueBumpTries(entry);
                }
            }).catch(function () {
                /* sigue en la cola para el próximo intento */
            }).then(function () {
                pending--;
                if (pending === 0 && onDone) { onDone(sent, queueRead().length); }
            });
        });
    }

    /* =====================================================================
     * Pantalla de marcado
     * ===================================================================== */

    var root = document.querySelector('[data-pl-marcar]');

    if (root) {
        initMarcar(root);
    } else {
        // Fuera de la pantalla de marcado, la cola sigue siendo asunto nuestro:
        // si el monitor vuelve a entrar con cobertura, se envía lo pendiente.
        if (navigator.onLine) { queueFlush(); }
        window.addEventListener('online', function () { queueFlush(); });
    }

    function initMarcar(root) {
        /* El ciclo del toque simple: solo los tres frecuentes. Desde un estado
           que solo se alcanza manteniendo pulsado, el toque lleva a "vino", que
           es lo que espera quien toca una fila ya marcada de forma especial.
           Mismo orden que sticpa_pl_next_state() en PHP. */
        // En la lista de MONITORES el ciclo es verde <-> rojo y no existe el
        // "sin marcar": se asume que vienen siempre, así que el toque solo pone
        // y quita faltas. Mismo par de reglas que sticpa_pl_next_state() y
        // sticpa_pl_next_state_monitor() en PHP.
        var IS_MONITORS = root.hasAttribute('data-pl-monitores');

        function nextState(current) {
            if (IS_MONITORS) {
                return (current === 'no_unjustified') ? 'yes' : 'no_unjustified';
            }
            switch (current) {
                case '': return 'yes';
                case 'yes': return 'no_unjustified';
                case 'no_unjustified': return '';
                default: return 'yes';
            }
        }

        var COUNTS = { yes: true, partial: true };
        var HOLD_MS = 500;
        var HOLD_SLOP = 10;

        var sessionId = root.getAttribute('data-session') || '';
        var groupId = root.getAttribute('data-group') || '';
        var draftKey = STORE_DRAFT + sessionId + '_' + groupId;

        var rows = Array.prototype.slice.call(root.querySelectorAll('.pl-row'));
        var saveBtn = root.querySelector('[data-pl-save]');
        var form = root.querySelector('[data-pl-form]');
        var marksInput = root.querySelector('[data-pl-marks]');
        var notesInput = root.querySelector('[data-pl-notes]');
        var status = root.querySelector('[data-pl-status]');
        var counts = {
            yes: root.querySelector('[data-pl-count-yes]'),
            no: root.querySelector('[data-pl-count-no]'),
            none: root.querySelector('[data-pl-count-none]'),
            noneWrap: root.querySelector('[data-pl-count-none-wrap]')
        };

        var dirty = false;

        /* ---- Estado en memoria ----------------------------------------- */

        function getState(row) {
            return row.getAttribute('data-state') || '';
        }

        function collect() {
            var marks = {};
            rows.forEach(function (row) {
                var id = row.getAttribute('data-contact');
                if (id) { marks[id] = getState(row); }
            });
            return marks;
        }

        /* Los motivos van en su propio campo y no dentro de `marks`.
           A propósito: `marks` es el formato que ya está en los borradores y en
           la cola de envío de los móviles, y cambiarlo dejaría sin entender lo
           que hay guardado de antes. Un campo nuevo al lado no rompe nada, y un
           borrador viejo simplemente vuelve sin motivos. */
        function collectNotes() {
            var notes = {};
            rows.forEach(function (row) {
                var id = row.getAttribute('data-contact');
                var m = row.getAttribute('data-motive') || '';
                if (id && m !== '') { notes[id] = m; }
            });
            return notes;
        }

        function setState(row, value, quiet) {
            if (getState(row) === value) { return; }
            row.setAttribute('data-state', value);

            // Un pellizco de escala en el círculo, en el instante del cambio: es
            // la confirmación de que el toque ha hecho algo.
            //
            // Con la API de animaciones y NO reiniciando una clase de CSS: el
            // truco de quitar la clase, leer offsetWidth y volver a ponerla
            // obliga al navegador a recalcular el diseño del documento entero,
            // y aquí pasaría en cada toque de cada fila. `animate()` hace lo
            // mismo sin tocar el diseño y solo anima `transform`.
            var mark = row.querySelector('.pl-mark');
            if (mark && !REDUCED && mark.animate) {
                try {
                    mark.animate(
                        [{ transform: 'scale(1)' }, { transform: 'scale(1.16)' }, { transform: 'scale(1)' }],
                        { duration: 260, easing: 'cubic-bezier(0.34, 1.56, 0.64, 1)' }
                    );
                } catch (e) { /* sin pellizco, pero la marca se pone igual */ }
            }

            var note = row.querySelector('[data-pl-state-note]');
            if (note) {
                // Los dos estados del gesto largo se dicen con palabras bajo el
                // nombre: ver "Parcial" escrito en una fila enseña que existen.
                var label = (value === 'partial' || value === 'no_justified')
                    ? (row.getAttribute('data-label-' + value) || '')
                    : '';
                var warn = row.getAttribute('data-warn') || '';
                var text = [label, warn].filter(Boolean).join(' · ');
                note.textContent = text;
                note.hidden = (text === '');
            }

            if (!quiet) {
                setDirty(true);
                saveDraft();
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
            if (saveBtn && !saveBtn.disabled) {
                var tpl = nNone > 0
                    ? (saveBtn.getAttribute('data-label-partial') || 'Guardar ({n} sin marcar)')
                    : (saveBtn.getAttribute('data-label-full') || 'Guardar lista');
                saveBtn.textContent = tpl.replace('{n}', nNone);
            }
        }

        /* ---- Borrador --------------------------------------------------- */

        function saveDraft() {
            if (!sessionId || !groupId) { return; }
            lsSet(draftKey, JSON.stringify({ marks: collect(), notes: collectNotes(), ts: Date.now() }));
        }

        function restoreDraft() {
            if (!sessionId || !groupId) { return; }
            var draft = readJson(draftKey, null);
            if (!draft || !draft.marks) { return; }

            var server = collect();
            var notes = draft.notes || {};
            var changed = 0;
            rows.forEach(function (row) {
                var id = row.getAttribute('data-contact');
                if (!id || !(id in draft.marks)) { return; }
                // El motivo se restaura ANTES del estado: setState() repinta la
                // nota bajo el nombre, y si el motivo llega después se queda sin
                // pintar hasta el siguiente toque.
                if (id in notes) { row.setAttribute('data-motive', notes[id]); }
                if (draft.marks[id] !== server[id]) {
                    setState(row, draft.marks[id], true);
                    changed++;
                }
            });

            if (changed > 0) {
                // Sin guardar, sí, pero el aviso que toca es el del borrador:
                // dice lo mismo Y de dónde salen esas marcas.
                dirty = true;
                say('draft', root.getAttribute('data-msg-draft') || '');
            }
        }

        /** El aviso de estado de la barra: una línea, un motivo. */
        function say(kind, text) {
            if (!status || !text) { return; }
            status.textContent = text;
            status.setAttribute('data-kind', kind);
            status.hidden = false;
        }
        function hush() {
            if (status) { status.hidden = true; }
        }

        /** Marcar o desmarcar «hay cambios que solo están en este móvil».
         *
         * ANTES NO SE DECÍA NADA. Se marcaba a diez chavales, la pantalla se
         * quedaba igual, y lo único que avisaba era el diálogo del navegador AL
         * SALIR — o sea, cuando ya te ibas. Quien cerraba la app sin más creía
         * que estaba guardado.
         *
         * El aviso tiene que distinguirse MUCHO del verde de «guardado en el
         * CRM»: uno es una promesa y el otro un hecho. Va en ámbar, con un
         * punto que late, y justo encima del botón de Guardar, que es donde
         * está mirando quien acaba de marcar.
         *
         * Sin cobertura manda el aviso de cobertura: ahí «solo en tu móvil» es
         * cierto pero no es la noticia, y dos avisos discutiendo en la misma
         * línea no los lee nadie.
         */
        function setDirty(value) {
            dirty = !!value;
            if (!status) { return; }
            if (!dirty) {
                if (status.getAttribute('data-kind') === 'dirty') { hush(); }
                return;
            }
            if (!navigator.onLine || queueRead().length > 0) { return; }
            say('dirty', root.getAttribute('data-msg-dirty') || '');
        }

        /* ---- Toque y gesto largo --------------------------------------- */

        /* El gesto largo en web: `pointerdown` arranca un temporizador y lo
           cancelan soltar, salirse o mover el dedo más de unos píxeles (para no
           confundirlo con un scroll). Mientras corre, un anillo se va llenando
           alrededor del círculo: el gesto se ve avanzar, que es la diferencia
           entre "no sabía que se podía" y "ya sé cómo". Si el temporizador
           llega, el `click` que viene detrás se ignora.
           El `contextmenu` se anula porque en Android el pulsado largo abre el
           menú del navegador y se lleva el gesto. */
        rows.forEach(function (row) {
            var timer = null;
            var startX = 0, startY = 0;
            var consumed = false;

            function cancelHold() {
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
                    cancelHold();
                }
            });

            row.addEventListener('pointerup', cancelHold);
            row.addEventListener('pointercancel', function () { cancelHold(); consumed = true; });
            row.addEventListener('pointerleave', cancelHold);

            // En escritorio, el clic derecho abre la misma hoja.
            row.addEventListener('contextmenu', function (ev) {
                ev.preventDefault();
                cancelHold();
                consumed = true;
                openSheet(row);
            });

            row.addEventListener('click', function (ev) {
                if (ev.target.closest && ev.target.closest('[data-pl-detail]')) {
                    return;     // la flecha abre la ficha, no marca
                }
                if (consumed) {
                    consumed = false;
                    return;
                }
                setState(row, nextState(getState(row)));
            });

            // Teclado: espacio y enter ciclan; la tecla de menú abre los cuatro.
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

        /* ---- "Han venido todos" ---------------------------------------- */

        var allBtn = root.querySelector('[data-pl-all-present]');
        if (allBtn) {
            allBtn.addEventListener('click', function () {
                // Escalonado mínimo: las filas se marcan en cascada de arriba
                // abajo. Cuesta 20 ms por fila y convierte un cambio de golpe
                // (que se lee como un parpadeo) en algo que se entiende.
                rows.forEach(function (row, i) {
                    if (REDUCED) {
                        setState(row, 'yes', i > 0);
                        return;
                    }
                    setTimeout(function () { setState(row, 'yes', i > 0); }, i * 20);
                });
                haptic(10);
            });
        }

        /* ---- La hoja de los cuatro estados ----------------------------- */

        var sheet = document.querySelector('[data-pl-sheet]');
        var veil = document.querySelector('[data-pl-veil]');
        var motive = sheet ? sheet.querySelector('[data-pl-sheet-motive]') : null;
        var sheetRow = null;
        var sheetSpring = null;
        var sheetY = 0;             // 0 = abierta del todo; height = cerrada
        var sheetHeight = 0;
        var drag = null;

        function renderSheet(y) {
            sheetY = y;
            // Solo transform y opacity: son las dos que el navegador puede mover
            // sin repintar. Cualquier otra propiedad aquí costaría cuadros.
            sheet.style.transform = 'translate3d(0,' + y + 'px,0)';
            if (veil) {
                var p = sheetHeight > 0 ? (1 - Math.min(Math.max(y / sheetHeight, 0), 1)) : 1;
                veil.style.opacity = String(p);
            }
        }

        function springTo(to, velocity, onRest) {
            if (sheetSpring) { sheetSpring.stop(); }
            if (REDUCED) {
                renderSheet(to);
                if (onRest) { onRest(); }
                return;
            }
            sheetSpring = spring({
                from: sheetY,
                to: to,
                velocity: velocity || 0,
                // Los valores del cajón de iOS: un rebote pequeño, porque el
                // gesto que lo mueve trae inercia. Sin gesto no rebotaría.
                damping: 0.8,
                response: 0.3,
                onUpdate: renderSheet,
                onRest: onRest
            });
        }

        /* Pasa lo escrito en el campo a la fila. Se llama al elegir un estado y
           al cerrar la hoja de CUALQUIER forma —arrastrando, por el velo, con
           Escape—: escribir un motivo y cerrar arrastrando es un gesto normal, y
           perder lo escrito ahí sería la peor sorpresa de la pantalla. */
        function commitMotive() {
            if (!motive || !sheetRow) { return; }
            var value = motive.value.trim().slice(0, 255);
            if ((sheetRow.getAttribute('data-motive') || '') === value) { return; }
            sheetRow.setAttribute('data-motive', value);
            setDirty(true);
            saveDraft();
        }

        /* Al enfocar el motivo sube el teclado y se come media pantalla. La hoja
           tiene `touch-action: none` —el arrastre vertical es nuestro— así que
           el dedo NO puede desplazarla para alcanzar el campo: hay que hacerlo
           por código. `scrollIntoView` sí funciona con touch-action puesto,
           porque eso solo se lleva los gestos, no el desplazamiento por script.
           Con un retardo pequeño porque el teclado tarda en aparecer y antes de
           que esté la ventana todavía mide lo de antes. */
        if (motive) {
            motive.addEventListener('focus', function () {
                setTimeout(function () {
                    try {
                        motive.scrollIntoView({ block: 'center', behavior: REDUCED ? 'auto' : 'smooth' });
                    } catch (e) {
                        motive.scrollIntoView(false);
                    }
                }, 250);
            });
            // Enter cierra la hoja: es lo que espera quien acaba de escribir el
            // motivo, y así no hay que buscar dónde tocar para salir. La hoja se
            // pinta FUERA del <form> de guardar a propósito, así que aquí Enter
            // no puede enviar la lista por accidente.
            motive.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    closeSheet(0);
                }
            });
        }

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

            /* La salida a la ficha: la dirección la tiene la flecha de la fila,
               que es hermana del botón. Si esa fila no la lleva (la lista de
               monitores no la pinta), el enlace no se enseña en vez de llevar
               a ninguna parte. */
            var ficha = sheet.querySelector('[data-pl-sheet-ficha]');
            if (ficha) {
                var detalle = row.parentNode && row.parentNode.querySelector
                    ? row.parentNode.querySelector('[data-pl-detail]')
                    : null;
                var href = detalle ? detalle.getAttribute('href') : '';
                if (href) {
                    ficha.setAttribute('href', href);
                    ficha.hidden = false;
                } else {
                    ficha.hidden = true;
                }
            }

            // El motivo que ya tuviera, y el estado en la hoja para que el CSS
            // sepa si el campo pinta algo (con "sin marcar" no pinta nada).
            if (motive) { motive.value = row.getAttribute('data-motive') || ''; }
            sheet.setAttribute('data-state', current);

            // La hoja tiene que estar visible para poder medirla, y la medida se
            // toma ANTES de escribir el transform: leer y escribir en el mismo
            // cuadro es lo que produce el tirón del primer fotograma.
            sheet.classList.add('is-open');
            if (veil) { veil.classList.add('is-open'); }
            sheet.setAttribute('aria-hidden', 'false');
            sheetHeight = sheet.offsetHeight || 320;

            renderSheet(sheetHeight);
            springTo(0, 0);

            // El háptico va en el mismo cuadro que el movimiento: si llega tarde,
            // se percibe como dos cosas distintas en vez de una.
            haptic(12);

            // El chip de "mantén pulsado" deja de latir en cuanto se descubre el
            // gesto: un aviso que ya no hace falta es ruido.
            lsSet(STORE_HINT, '1');
            document.body.classList.add('pl-hold-seen');
        }

        function closeSheet(velocity) {
            if (!sheet || !sheet.classList.contains('is-open')) { return; }
            commitMotive();
            // El teclado del móvil se va con la hoja: si se queda abierto tapa
            // media lista y hay que tocar fuera para quitarlo.
            if (motive) { motive.blur(); }
            springTo(sheetHeight, velocity || 0, function () {
                sheet.classList.remove('is-open');
                if (veil) { veil.classList.remove('is-open'); veil.style.opacity = ''; }
                sheet.setAttribute('aria-hidden', 'true');
                sheet.style.transform = '';
                sheetRow = null;
            });
        }

        if (sheet) {
            /* Arrastrar la hoja. Lo importante no es que se pueda cerrar de un
               gesto (eso ya lo hace el velo), es que se pueda AGARRAR mientras
               se mueve: si el muelle está corriendo y pones el dedo, se para
               donde está y sigue tu dedo desde ahí. Sin eso, la animación manda
               sobre el usuario, que es justo lo contrario de lo que queremos. */
            sheet.addEventListener('pointerdown', function (ev) {
                // Los botones de la hoja no arrastran: pulsar "Vino" es pulsar.
                // Y el campo del motivo tampoco: escribir en él es escribir, y
                // arrastrar la hoja al mover el cursor sería intolerable.
                if (ev.target.closest && ev.target.closest('button, input, label')) { return; }
                if (ev.button && ev.button !== 0) { return; }

                var live = sheetSpring ? sheetSpring.stop() : { value: sheetY, velocity: 0 };
                sheetSpring = null;
                sheet.setPointerCapture(ev.pointerId);

                drag = {
                    // El desplazamiento respecto a DONDE se ha agarrado: si se
                    // salta al centro, la ilusión se rompe en el primer píxel.
                    grabY: ev.clientY,
                    startValue: live.value,
                    history: [{ y: ev.clientY, t: performance.now() }]
                };
                sheet.classList.add('is-dragging');
            });

            sheet.addEventListener('pointermove', function (ev) {
                if (!drag) { return; }
                var raw = drag.startValue + (ev.clientY - drag.grabY);

                // Hacia arriba no hay nada: en vez de tope seco, resistencia
                // progresiva, que es cómo se comportan las cosas de verdad.
                var y = (raw < 0) ? rubberband(raw, sheetHeight) : raw;
                renderSheet(y);

                drag.history.push({ y: ev.clientY, t: performance.now() });
                if (drag.history.length > 6) { drag.history.shift(); }
            });

            function endDrag(ev) {
                if (!drag) { return; }
                sheet.classList.remove('is-dragging');
                sheet.releasePointerCapture && sheet.releasePointerCapture(ev.pointerId);

                // Velocidad de los últimos milímetros, no del gesto entero: es
                // la que tenía el dedo al soltar.
                var h = drag.history;
                var first = h[0];
                var last = h[h.length - 1];
                var dt = Math.max(last.t - first.t, 1);
                var velocity = ((last.y - first.y) / dt) * 1000;   // px/s

                drag = null;

                // A dónde IBA el gesto, no dónde ha acabado. Un empujón corto y
                // rápido cierra; un arrastre largo y lento que se queda a medias,
                // vuelve. Decidir por posición ignoraría el empujón.
                var projected = sheetY + project(velocity);
                if (projected > sheetHeight * 0.4) {
                    closeSheet(velocity);
                } else {
                    springTo(0, velocity);
                }
            }

            sheet.addEventListener('pointerup', endDrag);
            sheet.addEventListener('pointercancel', endDrag);

            Array.prototype.forEach.call(sheet.querySelectorAll('.pl-opt'), function (opt) {
                opt.addEventListener('click', function () {
                    if (sheetRow) {
                        // El motivo se recoge antes de cerrar: si se ha escrito
                        // y luego se toca un estado, no se pierde lo escrito.
                        commitMotive();
                        setState(sheetRow, opt.getAttribute('data-value') || '');
                    }
                    haptic(8);
                    closeSheet(0);
                });
            });
            var clear = sheet.querySelector('[data-pl-sheet-clear]');
            if (clear) {
                clear.addEventListener('click', function () {
                    if (sheetRow) {
                        // Quitar la marca se lleva el motivo: un motivo sin
                        // estado no significa nada y quedaría escrito a solas.
                        sheetRow.setAttribute('data-motive', '');
                        if (motive) { motive.value = ''; }
                        setState(sheetRow, '');
                    }
                    closeSheet(0);
                });
            }
        }

        if (veil) {
            veil.addEventListener('click', function () { closeSheet(0); });
        }
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') { closeSheet(0); }
        });

        /* ---- Guardar --------------------------------------------------- */

        /* Toda la lista en una petición. Los estados viajan en un campo oculto
           como JSON en vez de un input por fila: así el guardado es uno, y sin
           cobertura ese mismo JSON es lo que se guarda en la cola. */
        if (form) {
            // Qué botón ha enviado el formulario. `ev.submitter` es lo correcto,
            // pero si el navegador no lo trae, sin esto "Sin registro" se
            // encolaría sin cobertura como si fuera un guardado normal.
            var lastSubmitter = null;
            var actionInput = root.querySelector('[data-pl-action]');
            var sending = false;
            form.addEventListener('click', function (ev) {
                var btn = ev.target.closest && ev.target.closest('button[type="submit"]');
                if (btn) { lastSubmitter = btn; }
            }, true);

            form.addEventListener('submit', function (ev) {
                var marks = JSON.stringify(collect());
                var notes = JSON.stringify(collectNotes());
                if (marksInput) { marksInput.value = marks; }
                if (notesInput) { notesInput.value = notes; }

                var submitter = ev.submitter || lastSubmitter;
                var action = (submitter && submitter.getAttribute('value')) || 'save';
                /* La acción, al campo oculto. Los botones la llevan en su
                   `value`, pero un botón deshabilitado NO se serializa: si se
                   deshabilita durante este mismo manejador —lo que hacíamos
                   más abajo— la orden de guardar no sale del navegador y el
                   servidor no guarda nada. Era EL bug: «pasar lista» no pasaba
                   lista desde el primer día. */
                if (actionInput) { actionInput.value = action; }

                if (!navigator.onLine) {
                    // Sin cobertura no se intenta y se falla: se guarda y se
                    // dice. El monitor se va a casa con la lista puesta.
                    ev.preventDefault();
                    var nonceEl = form.querySelector('input[name="pl_nonce"]');
                    queuePush({
                        url: window.location.href,
                        session: sessionId,
                        group: groupId,
                        action: action,
                        nonce: nonceEl ? nonceEl.value : '',
                        marks: marks,
                        notes: notes,
                        ts: Date.now()
                    });
                    saveDraft();
                    // Ya está guardado (en el móvil), así que el aviso de salir
                    // sin guardar sería mentira: un aviso que miente enseña a
                    // ignorar todos los avisos.
                    setDirty(false);
                    say('offline', root.getAttribute('data-msg-queued') || '');
                    haptic(20);
                    return;
                }

                // Doble toque: el segundo envío no se manda (el primero ya va
                // de camino y navega).
                if (sending) {
                    ev.preventDefault();
                    return;
                }
                sending = true;

                // Con cobertura sí navega: el overlay de carga lo pone
                // js/stic-ui.js por la clase stic-loading-form del formulario.
                setDirty(false);
                // EL BORRADOR NO SE TIRA AQUÍ. Se tiraba al enviar, antes de
                // saber si el CRM lo había aceptado: si fallaba, el monitor
                // perdía las marcas y encima creía que estaban guardadas. Ahora
                // lo tira el arranque de la página siguiente, y solo si el
                // servidor confirma el guardado (`data-pl-saved-ok`).
                if (saveBtn) {
                    saveBtn.textContent = saveBtn.getAttribute('data-label-saving') || 'Guardando…';
                    // Deshabilitar SÍ, pero en el siguiente tic: el navegador
                    // construye los datos del formulario después de este
                    // manejador, y un control deshabilitado se queda fuera.
                    setTimeout(function () { saveBtn.disabled = true; }, 0);
                }
            });
        }

        // Salir con marcas sin guardar avisa. El borrador ya las protege, pero
        // el aviso es lo que evita que alguien crea que ha guardado.
        window.addEventListener('beforeunload', function (ev) {
            if (!dirty) { return; }
            ev.preventDefault();
            ev.returnValue = '';
        });

        /* ---- Cobertura ------------------------------------------------- */

        function showConnectivity() {
            if (!navigator.onLine) {
                say('offline', root.getAttribute('data-msg-offline') || '');
                return;
            }
            var pending = queueRead().length;
            if (pending > 0) {
                say('sync', root.getAttribute('data-msg-sync') || '');
                queueFlush(function (sent) {
                    if (sent > 0) {
                        say('ok', root.getAttribute('data-msg-sent') || '');
                        lsDel(draftKey);
                    } else if (queueStuck()) {
                        // Ni con cobertura entra: casi siempre el nonce caducado
                        // de una pantalla vieja. Callarlo dejaría al monitor
                        // creyendo que su lista se envió sola.
                        say('offline', root.getAttribute('data-msg-stuck')
                            || 'No se ha podido enviar lo que quedó pendiente. Vuelve a marcar y guardar.');
                    }
                });
            } else {
                hush();
            }
        }

        window.addEventListener('online', showConnectivity);
        window.addEventListener('offline', showConnectivity);

        /* ---- Arranque -------------------------------------------------- */

        if (lsGet(STORE_HINT)) { document.body.classList.add('pl-hold-seen'); }
        // El servidor ha confirmado, releyendo el CRM, que esto está guardado:
        // ahora sí se puede tirar el borrador. Va ANTES de restoreDraft() para
        // que no reviva las marcas que ya están en el CRM.
        if (root.hasAttribute('data-pl-saved-ok')) { lsDel(draftKey); }
        restoreDraft();
        showConnectivity();
        refresh();
    }

    /* =====================================================================
     * Selector de sesión: el <select> nativo navega al elegir
     * ---------------------------------------------------------------------
     * El valor de cada opción ES la URL de esa sesión, así que cambiar el
     * desplegable es ir allí. Sin JS sigue siendo un <select> dentro de la
     * página y no se pierde nada grave: se ve cuál está puesta, solo que no
     * navega. Por eso no hay un botón "ir" al lado — sería un toque más en el
     * caso normal para cubrir un caso que casi no existe en una webview.
     * ===================================================================== */

    Array.prototype.forEach.call(document.querySelectorAll('[data-pl-session-jump]'), function (sel) {
        sel.addEventListener('change', function () {
            var url = sel.value;
            if (!url) { return; }
            // El desplegable se queda en lo elegido mientras carga: volver al
            // valor anterior durante la espera se lee como "no me ha hecho caso".
            sel.disabled = true;
            window.location.href = url;
        });
    });

    /* =====================================================================
     * Ficha: el cambio de pañuelo
     * ---------------------------------------------------------------------
     * Dos toques y una confirmación. El pañuelo dice quién puede hacer qué en
     * una actividad, así que cambiarlo por un roce no puede ser posible; pero
     * tampoco puede costar entrar en el CRM, porque quien lo sabe es el monitor.
     *
     * Sin JS el formulario sigue funcionando: las opciones están en el HTML y
     * solo están ocultas, así que el peor caso es verlas todas desde el inicio.
     * ===================================================================== */

    var panuelo = document.querySelector('[data-pl-panuelo]');
    if (panuelo) {
        var opts = panuelo.querySelector('[data-pl-panuelo-opts]');
        var edit = panuelo.querySelector('[data-pl-panuelo-edit]');

        if (edit && opts) {
            edit.setAttribute('aria-expanded', 'false');
            edit.addEventListener('click', function () {
                var open = opts.hidden;
                opts.hidden = !open;
                edit.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }
    }

    /* La confirmación es de DOCUMENTO y no de cada formulario: la usan el
       pañuelo y los avisos, y dos manejadores iguales acaban siendo dos
       comportamientos distintos en cuanto alguien toca uno. */
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest && ev.target.closest('[data-pl-confirm]');
        if (!btn) { return; }
        if (!window.confirm(btn.getAttribute('data-pl-confirm'))) {
            ev.preventDefault();
        }
    });

    /* =====================================================================
     * Copiar un dato de un toque
     * ---------------------------------------------------------------------
     * El correo de un monitor se copia para pegarlo en otro sitio, y hasta
     * ahora eso era seleccionar un texto con el dedo dentro de una webview,
     * que es de las cosas más incómodas que hay en un móvil.
     *
     * El valor va en el `data-pl-copy` y NO se lee del DOM: en pantalla está
     * recortado con puntos suspensivos y se copiaría a medias.
     *
     * `navigator.clipboard` necesita contexto seguro; si no está, se cae al
     * `<textarea>` + `execCommand`, que sigue funcionando en las webviews
     * viejas. Si tampoco, no se dice que se ha copiado.
     * ===================================================================== */
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest && ev.target.closest('[data-pl-copy]');
        if (!btn) { return; }
        var text = btn.getAttribute('data-pl-copy') || '';
        if (!text) { return; }

        // Las dos etiquetas vienen del servidor en el propio botón: aquí no
        // hay puente de traducción, y una cadena en castellano metida a mano
        // en el JS se queda sin traducir para siempre.
        var etiqueta = btn.getAttribute('aria-label') || '';
        var hecho = btn.getAttribute('data-pl-copied') || etiqueta;

        var done = function () {
            btn.classList.add('is-done');
            // El acuse de recibo también en voz alta: sin esto, quien navega
            // con lector de pantalla no se entera de que ha pasado nada.
            btn.setAttribute('aria-label', hecho);
            window.setTimeout(function () {
                btn.classList.remove('is-done');
                btn.setAttribute('aria-label', etiqueta);
            }, 1600);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done, function () { legacyCopy(text, done); });
        } else {
            legacyCopy(text, done);
        }
    });

    function legacyCopy(text, done) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        // Fuera de la vista pero enfocable: `display:none` no se puede
        // seleccionar y `execCommand` no copiaría nada.
        ta.style.position = 'fixed';
        ta.style.top = '-1000px';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(ta);
        if (ok) { done(); }
    }

    /* =====================================================================
     * Ficha: poner un aviso de comportamiento
     * ---------------------------------------------------------------------
     * El formulario sale oculto y lo abre el botón, igual que el pañuelo:
     * poner un aviso no puede ser un roce. Sin JS se ve el formulario desde
     * el principio, que es el peor caso aceptable — se puede usar.
     * ===================================================================== */

    /* El alta de un seguimiento, detrás de un botón (ficha del monitor).
       Al abrir, el foco va al PRIMER campo —el desplegable del tipo—, no al
       texto: así se puede escribir la nota entera con el teclado sin volver a
       tocar la pantalla, y en móvil no salta el teclado antes de tiempo. */
    var segAdd = document.querySelector('[data-pl-seg-add]');
    var segForm = document.querySelector('[data-pl-seg-form]');
    if (segAdd && segForm) {
        segAdd.setAttribute('aria-expanded', 'false');
        segAdd.addEventListener('click', function () {
            var abrir = segForm.hidden;
            segForm.hidden = !abrir;
            segAdd.setAttribute('aria-expanded', abrir ? 'true' : 'false');
            if (abrir) {
                var first = segForm.querySelector('[data-pl-seg-first]');
                if (first) { first.focus(); }
                if (segForm.scrollIntoView) {
                    segForm.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            }
        });
    }

    var avisoAdd = document.querySelector('[data-pl-aviso-add]');
    var avisoForm = document.querySelector('[data-pl-aviso-form]');
    if (avisoAdd && avisoForm) {
        avisoAdd.setAttribute('aria-expanded', 'false');
        avisoAdd.addEventListener('click', function () {
            var open = avisoForm.hidden;
            avisoForm.hidden = !open;
            avisoAdd.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                // El foco va al motivo: es el único campo obligatorio y el
                // único por el que se abre esto.
                var ta = avisoForm.querySelector('textarea');
                if (ta) { ta.focus(); }
            }
        });
    }

    /* =====================================================================
     * El buscador: árbol de grupos y «Mis grupos»
     * ---------------------------------------------------------------------
     * Filtra lo que YA está pintado: ni una consulta, ni una recarga. Con
     * veintiocho grupos, encontrar el tuyo era leer la lista entera; con
     * trescientos chavales en la vista A-Z, más todavía.
     *
     * Se busca sobre el texto de la fila entera (código, nombre, curso,
     * monitores; o nombre, grupo y edad en las filas de personas), sin acentos
     * y sin mayúsculas, porque nadie escribe «Emaús» con tilde en un buscador.
     * Las cabeceras se esconden cuando se quedan sin filas: una cabecera «COM»
     * sola es peor que nada.
     * ===================================================================== */

    // Lo filtrable, en un sitio: tarjetas de grupo (árbol y «Mis grupos») y
    // filas de persona (`sticpa_pl_person_link_html`). Las filas de MARCAR no
    // entran: allí no hay buscador, y esconder una fila de una lista que se está
    // guardando es pedir un lío.
    var PL_FILTRABLE = '.pl-list .pl-group, .pl-mine, .pl-list .pl-rowlink';

    var filterInput = document.querySelector('[data-pl-filter]');
    if (filterInput) {
        var emptyMsg = document.querySelector('[data-pl-filter-empty]');

        // Se guardan las filas y su texto normalizado UNA vez: normalizar en
        // cada pulsación es trabajo repetido sobre algo que no cambia.
        var rows = [];
        Array.prototype.forEach.call(
            document.querySelectorAll(PL_FILTRABLE),
            function (el) { rows.push({ el: el, hay: norm(el.textContent) }); }
        );

        function norm(str) {
            str = String(str).toLowerCase();
            // Quita los diacríticos: «Emaús» encuentra «emaus» y al revés.
            if (str.normalize) {
                str = str.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            }
            return str.replace(/\s+/g, ' ').trim();
        }

        function apply() {
            var q = norm(filterInput.value);
            var shown = 0;
            rows.forEach(function (row) {
                var hit = (q === '' || row.hay.indexOf(q) !== -1);
                // `hidden` y no display: así el CSS de la fila no compite.
                row.el.hidden = !hit;
                if (hit) { shown++; }
            });

            // Cabeceras y sus listas: fuera si no queda nada dentro. Vale
            // igual para la cabecera de etapa del árbol y para el «Monitores» /
            // «Participantes» de la ficha de un grupo.
            Array.prototype.forEach.call(
                document.querySelectorAll('.pl-list'),
                function (list) {
                    var any = !!list.querySelector('.pl-group:not([hidden]), .pl-rowlink:not([hidden])');
                    list.hidden = !any;
                    // El título va justo ANTES de la lista en el HTML.
                    var title = list.previousElementSibling;
                    if (title && (title.classList.contains('pl-etapa-title')
                        || title.classList.contains('pl-sec'))) {
                        title.hidden = !any;
                    }
                }
            );

            if (emptyMsg) { emptyMsg.hidden = (shown > 0); }
        }

        filterInput.addEventListener('input', apply);
        // Escape limpia y devuelve la lista entera, que es lo que se espera de
        // un campo de búsqueda en cualquier sitio.
        filterInput.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && filterInput.value !== '') {
                ev.preventDefault();
                filterInput.value = '';
                apply();
            }
        });
    }
}());
