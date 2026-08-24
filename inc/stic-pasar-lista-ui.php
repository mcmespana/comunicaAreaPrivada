<?php
/**
 * PASAR LISTA — piezas de HTML reutilizadas por las pantallas.
 * ----------------------------------------------------------------------------
 * Los glifos, la fila de la lista, la leyenda y la hoja de estados. Están aquí
 * y no en las páginas para que el círculo verde de "vino" sea EL MISMO en la
 * lista, en la leyenda y en la hoja: si cada pantalla se pinta su propio SVG,
 * al mes hay tres checks distintos.
 *
 * Estilos: css/pasar-lista.css · Interacción: js/stic-pasar-lista.js
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Los cuatro glifos de estado, siempre los cuatro.
 *
 * Se pintan todos y el CSS enseña el del estado actual (`[data-state]`), en vez
 * de decidirlo en PHP: así el JS cambia un atributo y ya está, sin tener que
 * reconstruir HTML al marcar.
 */
function sticpa_pl_glyphs()
{
    return '<svg class="pl-glyph-check" viewBox="0 0 24 24" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>'
        . '<svg class="pl-glyph-half" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 1 0 20Z"/></svg>'
        . '<svg class="pl-glyph-dash" viewBox="0 0 24 24" stroke-width="3.2" stroke-linecap="round" aria-hidden="true"><path d="M6 12h12"/></svg>'
        . '<svg class="pl-glyph-cross" viewBox="0 0 24 24" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
}

/** Un glifo suelto, para la leyenda y la hoja. */
function sticpa_pl_glyph($which)
{
    switch ($which) {
        case 'check':
            return '<svg class="pl-glyph-check" viewBox="0 0 24 24" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>';
        case 'half':
            return '<svg class="pl-glyph-half" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 0 1 0 20Z"/></svg>';
        case 'dash':
            return '<svg class="pl-glyph-dash" viewBox="0 0 24 24" stroke-width="3.6" stroke-linecap="round" aria-hidden="true"><path d="M6 12h12"/></svg>';
        case 'cross':
            return '<svg class="pl-glyph-cross" viewBox="0 0 24 24" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>';
    }
    return '';
}

/** Iconos sueltos de la pantalla (chevron, reloj, info…). */
function sticpa_pl_icon($which)
{
    $icons = array(
        'back' => '<path d="m15 18-6-6 6-6"/>',
        'next' => '<path d="m9 18 6-6-6-6"/>',
        'down' => '<path d="m6 9 6 6 6-6"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'skip' => '<path d="m5 4 10 8-10 8V4Z"/><path d="M19 5v14"/>',
        'refresh' => '<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/>',
        // La lupa del buscador del árbol de grupos.
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        // El triángulo de aviso: lo que reclama algo, no lo que informa. Se usa
        // en el título de las listas pendientes y en nada decorativo.
        'warn' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'pencil' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
    );
    if (!isset($icons[$which])) {
        return '';
    }
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $icons[$which] . '</svg>';
}

/**
 * Una fila de la lista de marcado.
 *
 * `$person` viene de sticpa_pl_group_people(); `$state` es la clave del CRM o ''.
 * Es un <button> y no un <div> con onclick: así lo alcanza el teclado y lo
 * anuncia el lector de pantalla sin tener que añadir roles a mano.
 */
function sticpa_pl_row_html($person, $state, $streak = 0, $fichaUrl = '', $sub = '', $motive = '')
{
    $states = sticpa_pl_states();
    $state = sticpa_pl_is_state($state) ? $state : '';

    $warn = '';
    if ($streak >= sticpa_pl_streak_threshold()) {
        $warn = sprintf(
            /* translators: %d: número de ausencias consecutivas */
            _n('%d ausencia seguida', '%d ausencias seguidas', $streak, 'sticpa'),
            $streak
        );
    }

    // La nota bajo el nombre: el estado del gesto largo (que si no, es invisible)
    // y el aviso de ausencias seguidas, que solo sale si son SEGUIDAS. El JS la
    // recompone al marcar, y por eso el aviso viaja también en `data-warn`.
    $notes = array();
    $noteClass = '';
    if ($state === 'partial' || $state === 'no_justified') {
        $notes[] = $states[$state]['label'];
        $noteClass = 'style="color:' . esc_attr($states[$state]['ink']) . '"';
    }
    if ($warn !== '') {
        $notes[] = $warn;
        $noteClass = 'style="color:var(--danger-dark)"';
    }
    $note = implode(' · ', $notes);

    $html = '<button type="button" class="pl-row" data-state="' . esc_attr($state) . '"'
        . ' data-contact="' . esc_attr($person['id']) . '"'
        . ' data-warn="' . esc_attr($warn) . '"'
        . ' data-motive="' . esc_attr($motive) . '"'
        . ' data-name="' . esc_attr($person['name']) . '"'
        . ' data-initials="' . esc_attr($person['initials']) . '"'
        . ' data-label-partial="' . esc_attr($states['partial']['label']) . '"'
        . ' data-label-no_justified="' . esc_attr($states['no_justified']['label']) . '"'
        . ' aria-label="' . esc_attr($person['name']) . '">';

    $html .= '<span class="pl-avatar">' . esc_html($person['initials']) . '</span>';
    $html .= '<span class="pl-row-body">';
    $html .= '<span class="pl-name">' . esc_html($person['name']) . '</span>';
    // Línea fija: los grupos de un monitor. Es lo que distingue a dos personas
    // con el mismo nombre de pila y lo que explica por qué están en esta lista.
    if ($sub !== '') {
        $html .= '<span class="pl-rowsub">' . esc_html($sub) . '</span>';
    }
    $html .= '<span class="pl-note" data-pl-state-note ' . $noteClass
        . ($note === '' ? ' hidden' : '') . '>' . esc_html($note) . '</span>';
    $html .= '</span>';

    // El anillo del gesto largo va DENTRO del círculo, y se dibuja siempre: lo
    // enseña y lo llena el CSS cuando la fila está en `is-holding`. Pintarlo
    // desde el principio evita insertar nodos en medio de un gesto.
    $html .= '<span class="pl-mark">' . sticpa_pl_glyphs()
        . '<svg class="pl-hold-ring-svg" viewBox="0 0 44 44" aria-hidden="true">'
        . '<circle cx="22" cy="22" r="20"/></svg>'
        . '</span>';
    $html .= '</button>';

    // La flecha va FUERA del botón de marcar: dos controles anidados no se
    // pueden separar con el teclado, y aquí son dos acciones distintas.
    if ($fichaUrl !== '') {
        $html .= '<a class="pl-detail" data-pl-detail href="' . esc_url($fichaUrl) . '"'
            . ' aria-label="' . esc_attr(sprintf(
                /* translators: %s: nombre del participante */
                __('Ver la ficha de %s', 'sticpa'),
                $person['name']
            )) . '">' . sticpa_pl_icon('next') . '</a>';
    }

    return '<div class="pl-rowwrap">' . $html . '</div>';
}

/**
 * La leyenda de los cuatro círculos, más el chip del gesto largo.
 *
 * Va debajo de la lista y no en cada fila: el color y el glifo se aprenden una
 * vez y así la lista queda limpia. El chip de "mantén pulsado" es obligatorio,
 * no decorativo: sin él, parcial y justificada no existen para el usuario.
 */
function sticpa_pl_legend_html()
{
    $states = sticpa_pl_states();
    $order = array('yes' => 'yes', 'partial' => 'partial', 'just' => 'no_justified', 'no' => 'no_unjustified');

    $html = '<div class="pl-legend">';
    foreach ($order as $css => $key) {
        $html .= '<span class="pl-legend-item">'
            . '<span class="pl-legend-dot pl-legend-dot--' . esc_attr($css) . '">'
            . sticpa_pl_glyph($states[$key]['glyph']) . '</span>'
            . '<span class="pl-legend-label">' . esc_html($states[$key]['label']) . '</span>'
            . '</span>';
    }
    $html .= '<span class="pl-hold-hint"><span class="pl-hold-ring" aria-hidden="true"></span>'
        . esc_html__('Mantén pulsado', 'sticpa') . '</span>';
    $html .= '</div>';

    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . sprintf(
            /* translators: %s: "vino / no vino" en negrita */
            esc_html__('Toca la fila para %s. Mantén pulsado para parcial o justificar.', 'sticpa'),
            '<strong>' . esc_html__('vino / no vino', 'sticpa') . '</strong>'
        )
        . '</span></p>';

    return $html;
}

/**
 * La hoja inferior con los cuatro estados.
 *
 * Es lo que abre el gesto largo. Se pinta una sola vez por pantalla y el JS le
 * cambia el nombre y la marca: no hace falta una hoja por participante.
 */
function sticpa_pl_sheet_html($whenLabel = '')
{
    $states = sticpa_pl_states();
    $descs = array(
        'partial' => __('Cuenta como asistencia', 'sticpa'),
        'no_justified' => __('No es necesario justificar, pero a veces avisan', 'sticpa'),
    );

    $html = '<div class="pl-sheet-veil" data-pl-veil></div>';
    $html .= '<div class="pl-sheet" data-pl-sheet role="dialog" aria-modal="true" aria-hidden="true"'
        . ' aria-label="' . esc_attr__('Estado de asistencia', 'sticpa') . '">';
    // El agarre no es solo decoración: es la zona por la que se arrastra, así que
    // su área táctil es alta aunque la rayita se vea fina.
    $html .= '<div class="pl-sheet-griparea" aria-hidden="true"><span class="pl-sheet-grip"></span></div>';

    $html .= '<div class="pl-sheet-who">'
        . '<span class="pl-avatar" data-pl-sheet-initials></span>'
        . '<span><span class="pl-sheet-name" data-pl-sheet-name></span><br>'
        . '<span class="pl-sheet-when">' . esc_html($whenLabel) . '</span></span>'
        . '</div>';

    foreach (array('yes', 'partial', 'no_justified', 'no_unjustified') as $key) {
        $html .= '<button type="button" class="pl-opt" role="radio" aria-checked="false"'
            . ' data-value="' . esc_attr($key) . '">'
            . '<span class="pl-opt-dot">' . sticpa_pl_glyph($states[$key]['glyph']) . '</span>'
            . '<span class="pl-opt-body">'
            . '<span class="pl-opt-label">' . esc_html($states[$key]['label']) . '</span>'
            . (isset($descs[$key]) ? '<span class="pl-opt-desc">' . esc_html($descs[$key]) . '</span>' : '')
            . '</span>'
            . '<span class="pl-opt-check">' . sticpa_pl_glyph('check') . '</span>'
            . '</button>';
    }

    /* El motivo, opcional. Va al campo `description` de la asistencia, que es
     * donde el CRM lo espera y donde se puede leer luego desde el propio CRM.
     * Aquí abajo y no arriba: primero se dice QUÉ pasó (los cuatro estados) y
     * solo después, si hace falta, POR QUÉ. Sin estado no se pinta (lo oculta
     * el CSS): un motivo sin marca no significa nada. */
    $html .= '<label class="pl-motive">'
        . sticpa_pl_icon('pencil')
        . '<input type="text" data-pl-sheet-motive maxlength="255" autocomplete="off"'
        . ' placeholder="' . esc_attr__('Añadir un motivo (opcional)', 'sticpa') . '"'
        . ' aria-label="' . esc_attr__('Motivo de la ausencia', 'sticpa') . '">'
        . '</label>';

    $html .= '<button type="button" class="pl-sheet-clear" data-pl-sheet-clear>'
        . esc_html__('Quitar la marca', 'sticpa') . '</button>';
    $html .= '</div>';

    return $html;
}

/**
 * La cápsula de fecha del área privada: día grande y mes debajo.
 *
 * Es el componente de `docs/design-system.md` §11 (`.stic-cell-badge`), el
 * mismo que llevan los listados con `$listSettings['cardDate']`. Se reutiliza
 * tal cual en vez de pintar otra cápsula: si cada pantalla se hace la suya, al
 * mes hay tres formas distintas de decir "15 de noviembre".
 *
 * `$class` permite revestirla para el sitio donde va (sobre el degradado del
 * atajo, por ejemplo, donde la marca sobre la marca no se leería).
 */
function sticpa_pl_date_capsule($ts, $class = '')
{
    $ts = (int) $ts;
    if ($ts <= 0) {
        return '';
    }
    return '<span class="stic-cell-badge' . ($class !== '' ? ' ' . esc_attr($class) : '') . '" aria-hidden="true">'
        . '<span class="stic-cell-badge-day">' . esc_html(date_i18n('j', $ts)) . '</span>'
        . '<span class="stic-cell-badge-mon">' . esc_html(date_i18n('M', $ts)) . '</span>'
        . '</span>';
}

/**
 * El CUÁNDO en relativo, para la pastilla del atajo: "Hoy", "Hace 3 días"…
 *
 * Va en relativo y no con la fecha porque la fecha ya está en la cápsula de al
 * lado. Lo que la pastilla contesta es otra pregunta —"¿esto es lo de hoy o me
 * he quedado atrás?"— y esa se contesta antes de leer el nombre del grupo.
 */
function sticpa_pl_when_pill($pick, $done = false)
{
    if ($done) {
        return __('Pasada', 'sticpa');
    }
    $why = (is_array($pick) && !empty($pick['why'])) ? $pick['why'] : '';
    switch ($why) {
        case 'recent':
            $days = isset($pick['days']) ? (int) $pick['days'] : 0;
            return sprintf(
                /* translators: %d: cuántos días hace de la sesión */
                _n('Hace %d día', 'Hace %d días', $days, 'sticpa'),
                $days
            );
        case 'future':
            return __('Próxima', 'sticpa');
        default:
            return __('Hoy', 'sticpa');
    }
}

/**
 * El selector de sesión: un <select> NATIVO dentro de la pastilla de siempre.
 *
 * En el móvil —que es el 99 % de los usos— el desplegable nativo es lo mejor
 * que hay: rueda a pulgar, se abre pegado al dedo y no cuesta ni una pantalla
 * ni una consulta más. Antes esto era un viaje a otra pantalla para volver con
 * una fecha, que en un sábado con prisa son cuatro toques de más.
 *
 * Cada opción lleva el NÚMERO de sesión delante de la fecha corta ("3 · 11 ago")
 * porque el número es como se habla de ellas ("la tercera") y la fecha es como
 * se comprueba que es la que toca. Las dos juntas caben de sobra.
 */
function sticpa_pl_session_select_html($sessions, $currentId, $groupId = '', $page = 'single_stic_pasar_lista_marcar')
{
    $groupId = (string) $groupId;
    $elapsed = sticpa_pl_elapsed_sessions($sessions);
    if (count($elapsed) < 2) {
        // Con una sola sesión celebrada no hay nada que elegir: la pastilla
        // diría "sáb 15" y al abrirla habría una sola línea. Se pinta el dato
        // sin control, que es más honesto que un desplegable de un elemento.
        $current = null;
        foreach ($sessions as $s) {
            if ($s['id'] === $currentId) {
                $current = $s;
                break;
            }
        }
        if ($current === null) {
            return '';
        }
        return '<span class="pl-session-pick"><span class="pl-session-pick-text">'
            . esc_html(sticpa_pl_session_short($current)) . '</span></span>';
    }

    // El número de sesión es su posición en el CURSO, contando desde la
    // primera: así "la 3" es la 3 para todo el mundo y no cambia según lo que
    // se esté enseñando en pantalla.
    $numbers = array();
    $n = 0;
    foreach ($sessions as $s) {
        $n++;
        $numbers[$s['id']] = $n;
    }

    // De la más reciente a la más antigua: se pasa lista de lo que acaba de
    // pasar, y lo más probable está arriba sin tener que desplazar.
    $elapsed = array_reverse($elapsed);
    $currentLabel = '';

    $out = '<span class="pl-session-pick pl-session-pick--select">';
    $out .= '<select data-pl-session-jump'
        . ' aria-label="' . esc_attr__('Elegir la sesión', 'sticpa') . '">';
    foreach ($elapsed as $s) {
        $num = isset($numbers[$s['id']]) ? $numbers[$s['id']] : 0;
        // "3 · 11 ago": número de sesión y fecha corta, que es como se nombra
        // una sesión al hablar y como se comprueba que es la buena.
        $label = ($num > 0 ? $num . ' · ' : '') . sticpa_pl_session_short($s, true);
        // Sin grupo (la lista de monitores) la url no lo lleva: es la misma
        // pregunta —"¿de qué día?"— pero de una pantalla que no tiene grupo.
        $url = '?internalpage=' . $page
            . (($groupId !== '') ? '&grupo=' . rawurlencode($groupId) : '')
            . '&sesion=' . rawurlencode($s['id']);
        $selected = ($s['id'] === $currentId);
        if ($selected) {
            $currentLabel = $label;
        }
        $out .= '<option value="' . esc_url($url) . '"' . ($selected ? ' selected' : '') . '>'
            . esc_html($label) . '</option>';
    }
    $out .= '</select>';
    // El texto visible es el de la pastilla de siempre; el <select> va encima,
    // transparente y a pantalla completa de la pastilla (ver el CSS).
    $out .= '<span class="pl-session-pick-text">' . esc_html($currentLabel) . '</span>';
    $out .= sticpa_pl_icon('down');
    $out .= '</span>';

    return $out;
}

/**
 * Cómo se lee una sesión en pantalla: "sábado 15 de noviembre · 16:30".
 *
 * Se formatea a partir de `start_date`, NUNCA del `name` de la sesión: el CRM
 * genera ese nombre al crearla y no lo refresca si luego cambian las fechas,
 * y además arrastra un desajuste de zona horaria.
 */
function sticpa_pl_session_label($session, $withTime = true)
{
    if (empty($session['start'])) {
        return '';
    }
    $ts = (int) $session['start'];
    $label = function_exists('wp_date')
        ? wp_date('l j \d\e F', $ts)
        : date_i18n('l j \d\e F', $ts);
    if ($withTime) {
        $label .= ' · ' . date_i18n('H:i', $ts);
    }
    return $label;
}

/**
 * Versión corta de una sesión.
 *
 * Con `$withMonth` sale "11 ago", que es lo que hace falta en el desplegable:
 * un curso pasa por varios meses y "sáb 11" a secas se repite cuatro veces.
 * Sin él sale "sáb 15", que es lo que cabe en la pastilla de la cabecera.
 */
function sticpa_pl_session_short($session, $withMonth = false)
{
    if (empty($session['start'])) {
        return '';
    }
    $ts = (int) $session['start'];
    return $withMonth ? date_i18n('j M', $ts) : date_i18n('D j', $ts);
}

/**
 * El aviso de por qué se propone esta sesión y no otra.
 *
 * Solo se pinta cuando hay algo que decir: durante la sesión no hay aviso,
 * porque es el caso normal y un aviso ahí sería ruido.
 */
function sticpa_pl_notice_html($pick)
{
    if (!is_array($pick) || empty($pick['why'])) {
        return '';
    }
    $session = isset($pick['session']) ? $pick['session'] : array();
    $time = !empty($session['start']) ? date_i18n('H:i', (int) $session['start']) : '';
    $msg = '';

    switch ($pick['why']) {
        case 'today_before':
            $msg = sprintf(
                /* translators: %s: hora de inicio */
                __('Empieza a las %s — aún no han llegado', 'sticpa'),
                '<strong>' . esc_html($time) . '</strong>'
            );
            break;
        case 'recent':
            $msg = sprintf(
                /* translators: %s: cuántos días hace */
                _n('Es la sesión de hace %d día', 'Es la sesión de hace %d días', (int) $pick['days'], 'sticpa'),
                (int) $pick['days']
            );
            break;
        case 'future':
            $msg = sprintf(
                /* translators: %s: fecha de la próxima sesión */
                __('La próxima sesión es el %s', 'sticpa'),
                esc_html(sticpa_pl_session_label($session, false))
            );
            break;
        case 'today_now':
        case 'today_after':
        default:
            return '';
    }

    return '<p class="pl-notice">' . sticpa_pl_icon('clock') . '<span>' . $msg . '</span></p>';
}
