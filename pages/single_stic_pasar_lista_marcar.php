<?php
/**
 * PASAR LISTA — marcar asistencia de un grupo en una sesión.
 * ----------------------------------------------------------------------------
 * La pantalla que importa. Se llega con ?grupo=<id> y, opcionalmente,
 * ?sesion=<id>; si no se dice la sesión, la elige sticpa_pl_pick_session().
 *
 * Tres consultas y ya está: las personas del grupo, las asistencias de la
 * sesión y la lista del grupo. Marcar no consulta nada: todo pasa en el
 * navegador hasta que se pulsa Guardar, y entonces se manda de una vez.
 *
 * Un monitor puede pasar lista de CUALQUIER grupo de su delegación, no solo del
 * suyo: a veces uno no está y le cubre otro, y esa es la realidad de un sábado.
 * El límite es la delegación, que la pone el CRM con su grupo de seguridad.
 *
 * Diseño: docs/comunica/PASAR-LISTA.md §6.3
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageSettings['fileName'] = basename(__FILE__, ".php");

$groupId = isset($_REQUEST['grupo']) ? sticpa_pl_safe_id($_REQUEST['grupo']) : '';
$sessionId = isset($_REQUEST['sesion']) ? sticpa_pl_safe_id($_REQUEST['sesion']) : '';

$groups = sticpa_pl_groups($objSCP);

if ($groupId === '' || !isset($groups[$groupId])) {
    // Sin grupo válido no se enseña una pantalla a medias: se manda al árbol.
    $html .= '<div class="stic-entry-header"><h3>' . esc_html__('Pasar lista', 'sticpa') . '</h3></div>';
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('Elige un grupo para pasar lista.', 'sticpa') . '</span></p>';
    $html .= '<p><a class="pl-session-pick" href="?internalpage=single_stic_pasar_lista_grupos">'
        . esc_html__('Ver los grupos', 'sticpa') . '</a></p>';
    return;
}

$group = $groups[$groupId];
$etapa = sticpa_pl_group_etapa($group['level']);
$events = sticpa_pl_etapa_events($objSCP);
$event = isset($events[$etapa]) ? $events[$etapa] : null;

if ($event === null) {
    // El evento de la etapa no existe o no sigue la convención de nombres. Se
    // dice en claro: callarlo deja al monitor mirando una lista vacía sin saber
    // que el problema está en el CRM y no en él.
    $html .= '<div class="stic-entry-header"><h3>' . esc_html($group['code']) . '</h3></div>';
    $html .= '<p class="pl-notice">' . sticpa_pl_icon('clock') . '<span>'
        . esc_html__('Este grupo no tiene evento de sesiones semanales en el curso actual. Avisa a coordinación.', 'sticpa')
        . '</span></p>';
    return;
}

$sessions = sticpa_pl_event_sessions($objSCP, $event['id']);

// Qué sesión se marca: la pedida, o la que toca según la regla.
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
    $html .= '<div class="stic-entry-header"><h3>' . esc_html($group['code']) . '</h3></div>';
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('Este evento aún no tiene sesiones.', 'sticpa') . '</span></p>';
    return;
}

$people = sticpa_pl_group_people($objSCP, $groupId);
$regMap = sticpa_pl_event_registrations($objSCP, $event['id']);

// ---------------------------------------------------------------------------
// Guardado
// ---------------------------------------------------------------------------

$saved = null;
if (!empty($_POST['pl_action'])) {
    // El nonce evita que un enlace de fuera escriba asistencias en nombre del
    // monitor conectado. Sin esto, un GET disfrazado bastaría.
    if (!isset($_POST['pl_nonce']) || !wp_verify_nonce($_POST['pl_nonce'], 'pl_save_' . $groupId)) {
        $html .= '<p class="pl-notice">' . sticpa_pl_icon('clock') . '<span>'
            . esc_html__('La sesión ha caducado. Vuelve a cargar la pantalla.', 'sticpa') . '</span></p>';
    } else {
        $marks = array();
        if (!empty($_POST['pl_marks'])) {
            $decoded = json_decode(wp_unslash($_POST['pl_marks']), true);
            if (is_array($decoded)) {
                foreach ($decoded as $cid => $st) {
                    // Solo personas de ESTE grupo y estados que conocemos: lo
                    // que venga del navegador no manda sobre el CRM.
                    $cid = sticpa_pl_safe_id($cid);
                    if ($cid === '' || !sticpa_pl_is_state($st)) {
                        continue;
                    }
                    $marks[$cid] = $st;
                }
            }
        }
        $allowed = array();
        foreach ($people['participants'] as $p) {
            $allowed[$p['id']] = true;
        }
        $marks = array_intersect_key($marks, $allowed);

        $omitida = ($_POST['pl_action'] === 'skip');
        $saved = sticpa_pl_save($objSCP, $session['id'], $groupId, $marks, $omitida, $regMap);
    }
}

// ---------------------------------------------------------------------------
// Estado actual
// ---------------------------------------------------------------------------

$attendances = sticpa_pl_session_attendances($objSCP, $session['id'], $regMap);
$lista = sticpa_pl_lista($objSCP, $session['id'], $groupId);

// Para el aviso de ausencias seguidas hace falta el histórico del participante,
// que son las asistencias de TODAS las sesiones pasadas. Es una consulta por
// sesión celebrada, así que en esta fase solo se calcula con lo que ya está en
// la caché del estado: el aviso es una ayuda, no un dato que justifique 20
// llamadas al abrir la pantalla. La fase 3 lo traerá del resumen, que ya
// recorre el curso entero.
$streaks = array();

$monitorNames = array();
foreach ($people['monitors'] as $m) {
    $monitorNames[] = $m['name'];
}

// ---------------------------------------------------------------------------
// Pintado
// ---------------------------------------------------------------------------

$html .= '<div data-pl-marcar>';

// Cabecera: grupo, monitores y selector de sesión.
$html .= '<div class="pl-head">';
$html .= '<a class="pl-back" href="?internalpage=single_stic_pasar_lista_grupos"'
    . ' aria-label="' . esc_attr__('Volver a los grupos', 'sticpa') . '">' . sticpa_pl_icon('back') . '</a>';
$html .= '<div class="pl-head-titles">';
$html .= '<div class="pl-title"><span class="pl-title-code">' . esc_html($group['code']) . '</span>';
if ($group['name'] !== '') {
    $html .= '<span class="pl-title-name">' . esc_html($group['name']) . '</span>';
}
$html .= '</div>';
$sub = array();
if (!empty($monitorNames)) {
    $sub[] = implode(', ', $monitorNames);
}
$sub[] = sprintf(
    /* translators: %d: número de participantes del grupo */
    _n('%d participante', '%d participantes', count($people['participants']), 'sticpa'),
    count($people['participants'])
);
$html .= '<div class="pl-subtitle">' . esc_html(implode(' · ', $sub)) . '</div>';
$html .= '</div>';
$html .= '<a class="pl-session-pick" href="?internalpage=single_stic_pasar_lista_grupos&grupo='
    . esc_attr($groupId) . '&sesiones=1">'
    . esc_html(sticpa_pl_session_short($session)) . sticpa_pl_icon('down') . '</a>';
$html .= '</div>';

// Aviso de por qué esta sesión (solo si hay algo que decir).
$html .= sticpa_pl_notice_html($pick);

// Resultado del guardado.
if (is_array($saved)) {
    if ($saved['failed'] > 0) {
        $html .= '<p class="pl-notice"><span>' . esc_html(sprintf(
            /* translators: 1: guardadas, 2: fallidas */
            __('Se han guardado %1$d marcas y %2$d han fallado. Vuelve a intentarlo.', 'sticpa'),
            $saved['saved'],
            $saved['failed']
        )) . '</span></p>';
    } else {
        $html .= '<p class="pl-notice" style="color:var(--success-dark)">' . sticpa_pl_icon('check')
            . '<span>' . esc_html__('Lista guardada.', 'sticpa') . '</span></p>';
    }
}

// Si la lista ya está pasada, se dice: evita el "¿la pasé o no?".
if ($lista !== null && $lista['estado'] !== '') {
    $estados = sticpa_pl_lista_estados();
    $txt = ($lista['estado'] === $estados['omitida'])
        ? __('Esta sesión está marcada como «sin registro».', 'sticpa')
        : __('Esta lista ya está pasada. Si la cambias, se actualiza.', 'sticpa');
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>' . esc_html($txt) . '</span></p>';
}

if (empty($people['participants'])) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('Este grupo no tiene participantes con relación vigente. Revisa las relaciones en el CRM.', 'sticpa')
        . '</span></p>';
    $html .= '</div>';
    return;
}

$html .= '<form method="post" data-pl-form>';
$html .= wp_nonce_field('pl_save_' . $groupId, 'pl_nonce', true, false);
$html .= '<input type="hidden" name="pl_marks" value="" data-pl-marks>';

// "Han venido todos": desmarcar dos ausentes es más rápido que marcar diez.
$html .= '<button type="button" class="pl-all-present" data-pl-all-present>'
    . sticpa_pl_glyph('check') . esc_html__('Han venido todos', 'sticpa') . '</button>';

$html .= '<div class="pl-list">';
foreach ($people['participants'] as $p) {
    $state = isset($attendances[$p['id']]['status']) ? $attendances[$p['id']]['status'] : '';
    $streak = isset($streaks[$p['id']]) ? (int) $streaks[$p['id']] : 0;
    $html .= sticpa_pl_row_html($p, $state, $streak);
}
$html .= '</div>';

$html .= sticpa_pl_legend_html();

// "Sin registro": cubre "no hubo reunión" y "se me olvidó y ya no me acuerdo".
// Un monitor honesto necesita poder cerrar el aviso sin inventarse datos.
$html .= '<button type="submit" name="pl_action" value="skip" class="pl-skip">'
    . sticpa_pl_icon('skip') . esc_html__('Sin registro — no me avises más', 'sticpa') . '</button>';

// Barra de guardado: contadores vivos y un solo botón.
$html .= '<div class="pl-savebar">';
$html .= '<div class="pl-counts">';
$html .= '<span class="pl-count"><span class="pl-count-dot pl-count-dot--yes"></span>'
    . '<span data-pl-count-yes>0</span>&nbsp;' . esc_html__('vinieron', 'sticpa') . '</span>';
$html .= '<span class="pl-count"><span class="pl-count-dot pl-count-dot--no"></span>'
    . '<span data-pl-count-no>0</span>&nbsp;' . esc_html__('ausencias', 'sticpa') . '</span>';
$html .= '<span class="pl-count pl-count--none" data-pl-count-none-wrap hidden>'
    . '<span class="pl-count-dot pl-count-dot--none"></span>'
    . '<span data-pl-count-none>0</span>&nbsp;' . esc_html__('sin marcar', 'sticpa') . '</span>';
$html .= '</div>';
$html .= '<button type="submit" name="pl_action" value="save" class="pl-save" data-pl-save'
    . ' data-label-full="' . esc_attr__('Guardar lista', 'sticpa') . '"'
    . ' data-label-partial="' . esc_attr__('Guardar ({n} sin marcar)', 'sticpa') . '">'
    . esc_html__('Guardar lista', 'sticpa') . '</button>';
$html .= '</div>';

$html .= '</form>';

// La hoja de los cuatro estados, una por pantalla.
$html .= sticpa_pl_sheet_html(sticpa_pl_session_label($session));

$html .= '</div>';
