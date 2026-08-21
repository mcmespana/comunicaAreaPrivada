<?php
/**
 * PASAR LISTA — home.
 * ----------------------------------------------------------------------------
 * Lo primero que ve un monitor el sábado. Un atajo grande a la sesión que toca
 * y su grupo, y debajo lo que le falta por pasar.
 *
 * La idea es que en el caso normal —tu grupo, la sesión de hoy— haya UN toque
 * entre entrar y estar marcando. Todo lo demás (otro grupo, otro día) sigue
 * ahí, un nivel más abajo.
 *
 * Diseño: docs/comunica/PASAR-LISTA.md §6.1
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageSettings['fileName'] = basename(__FILE__, ".php");

// El botón de refrescar de cualquier pantalla de Pasar Lista cae aquí.
if (!empty($_REQUEST['refrescar'])) {
    sticpa_pl_flush($objSCP, 'all');
}

$groups = sticpa_pl_groups($objSCP);
$myGroups = sticpa_pl_my_groups($objSCP);
$events = sticpa_pl_etapa_events($objSCP);
$course = sticpa_pl_course_for();

// El grupo del atajo: el tuyo. Si tienes varios, el primero; si no tienes
// ninguno (coordinación, o un monitor sin relación puesta), no hay atajo y se
// entra por el árbol, que es lo honesto.
$mainGroupId = '';
foreach ($myGroups as $gid) {
    if (isset($groups[$gid])) {
        $mainGroupId = $gid;
        break;
    }
}

$html .= '<div class="pl-head">';
$html .= '<div class="pl-head-titles">';
$html .= '<div class="pl-title"><span class="pl-title-code">' . esc_html__('Pasar lista', 'sticpa') . '</span></div>';
$html .= '<div class="pl-subtitle">' . esc_html($course['label']) . '</div>';
$html .= '</div>';
$html .= '<a class="pl-session-pick" href="?internalpage=single_stic_pasar_lista&refrescar=1"'
    . ' aria-label="' . esc_attr__('Refrescar datos', 'sticpa') . '">' . sticpa_pl_icon('refresh') . '</a>';
$html .= '</div>';

if (empty($groups)) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No hay grupos de tu delegación en este curso. Si crees que es un error, avisa a coordinación.', 'sticpa')
        . '</span></p>';
    return;
}

// ---------------------------------------------------------------------------
// El atajo
// ---------------------------------------------------------------------------

if ($mainGroupId !== '') {
    $group = $groups[$mainGroupId];
    $etapa = sticpa_pl_group_etapa($group['level']);
    $event = isset($events[$etapa]) ? $events[$etapa] : null;
    $sessions = ($event !== null) ? sticpa_pl_event_sessions($objSCP, $event['id']) : array();
    $pick = sticpa_pl_pick_session($sessions);

    if ($pick !== null) {
        $session = $pick['session'];
        $lista = sticpa_pl_lista($objSCP, $session['id'], $mainGroupId);
        $estados = sticpa_pl_lista_estados();
        $done = ($lista !== null && $lista['estado'] === $estados['pasada']);

        $html .= '<div class="pl-hero' . ($done ? ' pl-hero--done' : '') . '">';
        $html .= '<div class="pl-hero-when">' . esc_html(sticpa_pl_session_label($session)) . '</div>';
        $html .= '<div class="pl-hero-group">' . esc_html($group['code'])
            . ($group['name'] !== '' ? ' · ' . esc_html($group['name']) : '') . '</div>';

        if ($done) {
            $html .= '<div class="pl-hero-meta">' . esc_html(sprintf(
                /* translators: 1: cuántos vinieron, 2: cuántas ausencias */
                __('Ya pasada · %1$d vinieron, %2$d ausencias', 'sticpa'),
                $lista['n_asistieron'],
                $lista['n_faltaron']
            )) . '</div>';
        } elseif ($pick['why'] === 'today_before') {
            $html .= '<div class="pl-hero-meta">' . esc_html(sprintf(
                /* translators: %s: hora de inicio de la sesión */
                __('Empieza a las %s', 'sticpa'),
                date_i18n('H:i', (int) $session['start'])
            )) . '</div>';
        }

        $html .= '<a class="pl-hero-cta" href="?internalpage=single_stic_pasar_lista_marcar&grupo='
            . esc_attr($mainGroupId) . '&sesion=' . esc_attr($session['id']) . '">'
            . esc_html($done ? __('Revisar la lista', 'sticpa') : __('Pasar lista', 'sticpa'))
            . sticpa_pl_icon('next') . '</a>';
        $html .= '</div>';
    }
}

// ---------------------------------------------------------------------------
// Lo que falta por pasar
// ---------------------------------------------------------------------------

/* Solo de TUS grupos, y solo de la sesión que toca. Es a propósito: recorrer
 * todas las sesiones de todos los grupos es una consulta por par y no cabe en
 * una home. El panorama completo del curso es el resumen de coordinación
 * (fase 3), que ya recorre el curso entero y pinta la tira de listas. */
$pending = array();
foreach ($myGroups as $gid) {
    if (!isset($groups[$gid]) || $gid === $mainGroupId) {
        continue;
    }
    $g = $groups[$gid];
    $etapa = sticpa_pl_group_etapa($g['level']);
    if (!isset($events[$etapa])) {
        continue;
    }
    $sessions = sticpa_pl_event_sessions($objSCP, $events[$etapa]['id']);
    $pick = sticpa_pl_pick_session($sessions);
    if ($pick === null || $pick['why'] === 'future') {
        continue;
    }
    $lista = sticpa_pl_lista($objSCP, $pick['session']['id'], $gid);
    if ($lista !== null && $lista['estado'] !== '') {
        continue;   // pasada u omitida: no es pendiente
    }
    $pending[] = array('group' => $g, 'id' => $gid, 'session' => $pick['session']);
}

if (!empty($pending)) {
    $html .= '<div class="pl-etapa-title">'
        . '<span class="pl-etapa-dot" style="background:var(--warning-color)"></span>'
        . esc_html__('Te falta pasar', 'sticpa') . '</div>';
    $html .= '<div class="pl-list">';
    foreach ($pending as $row) {
        $html .= '<a class="pl-group" href="?internalpage=single_stic_pasar_lista_marcar&grupo='
            . esc_attr($row['id']) . '&sesion=' . esc_attr($row['session']['id']) . '">';
        $html .= '<span class="pl-group-body">';
        $html .= '<span class="pl-name">' . esc_html($row['group']['code'])
            . ($row['group']['name'] !== '' ? ' · ' . esc_html($row['group']['name']) : '') . '</span>';
        $html .= '<span class="pl-group-meta">' . esc_html(sticpa_pl_session_label($row['session'], false)) . '</span>';
        $html .= '</span>';
        $html .= '<span class="pl-done pl-done--no"></span>';
        $html .= '<span class="pl-detail">' . sticpa_pl_icon('next') . '</span>';
        $html .= '</a>';
    }
    $html .= '</div>';
}

// ---------------------------------------------------------------------------
// Entrada al árbol
// ---------------------------------------------------------------------------

$html .= '<a class="pl-all-groups" href="?internalpage=single_stic_pasar_lista_grupos">';
$html .= '<span>' . esc_html__('Ver todos los grupos', 'sticpa') . '</span>';
$html .= '<span class="pl-all-groups-meta">' . esc_html(sprintf(
    /* translators: %d: número de grupos de la delegación */
    _n('%d grupo', '%d grupos', count($groups), 'sticpa'),
    count($groups)
)) . '</span>';
$html .= sticpa_pl_icon('next');
$html .= '</a>';

if ($mainGroupId === '') {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No tienes ningún grupo asignado como monitor/a, así que no hay atajo. Puedes pasar lista de cualquier grupo desde la lista de arriba.', 'sticpa')
        . '</span></p>';
}
