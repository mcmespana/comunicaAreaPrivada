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

// UNA TANDA para lo que no depende de nada: quién coordina, los eventos (de
// ahí sale el de reuniones) y las listas de la delegación. Eran tres viajes en
// fila antes de poder pintar nada. Las listas no se pedían: ahora dicen, en la
// misma lectura, qué reuniones tienen ya la lista pasada.
// (Al crear una reunión no: el alta tira la caché y lo leería todo otra vez.)
if (empty($_POST['pl_reunion_name'])) {
    sticpa_pl_prime($objSCP, function () use ($objSCP) {
        sticpa_pl_coord_scope($objSCP);
        sticpa_pl_events_raw($objSCP);
        sticpa_pl_all_listas_monitores($objSCP);
    });
}

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
$createOk = false;
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
        $createOk = ($created !== null);
        $createMsg = $createOk
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
// El ALCANCE en la cabecera, como en Monitores (design.md §6.4): la lista que
// se pasa en cada reunión es la de los monitores de ese alcance.
$html .= '<div class="pl-title"><span class="pl-title-code">' . esc_html__('Reuniones', 'sticpa') . '</span>'
    . '<span class="pl-title-name">' . esc_html(sticpa_pl_coord_scope_label($scope)) . '</span></div>';
$html .= '<div class="pl-subtitle">' . esc_html($course['label']) . '</div>';
$html .= '</div>';
$html .= '</div>';

if ($createMsg !== '') {
    $html .= $createOk
        ? '<p class="pl-notice pl-notice--ok">' . sticpa_pl_icon('check')
            . '<span>' . esc_html($createMsg) . '</span></p>'
        : '<p class="pl-notice pl-notice--error">' . sticpa_pl_icon('warn')
            . '<span>' . esc_html($createMsg) . '</span></p>';
}

// ---------------------------------------------------------------------------
// Las reuniones que hay
// ---------------------------------------------------------------------------

if (!empty($sessions)) {
    // Del mismo índice que ya se ha leído en la tanda: cero consultas.
    $listasReu = sticpa_pl_all_listas_monitores($objSCP);
    // De la más reciente a la más antigua: la de la semana pasada es la que se
    // busca, no la de octubre.
    $ordered = array_reverse($sessions);
    $html .= '<div class="pl-list">';
    foreach ($ordered as $s) {
        $listaReu = isset($listasReu[$s['id']]) ? $listasReu[$s['id']] : null;
        $mark = sticpa_pl_list_mark(($listaReu !== null) ? $listaReu['estado'] : '', (int) $s['start']);
        $hours = (!empty($s['end']) && $s['end'] > $s['start'])
            ? round(($s['end'] - $s['start']) / HOUR_IN_SECONDS, 1)
            : 0;

        $html .= '<a class="pl-group" href="?internalpage=single_stic_pasar_lista_monitores&reunion=1&sesion='
            . esc_attr($s['id']) . '">';
        $html .= '<span class="pl-group-body">';
        // EL NOMBRE MANDA. Una reunión es «Programación del 2.º trimestre», y
        // eso es lo que se busca en la lista; la fecha acompaña. En las sesiones
        // semanales es al revés y por eso aquí no vale el mismo formato.
        $nombre = (isset($s['name']) && $s['name'] !== '') ? $s['name'] : sticpa_pl_session_label($s);
        $html .= '<span class="pl-name">' . esc_html($nombre) . '</span>';
        $meta = array();
        if (isset($s['name']) && $s['name'] !== '') {
            $meta[] = sticpa_pl_session_label($s);
        }
        if ($hours > 0) {
            // Con espacio duro: la línea es larga y partía «3 / h» al saltar.
            $meta[] = str_replace(' ', "\u{00A0}", sprintf(
                /* translators: %s: duración en horas */
                __('%s h', 'sticpa'),
                $hours
            ));
        }
        /* ¿ESTÁ PASADA? Con el MISMO idioma que el historial de un grupo: el
         * círculo de la derecha (✓ pasada, vacío pendiente) y los números en
         * la línea de debajo. Antes había que entrar en cada reunión para
         * saberlo. Los números son los de la lista, que es una por reunión: si
         * dos coordinadores comparten la reunión, son los del último que
         * guardó. */
        $done = '';
        if ($mark === 'ok') {
            $meta[] = sprintf(
                /* translators: 1: cuántos vinieron, 2: cuántas faltas */
                __('%1$d vinieron · %2$d faltas', 'sticpa'),
                (int) $listaReu['n_asistieron'],
                (int) $listaReu['n_faltaron']
            );
            $done = '<span class="pl-done pl-done--yes">' . sticpa_pl_glyph('check') . '</span>';
        } elseif ($mark === 'gap') {
            $meta[] = __('sin pasar', 'sticpa');
            $done = '<span class="pl-done pl-done--no"></span>';
        } elseif ($mark === 'skip') {
            $meta[] = __('sin registro', 'sticpa');
            $done = '<span class="pl-done pl-done--skip">' . sticpa_pl_icon('skip') . '</span>';
        } else {
            $meta[] = __('todavía no ha llegado', 'sticpa');
        }
        $html .= '<span class="pl-group-meta">' . esc_html(implode(' · ', $meta)) . '</span>';
        $html .= '</span>';
        $html .= $done;
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

/* DETRÁS DE UN BOTÓN, como el alta de un seguimiento en la ficha. A esta
 * pantalla se viene a ELEGIR una reunión y pasar su lista; crearla pasa tres o
 * cuatro veces al año. Con el formulario siempre abierto, su botón era el único
 * de marca de la pantalla y parecía que a lo que se venía era a crear.
 *
 * Abierto de partida solo cuando no hay nada que elegir (no hay reuniones) o
 * cuando el alta acaba de fallar: ahí lo que toca es corregir y volver a
 * intentarlo, no buscar un botón. */
$formAbierto = empty($sessions) || ($createMsg !== '' && !$createOk);

if ($formAbierto) {
    $html .= '<div class="pl-sec">' . esc_html__('Nueva reunión', 'sticpa') . '</div>';
} else {
    $html .= '<button type="button" class="pl-seg-add" data-pl-seg-add aria-expanded="false"'
        . ' aria-controls="pl-reu-form">'
        . sticpa_pl_icon('plus')
        . '<span>' . esc_html__('Nueva reunión', 'sticpa') . '</span></button>';
}
$html .= '<form method="post" id="pl-reu-form" class="pl-newmeet stic-loading-form" data-pl-seg-form'
    . ($formAbierto ? '' : ' hidden')
    . ' data-loading-text="' . esc_attr__('Creando la reunión…', 'sticpa') . '">';
$html .= wp_nonce_field('pl_reuniones', 'pl_nonce', true, false);

$html .= '<label class="pl-field">';
$html .= '<span class="pl-field-label">' . esc_html__('Nombre', 'sticpa') . '</span>';
// El foco va aquí al abrir (`data-pl-seg-first`): es el único campo que casi
// siempre hay que escribir; el día y la hora ya vienen puestos.
$html .= '<input type="text" name="pl_reunion_name" required maxlength="120" data-pl-seg-first'
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
