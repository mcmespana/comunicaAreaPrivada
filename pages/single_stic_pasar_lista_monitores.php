<?php
/**
 * PASAR LISTA — monitores (coordinación).
 * ----------------------------------------------------------------------------
 * La lista de monitores de una sesión. Dos diferencias con la de participantes,
 * y las dos son a propósito:
 *
 *   1. ARRANCA TODO EN VERDE. Se asume que los monitores vienen siempre, así
 *      que coordinación no repasa doce nombres: afirma "vinieron todos menos
 *      estos". El toque solo pone y quita faltas.
 *   2. Al guardar se escribe `yes` EXPLÍCITO para los no marcados. Aquí el
 *      verde es un dato afirmado, no un hueco; si se dejara vacío, el
 *      porcentaje de un monitor que ha venido a todo saldría a cero.
 *
 * Con ?reunion=1 la lista es de una reunión de programación en vez del sábado.
 *
 * Diseño: docs/comunica/PASAR-LISTA-COORDINACION.md §3
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageSettings['fileName'] = basename(__FILE__, ".php");

// La tanda va ANTES de resolver el alcance: así la primera consulta de
// coordinación viaja con las otras cuatro en vez de abrir su propio viaje.
sticpa_pl_prime($objSCP, function () use ($objSCP) {
    sticpa_pl_coord_scope($objSCP);
    sticpa_pl_groups($objSCP);
    sticpa_pl_all_relationships($objSCP);
    sticpa_pl_etapa_events($objSCP);
    sticpa_pl_all_listas($objSCP);
});

$scope = sticpa_pl_coord_scope($objSCP);
if ($scope === null) {
    // No coordina: no se enseña media pantalla ni un error técnico.
    $html .= '<div class="pl-head"><a class="pl-back" href="?internalpage=single_stic_pasar_lista"'
        . ' aria-label="' . esc_attr__('Volver', 'sticpa') . '">' . sticpa_pl_icon('back') . '</a>'
        . '<div class="pl-head-titles"><div class="pl-title"><span class="pl-title-code">'
        . esc_html__('Monitores', 'sticpa') . '</span></div></div></div>';
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('Esta pantalla es de coordinación. Si crees que deberías verla, avisa a la oficina técnica.', 'sticpa')
        . '</span></p>';
    return;
}

$isReunion = !empty($_REQUEST['reunion']);
$sessionId = isset($_REQUEST['sesion']) ? sticpa_pl_safe_id($_REQUEST['sesion']) : '';

$groups = sticpa_pl_scoped_groups($objSCP, $scope);
$monitors = sticpa_pl_monitors_of($objSCP, $groups);

// ---------------------------------------------------------------------------
// De qué evento y qué sesión
// ---------------------------------------------------------------------------

$events = sticpa_pl_etapa_events($objSCP);
$event = null;

if ($isReunion) {
    $event = sticpa_pl_reuniones_event($objSCP);
} else {
    // El sábado: se usa el evento de la etapa del alcance. Sin etapa marcada,
    // el primero que haya, porque las sesiones son las mismas fechas.
    $etapa = ($scope['etapa'] !== '') ? $scope['etapa'] : '';
    if ($etapa !== '' && isset($events[$etapa])) {
        $event = $events[$etapa];
    } else {
        foreach (array('COM', 'MIC', 'LC') as $e) {
            if (isset($events[$e])) {
                $event = $events[$e];
                break;
            }
        }
    }
}

if ($event === null) {
    $html .= '<div class="pl-head"><a class="pl-back" href="?internalpage=single_stic_pasar_lista"'
        . ' aria-label="' . esc_attr__('Volver', 'sticpa') . '">' . sticpa_pl_icon('back') . '</a>'
        . '<div class="pl-head-titles"><div class="pl-title"><span class="pl-title-code">'
        . esc_html__('Monitores', 'sticpa') . '</span></div></div></div>';
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html($isReunion
            ? __('Todavía no hay ninguna reunión creada.', 'sticpa')
            : __('No hay evento de sesiones semanales en el curso actual.', 'sticpa'))
        . '</span></p>';
    if ($isReunion) {
        $html .= '<p><a class="pl-session-pick" href="?internalpage=single_stic_pasar_lista_reuniones">'
            . esc_html__('Crear una reunión', 'sticpa') . '</a></p>';
    }
    return;
}

// TANDA 2: sesiones e inscripciones del evento.
sticpa_pl_prime($objSCP, function () use ($objSCP, $event) {
    sticpa_pl_event_sessions($objSCP, $event['id']);
    sticpa_pl_event_registrations($objSCP, $event['id']);
});

$sessions = sticpa_pl_event_sessions($objSCP, $event['id']);
$pick = null;
$session = null;
if ($sessionId !== '') {
    foreach ($sessions as $s) {
        if ($s['id'] === $sessionId) {
            $session = $s;
            break;
        }
    }
}
if ($session === null) {
    $pick = sticpa_pl_pick_session($sessions);
    $session = ($pick !== null) ? $pick['session'] : null;
}

if ($session === null) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('Este evento aún no tiene sesiones.', 'sticpa') . '</span></p>';
    return;
}

$regMap = sticpa_pl_event_registrations($objSCP, $event['id']);

// ---------------------------------------------------------------------------
// Guardado
// ---------------------------------------------------------------------------

$saved = null;
$saveProblems = array();
$savedOk = false;
$isPost = (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST');
$marksRaw = isset($_POST['pl_marks']) ? (string) $_POST['pl_marks'] : '';

if (!empty($_POST['pl_action'])) {
    if (!isset($_POST['pl_nonce']) || !wp_verify_nonce($_POST['pl_nonce'], 'pl_monitores')) {
        sticpa_pl_log_save(array(
            'pantalla' => 'monitores', 'motivo' => 'nonce',
            'sesion' => $session['id'], 'marcas_post' => strlen($marksRaw),
        ));
        $html .= '<p class="pl-notice">' . sticpa_pl_icon('clock') . '<span>'
            . esc_html__('La sesión ha caducado. Vuelve a cargar la pantalla.', 'sticpa') . '</span></p>';
    } else {
        $marks = array();
        if (!empty($_POST['pl_marks'])) {
            $decoded = json_decode(wp_unslash($_POST['pl_marks']), true);
            if (is_array($decoded)) {
                foreach ($decoded as $cid => $st) {
                    $cid = sticpa_pl_safe_id($cid);
                    // Solo estados conocidos. El vacío se ignora: aquí no
                    // existe "sin marcar", y quien no venga marcado será verde.
                    if ($cid === '' || !sticpa_pl_is_state($st)) {
                        continue;
                    }
                    $marks[$cid] = $st;
                }
            }
        }
        $saved = sticpa_pl_save_monitors($objSCP, $session['id'], $monitors, $marks, $regMap);
    }
} elseif ($isPost) {
    // Un POST sin acción: el mismo agujero que en la pantalla de participantes
    // (el botón deshabilitado antes de serializar). Aquí queda registrado.
    sticpa_pl_log_save(array(
        'pantalla' => 'monitores', 'motivo' => 'post_sin_accion',
        'sesion' => $session['id'], 'marcas_post' => strlen($marksRaw),
    ));
    $html .= '<p class="pl-notice" style="color:var(--danger-dark)">' . sticpa_pl_icon('warn') . '<span>'
        . esc_html__('No se ha guardado: la petición llegó sin la orden de guardar. Vuelve a intentarlo.', 'sticpa')
        . '</span></p>';
}

$attendances = sticpa_pl_session_attendances($objSCP, $session['id'], $regMap);

// La lista de monitores de esta sesión, releída del CRM. Se lee SIEMPRE (no
// solo tras guardar) para poder decir si ya estaba pasada: es la misma pregunta
// que contesta la pantalla de participantes, «¿la pasé o no?».
$listasMon = sticpa_pl_all_listas_monitores($objSCP);
$listaMon = isset($listasMon[$session['id']]) ? $listasMon[$session['id']] : null;

// La misma verificación por relectura que en participantes: las asistencias y,
// desde ahora, también la lista.
if (is_array($saved)) {
    $saveProblems = sticpa_pl_check_saved(
        isset($saved['written']) ? $saved['written'] : array(),
        $listaMon,
        $attendances,
        false,
        true
    );
    $savedOk = ((int) $saved['failed'] === 0 && empty($saveProblems));
    sticpa_pl_log_save(array(
        'pantalla' => 'monitores',
        'motivo' => $savedOk ? 'ok' : 'fallos',
        'sesion' => $session['id'],
        'marcas_post' => strlen($marksRaw),
        'marcas_usadas' => isset($saved['written']) ? count($saved['written']) : 0,
        'saved' => (int) $saved['saved'],
        'failed' => (int) $saved['failed'],
        'lista_id' => (string) $saved['lista_id'],
        'errores' => array_merge(
            isset($saved['errors']) ? (array) $saved['errors'] : array(),
            array_map(function ($p) { return array('paso' => 'relectura', 'error' => $p); }, $saveProblems)
        ),
    ));
}

// ---------------------------------------------------------------------------
// Pintado
// ---------------------------------------------------------------------------

$backUrl = $isReunion
    ? '?internalpage=single_stic_pasar_lista_reuniones'
    : '?internalpage=single_stic_pasar_lista';

$html .= '<div data-pl-marcar data-pl-monitores'
    . ($savedOk ? ' data-pl-saved-ok' : '')
    . ' data-session="' . esc_attr($session['id']) . '"'
    . ' data-group="monitores"'
    . ' data-msg-draft="' . esc_attr__('Tienes marcas sin guardar de antes.', 'sticpa') . '"'
    . ' data-msg-offline="' . esc_attr__('Sin cobertura. Puedes marcar: se guardará en el móvil.', 'sticpa') . '"'
    . ' data-msg-queued="' . esc_attr__('Guardado en el móvil. Se enviará solo al volver la cobertura.', 'sticpa') . '"'
    . ' data-msg-sync="' . esc_attr__('Enviando lo que quedó pendiente…', 'sticpa') . '"'
    . ' data-msg-sent="' . esc_attr__('Lo pendiente ya está enviado.', 'sticpa') . '"'
    . ' data-msg-stuck="' . esc_attr__('No se ha podido enviar lo que quedó pendiente. Vuelve a marcar y guardar.', 'sticpa') . '"'
    . '>';

$html .= '<div class="pl-head">';
$html .= '<a class="pl-back" href="' . esc_url($backUrl) . '"'
    . ' aria-label="' . esc_attr__('Volver', 'sticpa') . '">' . sticpa_pl_icon('back') . '</a>';
$html .= '<div class="pl-head-titles">';
$html .= '<div class="pl-title"><span class="pl-title-code">' . esc_html__('Monitores', 'sticpa') . '</span>';
if ($scope['etapa'] !== '') {
    $html .= '<span class="pl-title-name">' . esc_html($scope['etapa']) . '</span>';
}
$html .= '</div>';
// En una reunión, lo que identifica la lista es su NOMBRE («Programación del
// 2.º trimestre»); la fecha va detrás. En el sábado semanal es al revés.
$subtitulo = ($isReunion && !empty($session['name']))
    ? $session['name'] . ' · ' . sticpa_pl_session_label($session)
    : sticpa_pl_session_label($session);
$html .= '<div class="pl-subtitle">' . esc_html($subtitulo) . '</div>';
$html .= '</div>';
if (!$isReunion) {
    // El mismo desplegable nativo que la lista de participantes: la pregunta
    // "¿de qué día?" se contesta igual en las dos pantallas, y una interacción
    // que cambia de forma según la pantalla se aprende dos veces.
    $html .= sticpa_pl_session_select_html($sessions, $session['id'], '', 'single_stic_pasar_lista_monitores');
}
$html .= '</div>';

$html .= sticpa_pl_notice_html($pick);

// Si ya estaba pasada, se dice. Evita el «¿la pasé o no?» y el guardado doble.
if ($saved === null && $listaMon !== null && $listaMon['estado'] !== '') {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>' . esc_html(sprintf(
        /* translators: 1: cuántos vinieron, 2: cuántas faltas */
        __('Esta lista de monitores ya está pasada: %1$d vinieron, %2$d faltas. Si la cambias, se actualiza.', 'sticpa'),
        (int) $listaMon['n_asistieron'],
        (int) $listaMon['n_faltaron']
    )) . '</span></p>';
}

if (is_array($saved)) {
    if ($savedOk) {
        $html .= '<p class="pl-notice" style="color:var(--success-dark)">' . sticpa_pl_icon('check')
            . '<span>' . esc_html(sprintf(
                /* translators: 1: cuántos vinieron, 2: cuántas faltas */
                __('Guardado · %1$d vinieron, %2$d faltas', 'sticpa'),
                $saved['counts']['yes'],
                $saved['counts']['no']
            )) . '</span></p>';
    } else {
        $html .= sticpa_pl_save_result_html($saved, $saveProblems, $objSCP);
    }
}

if (empty($monitors)) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No hay monitores con relación vigente en los grupos de tu alcance.', 'sticpa')
        . '</span></p>';
    $html .= '</div>';
    return;
}

// La regla, dicha en la pantalla. Es lo contrario que en los chavales y quien
// entra por primera vez tiene que saberlo antes de guardar, no después.
$html .= '<p class="pl-hint pl-hint--rule">' . sticpa_pl_icon('info') . '<span>'
    . esc_html__('Están todos en verde. Marca solo a quien no vino.', 'sticpa')
    . '</span></p>';

$html .= '<form method="post" class="stic-loading-form" data-pl-form'
    . ' data-loading-text="' . esc_attr__('Guardando…', 'sticpa') . '">';
$html .= wp_nonce_field('pl_monitores', 'pl_nonce', true, false);
// La acción, también en un campo oculto: ver el comentario largo en la pantalla
// de participantes. Sin esto, un botón deshabilitado al enviar se traga la orden
// de guardar y no se escribe nada.
$html .= '<input type="hidden" name="pl_action" value="save" data-pl-action>';
$html .= '<input type="hidden" name="pl_marks" value="" data-pl-marks>';

$html .= '<div class="pl-list">';
foreach ($monitors as $m) {
    // El estado del CRM si lo hay; si no, verde, que es el defecto de esta
    // pantalla y el que se va a escribir.
    $state = isset($attendances[$m['id']]['status']) && $attendances[$m['id']]['status'] !== ''
        ? $attendances[$m['id']]['status']
        : 'yes';
    $person = $m;
    // La línea de debajo dice de qué grupos es: es lo que distingue a dos
    // monitores con el mismo nombre de pila y lo que explica por qué está aquí.
    if (!empty($m['groups'])) {
        $person['name'] = $m['name'];
    }
    $fichaUrl = '?internalpage=single_stic_pasar_lista_monitor&monitor=' . rawurlencode($m['id']);
    $html .= sticpa_pl_row_html($person, $state, 0, $fichaUrl, implode(' · ', $m['groups']));
}
$html .= '</div>';

$html .= sticpa_pl_legend_html();

$html .= '<div class="pl-savebar">';
$html .= '<p class="pl-status" data-pl-status hidden></p>';
$html .= '<div class="pl-counts">';
$html .= '<span class="pl-count"><span class="pl-count-dot pl-count-dot--yes"></span>'
    . '<span data-pl-count-yes>0</span>&nbsp;' . esc_html__('vinieron', 'sticpa') . '</span>';
$html .= '<span class="pl-count"><span class="pl-count-dot pl-count-dot--no"></span>'
    . '<span data-pl-count-no>0</span>&nbsp;' . esc_html__('faltas', 'sticpa') . '</span>';
$html .= '</div>';
$html .= '<button type="submit" name="pl_action" value="save" class="pl-save" data-pl-save'
    . ' data-label-full="' . esc_attr__('Guardar', 'sticpa') . '"'
    . ' data-label-partial="' . esc_attr__('Guardar', 'sticpa') . '"'
    . ' data-label-saving="' . esc_attr__('Guardando…', 'sticpa') . '">'
    . esc_html__('Guardar', 'sticpa') . '</button>';
$html .= '</div>';

$html .= '</form>';
$html .= sticpa_pl_sheet_html(sticpa_pl_session_label($session));
$html .= '</div>';
