<?php
/**
 * PASAR LISTA — reuniones de programación (coordinación).
 * ----------------------------------------------------------------------------
 * Tres o cuatro al año, y no siguen el calendario de los sábados. Por eso van a
 * un evento aparte y por eso **coordinación las crea desde aquí**: nombre, fecha
 * y duración, que es lo que se puede teclear de pie en cinco segundos. Entrar al
 * CRM para tres reuniones al año es lo que hace que no se registren.
 *
 * La lista de cada reunión es la de monitores, con la misma pantalla y la misma
 * regla (todos en verde, se marca quien no vino).
 *
 * Diseño: docs/comunica/PASAR-LISTA-COORDINACION.md §1
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageSettings['fileName'] = basename(__FILE__, ".php");

$scope = sticpa_pl_coord_scope($objSCP);
if ($scope === null) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('Esta pantalla es de coordinación.', 'sticpa') . '</span></p>';
    return;
}

// ---------------------------------------------------------------------------
// Crear una reunión
// ---------------------------------------------------------------------------

$createMsg = '';
if (!empty($_POST['pl_reunion_name'])) {
    if (!isset($_POST['pl_nonce']) || !wp_verify_nonce($_POST['pl_nonce'], 'pl_reuniones')) {
        $createMsg = __('La sesión ha caducado. Vuelve a cargar la pantalla.', 'sticpa');
    } else {
        $created = sticpa_pl_create_reunion(
            $objSCP,
            $_POST['pl_reunion_name'],
            isset($_POST['pl_reunion_date']) ? $_POST['pl_reunion_date'] : '',
            isset($_POST['pl_reunion_time']) ? $_POST['pl_reunion_time'] : '',
            isset($_POST['pl_reunion_hours']) ? $_POST['pl_reunion_hours'] : 1.5
        );
        $createMsg = ($created !== null)
            ? __('Reunión creada. Ya puedes pasar lista.', 'sticpa')
            : __('No se ha podido crear la reunión. Revisa la fecha.', 'sticpa');
    }
}

$event = sticpa_pl_reuniones_event($objSCP);
$sessions = ($event !== null) ? sticpa_pl_event_sessions($objSCP, $event['id']) : array();

// ---------------------------------------------------------------------------
// Cabecera
// ---------------------------------------------------------------------------

$course = sticpa_pl_course_for();

$html .= '<div class="pl-head">';
$html .= '<a class="pl-back" href="?internalpage=single_stic_pasar_lista"'
    . ' aria-label="' . esc_attr__('Volver', 'sticpa') . '">' . sticpa_pl_icon('back') . '</a>';
$html .= '<div class="pl-head-titles">';
$html .= '<div class="pl-title"><span class="pl-title-code">' . esc_html__('Reuniones', 'sticpa') . '</span></div>';
$html .= '<div class="pl-subtitle">' . esc_html($course['label']) . '</div>';
$html .= '</div>';
$html .= '</div>';

if ($createMsg !== '') {
    $html .= '<p class="pl-notice"><span>' . esc_html($createMsg) . '</span></p>';
}

// ---------------------------------------------------------------------------
// Las reuniones que hay
// ---------------------------------------------------------------------------

if (!empty($sessions)) {
    // De la más reciente a la más antigua: la de la semana pasada es la que se
    // busca, no la de octubre.
    $ordered = array_reverse($sessions);
    $html .= '<div class="pl-list">';
    foreach ($ordered as $s) {
        $past = ((int) $s['start'] <= sticpa_pl_now());
        $hours = (!empty($s['end']) && $s['end'] > $s['start'])
            ? round(($s['end'] - $s['start']) / HOUR_IN_SECONDS, 1)
            : 0;

        $html .= '<a class="pl-group" href="?internalpage=single_stic_pasar_lista_monitores&reunion=1&sesion='
            . esc_attr($s['id']) . '">';
        $html .= '<span class="pl-group-body">';
        $html .= '<span class="pl-name">' . esc_html(sticpa_pl_session_label($s)) . '</span>';
        $meta = array();
        if ($hours > 0) {
            $meta[] = sprintf(
                /* translators: %s: duración en horas */
                __('%s h', 'sticpa'),
                $hours
            );
        }
        $meta[] = $past ? __('pasar lista', 'sticpa') : __('todavía no ha llegado', 'sticpa');
        $html .= '<span class="pl-group-meta">' . esc_html(implode(' · ', $meta)) . '</span>';
        $html .= '</span>';
        $html .= '<span class="pl-detail">' . sticpa_pl_icon('next') . '</span>';
        $html .= '</a>';
    }
    $html .= '</div>';
} else {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('Todavía no hay reuniones este curso. Crea la primera aquí abajo.', 'sticpa')
        . '</span></p>';
}

// ---------------------------------------------------------------------------
// El formulario de crear: tres campos y un botón
// ---------------------------------------------------------------------------

$html .= '<div class="pl-sec">' . esc_html__('Nueva reunión', 'sticpa') . '</div>';
$html .= '<form method="post" class="pl-newmeet stic-loading-form"'
    . ' data-loading-text="' . esc_attr__('Creando la reunión…', 'sticpa') . '">';
$html .= wp_nonce_field('pl_reuniones', 'pl_nonce', true, false);

$html .= '<label class="pl-field">';
$html .= '<span class="pl-field-label">' . esc_html__('Nombre', 'sticpa') . '</span>';
$html .= '<input type="text" name="pl_reunion_name" required maxlength="120"'
    . ' placeholder="' . esc_attr__('Programación del 2.º trimestre', 'sticpa') . '">';
$html .= '</label>';

$html .= '<div class="pl-field-row">';
$html .= '<label class="pl-field">';
$html .= '<span class="pl-field-label">' . esc_html__('Día', 'sticpa') . '</span>';
// La fecha por defecto es HOY: lo normal es registrar la reunión el mismo día o
// justo después, y así el campo casi nunca hay que tocarlo.
$html .= '<input type="date" name="pl_reunion_date" required value="'
    . esc_attr(date('Y-m-d', sticpa_pl_now())) . '">';
$html .= '</label>';

$html .= '<label class="pl-field pl-field--sm">';
$html .= '<span class="pl-field-label">' . esc_html__('Hora', 'sticpa') . '</span>';
$html .= '<input type="time" name="pl_reunion_time" value="19:00">';
$html .= '</label>';

$html .= '<label class="pl-field pl-field--sm">';
$html .= '<span class="pl-field-label">' . esc_html__('Horas', 'sticpa') . '</span>';
$html .= '<input type="number" name="pl_reunion_hours" value="1.5" min="0.5" max="12" step="0.5" inputmode="decimal">';
$html .= '</label>';
$html .= '</div>';

$html .= '<button type="submit" class="pl-save">' . esc_html__('Crear reunión', 'sticpa') . '</button>';
$html .= '</form>';

$html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
    . esc_html__('Las reuniones van a su propio evento, no al de los sábados: así no aparecen en el calendario de las familias.', 'sticpa')
    . '</span></p>';
