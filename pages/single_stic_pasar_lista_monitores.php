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
if (!empty($_POST['pl_action'])) {
    if (!isset($_POST['pl_nonce']) || !wp_verify_nonce($_POST['pl_nonce'], 'pl_monitores')) {
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
}

$attendances = sticpa_pl_session_attendances($objSCP, $session['id'], $regMap);

// ---------------------------------------------------------------------------
// Pintado
// ---------------------------------------------------------------------------

$backUrl = $isReunion
    ? '?internalpage=single_stic_pasar_lista_reuniones'
    : '?internalpage=single_stic_pasar_lista';

$html .= '<div data-pl-marcar data-pl-monitores'
    . ' data-session="' . esc_attr($session['id']) . '"'
    . ' data-group="monitores"'
    . ' data-msg-draft="' . esc_attr__('Tienes marcas sin guardar de antes.', 'sticpa') . '"'
    . ' data-msg-offline="' . esc_attr__('Sin cobertura. Puedes marcar: se guardará en el móvil.', 'sticpa') . '"'
    . ' data-msg-queued="' . esc_attr__('Guardado en el móvil. Se enviará solo al volver la cobertura.', 'sticpa') . '"'
    . ' data-msg-sync="' . esc_attr__('Enviando lo que quedó pendiente…', 'sticpa') . '"'
    . ' data-msg-sent="' . esc_attr__('Lo pendiente ya está enviado.', 'sticpa') . '"'
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
$html .= '<div class="pl-subtitle">' . esc_html(sticpa_pl_session_label($session)) . '</div>';
$html .= '</div>';
if (!$isReunion) {
    $html .= '<a class="pl-session-pick" href="?internalpage=single_stic_pasar_lista_monitores&sesiones=1">'
        . esc_html(sticpa_pl_session_short($session)) . sticpa_pl_icon('down') . '</a>';
}
$html .= '</div>';

$html .= sticpa_pl_notice_html($pick);

if (is_array($saved)) {
    if ($saved['failed'] > 0) {
        $html .= '<p class="pl-notice"><span>' . esc_html(sprintf(
            /* translators: 1: guardadas, 2: fallidas */
            __('Se han guardado %1$d y %2$d han fallado. Vuelve a intentarlo.', 'sticpa'),
            $saved['saved'],
            $saved['failed']
        )) . '</span></p>';
    } else {
        $html .= '<p class="pl-notice" style="color:var(--success-dark)">' . sticpa_pl_icon('check')
            . '<span>' . esc_html(sprintf(
                /* translators: 1: cuántos vinieron, 2: cuántas faltas */
                __('Guardado · %1$d vinieron, %2$d faltas', 'sticpa'),
                $saved['counts']['yes'],
                $saved['counts']['no']
            )) . '</span></p>';
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
