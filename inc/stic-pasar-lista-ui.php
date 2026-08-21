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
function sticpa_pl_row_html($person, $state, $streak = 0, $fichaUrl = '')
{
    $states = sticpa_pl_states();
    $state = sticpa_pl_is_state($state) ? $state : '';

    // La nota bajo el nombre: el estado del gesto largo (que si no es invisible)
    // y el aviso de ausencias seguidas, que solo sale si son SEGUIDAS.
    $notes = array();
    $noteClass = '';
    if ($state === 'partial' || $state === 'no_justified') {
        $notes[] = $states[$state]['label'];
        $noteClass = 'style="color:' . esc_attr($states[$state]['ink']) . '"';
    }
    if ($streak >= sticpa_pl_streak_threshold()) {
        $notes[] = sprintf(
            /* translators: %d: número de ausencias consecutivas */
            _n('%d ausencia seguida', '%d ausencias seguidas', $streak, 'sticpa'),
            $streak
        );
        $noteClass = 'style="color:var(--danger-dark)"';
    }
    $note = implode(' · ', $notes);

    $html = '<button type="button" class="pl-row" data-state="' . esc_attr($state) . '"'
        . ' data-contact="' . esc_attr($person['id']) . '"'
        . ' data-name="' . esc_attr($person['name']) . '"'
        . ' data-initials="' . esc_attr($person['initials']) . '"'
        . ' data-label-partial="' . esc_attr($states['partial']['label']) . '"'
        . ' data-label-no_justified="' . esc_attr($states['no_justified']['label']) . '"'
        . ' aria-label="' . esc_attr($person['name']) . '">';

    $html .= '<span class="pl-avatar">' . esc_html($person['initials']) . '</span>';
    $html .= '<span class="pl-row-body">';
    $html .= '<span class="pl-name">' . esc_html($person['name']) . '</span>';
    $html .= '<span class="pl-note" data-pl-state-note ' . $noteClass
        . ($note === '' ? ' hidden' : '') . '>' . esc_html($note) . '</span>';
    $html .= '</span>';

    $html .= '<span class="pl-mark">' . sticpa_pl_glyphs() . '</span>';
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
    $html .= '<div class="pl-sheet-grip" aria-hidden="true"></div>';

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

    $html .= '<button type="button" class="pl-sheet-clear" data-pl-sheet-clear>'
        . esc_html__('Quitar la marca', 'sticpa') . '</button>';
    $html .= '</div>';

    return $html;
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

/** Versión corta para la pastilla del selector: "sáb 15". */
function sticpa_pl_session_short($session)
{
    if (empty($session['start'])) {
        return '';
    }
    return date_i18n('D j', (int) $session['start']);
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
