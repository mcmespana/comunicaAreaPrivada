<?php
/**
 * PASAR LISTA — etapas y grupos.
 * ----------------------------------------------------------------------------
 * El árbol: MIC, COM, LC, y dentro los grupos de la delegación. Tu grupo va
 * primero y destacado, pero cualquiera es tocable: un monitor puede pasar lista
 * de cualquier grupo porque a veces cubre a un compañero.
 *
 * Con ?grupo=<id>&sesiones=1 se convierte en el SELECTOR DE SESIÓN de ese
 * grupo, con el estado de cada lista. Es la misma pantalla porque es la misma
 * pregunta hecha de dos formas: "¿de qué grupo?" y "¿de qué día?".
 *
 * Diseño: docs/comunica/PASAR-LISTA.md §6.2
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageSettings['fileName'] = basename(__FILE__, ".php");

$groups = sticpa_pl_groups($objSCP);
$myGroups = sticpa_pl_my_groups($objSCP);
$events = sticpa_pl_etapa_events($objSCP);

$wantSessions = !empty($_REQUEST['sesiones']);
$groupId = isset($_REQUEST['grupo']) ? sticpa_pl_safe_id($_REQUEST['grupo']) : '';

// ---------------------------------------------------------------------------
// Selector de sesión de un grupo
// ---------------------------------------------------------------------------

if ($wantSessions && $groupId !== '' && isset($groups[$groupId])) {
    $group = $groups[$groupId];
    $etapa = sticpa_pl_group_etapa($group['level']);
    $event = isset($events[$etapa]) ? $events[$etapa] : null;
    $sessions = ($event !== null) ? sticpa_pl_event_sessions($objSCP, $event['id']) : array();

    $html .= '<div class="pl-head">';
    $html .= '<a class="pl-back" href="?internalpage=single_stic_pasar_lista_marcar&grupo=' . esc_attr($groupId) . '"'
        . ' aria-label="' . esc_attr__('Volver', 'sticpa') . '">' . sticpa_pl_icon('back') . '</a>';
    $html .= '<div class="pl-head-titles">';
    $html .= '<div class="pl-title"><span class="pl-title-code">' . esc_html($group['code']) . '</span>';
    if ($group['name'] !== '') {
        $html .= '<span class="pl-title-name">' . esc_html($group['name']) . '</span>';
    }
    $html .= '</div>';
    $html .= '<div class="pl-subtitle">' . esc_html__('Elige la sesión', 'sticpa') . '</div>';
    $html .= '</div>';

    // Solo las sesiones ya celebradas, de la más reciente a la más antigua: se
    // pasa lista de lo que ya ha pasado. Y se puede pasar de cualquier día
    // anterior, que era el requisito.
    $elapsed = array_reverse(sticpa_pl_elapsed_sessions($sessions));

    if (empty($elapsed)) {
        $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
            . esc_html__('Todavía no ha habido ninguna sesión.', 'sticpa') . '</span></p>';
        return;
    }

    $estados = sticpa_pl_lista_estados();
    $html .= '<div class="pl-list">';
    foreach ($elapsed as $s) {
        // Una consulta por sesión: aquí sí se acepta, porque el selector se abre
        // de vez en cuando y enseñar el estado de cada lista es justo su razón
        // de ser. La caché del estado lo amortigua.
        $lista = sticpa_pl_lista($objSCP, $s['id'], $groupId);
        $estado = ($lista !== null) ? $lista['estado'] : '';
        $mark = sticpa_pl_list_mark($estado, $s['start']);

        $doneClass = 'pl-done--no';
        $doneInner = '';
        $meta = esc_html__('Sin pasar', 'sticpa');
        if ($mark === 'ok') {
            $doneClass = 'pl-done--yes';
            $doneInner = sticpa_pl_glyph('check');
            $meta = ($lista !== null)
                ? esc_html(sprintf(
                    /* translators: 1: cuántos vinieron, 2: cuántas ausencias */
                    __('%1$d vinieron · %2$d ausencias', 'sticpa'),
                    $lista['n_asistieron'],
                    $lista['n_faltaron']
                ))
                : esc_html__('Pasada', 'sticpa');
        } elseif ($mark === 'skip') {
            $doneClass = 'pl-done--skip';
            $meta = esc_html__('Sin registro', 'sticpa');
        }

        $html .= '<a class="pl-group" href="?internalpage=single_stic_pasar_lista_marcar&grupo='
            . esc_attr($groupId) . '&sesion=' . esc_attr($s['id']) . '">';
        $html .= '<span class="pl-group-body">';
        $html .= '<span class="pl-name">' . esc_html(sticpa_pl_session_label($s)) . '</span>';
        $html .= '<span class="pl-group-meta">' . $meta . '</span>';
        $html .= '</span>';
        $html .= '<span class="pl-done ' . esc_attr($doneClass) . '">' . $doneInner . '</span>';
        $html .= '<span class="pl-detail">' . sticpa_pl_icon('next') . '</span>';
        $html .= '</a>';
    }
    $html .= '</div>';
    return;
}

// ---------------------------------------------------------------------------
// Árbol de etapas y grupos
// ---------------------------------------------------------------------------

$html .= '<div class="pl-head">';
$html .= '<a class="pl-back" href="?internalpage=single_stic_pasar_lista"'
    . ' aria-label="' . esc_attr__('Volver', 'sticpa') . '">' . sticpa_pl_icon('back') . '</a>';
$html .= '<div class="pl-head-titles">';
$html .= '<div class="pl-title"><span class="pl-title-code">' . esc_html__('Pasar lista', 'sticpa') . '</span></div>';
$html .= '<div class="pl-subtitle">' . esc_html(sticpa_pl_course_for()['label']) . '</div>';
$html .= '</div>';
$html .= '<a class="pl-session-pick" href="?internalpage=single_stic_pasar_lista_grupos&refrescar=1"'
    . ' aria-label="' . esc_attr__('Refrescar datos', 'sticpa') . '">' . sticpa_pl_icon('refresh') . '</a>';
$html .= '</div>';

if (empty($groups)) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No hay grupos de tu delegación en este curso. Si crees que es un error, avisa a coordinación.', 'sticpa')
        . '</span></p>';
    return;
}

// Etapa por grupo, y los tuyos separados para pintarlos primero.
$byEtapa = array();
$mine = array();
foreach ($groups as $id => $g) {
    if (in_array($id, $myGroups, true)) {
        $mine[$id] = $g;
        continue;
    }
    $etapa = sticpa_pl_group_etapa($g['level']);
    if ($etapa === '') {
        $etapa = '?';
    }
    $byEtapa[$etapa][$id] = $g;
}

// El color del punto de cada etapa: los mismos del resumen.
$etapaDots = array('MIC' => 'var(--danger-color)', 'COM' => 'var(--success-color)', 'LC' => 'var(--primary-color)');

/** El nombre del monitor no está aquí (haría una consulta por grupo), así que
 *  la línea secundaria dice lo que sí sabemos sin coste: la etapa y el curso. */
$groupMeta = function ($g) {
    $bits = array();
    $etapa = sticpa_pl_group_etapa($g['level']);
    if ($etapa !== '') {
        $bits[] = $etapa;
    }
    if (!empty($g['cursos'])) {
        $bits[] = $g['cursos'];
    }
    return implode(' · ', $bits);
};

// Tu grupo, primero y con el filete de degradado.
foreach ($mine as $id => $g) {
    // El nombre completo ya está en sesión desde el login; de ahí salen las
    // iniciales sin una consulta más.
    $initials = sticpa_pl_initials('', '', isset($_SESSION['scp_user_contact_name']) ? $_SESSION['scp_user_contact_name'] : '');
    $html .= '<div class="pl-mine">';
    $html .= '<a class="pl-mine-inner" href="?internalpage=single_stic_pasar_lista_marcar&grupo=' . esc_attr($id) . '">';
    $html .= '<span class="pl-mine-shine" aria-hidden="true"></span>';
    $html .= '<span class="pl-mine-avatar">' . esc_html($initials) . '</span>';
    $html .= '<span class="pl-group-body">';
    $html .= '<span class="pl-title"><span class="pl-title-code">' . esc_html($g['code']) . '</span>';
    if ($g['name'] !== '') {
        $html .= '<span class="pl-title-name">' . esc_html($g['name']) . '</span>';
    }
    $html .= '</span>';
    $html .= '<span class="pl-group-meta"><span class="pl-mine-tag">' . esc_html__('Tu grupo', 'sticpa') . '</span>'
        . ($groupMeta($g) !== '' ? ' · ' . esc_html($groupMeta($g)) : '') . '</span>';
    $html .= '</span>';
    $html .= '<span class="pl-detail">' . sticpa_pl_icon('next') . '</span>';
    $html .= '</a>';
    $html .= '</div>';
}

// El resto, por etapa. El orden es el de siempre: MIC, COM, LC.
foreach (array('MIC', 'COM', 'LC', '?') as $etapa) {
    if (empty($byEtapa[$etapa])) {
        continue;
    }
    $dot = isset($etapaDots[$etapa]) ? $etapaDots[$etapa] : 'var(--gray-300)';
    $title = ($etapa === '?') ? __('Sin etapa', 'sticpa') : $etapa;

    $html .= '<div class="pl-etapa-title">'
        . '<span class="pl-etapa-dot" style="background:' . esc_attr($dot) . '"></span>'
        . esc_html($title) . '</div>';
    $html .= '<div class="pl-list">';
    foreach ($byEtapa[$etapa] as $id => $g) {
        $html .= '<a class="pl-group" href="?internalpage=single_stic_pasar_lista_marcar&grupo=' . esc_attr($id) . '">';
        $html .= '<span class="pl-group-body">';
        $html .= '<span class="pl-title"><span class="pl-title-code">' . esc_html($g['code']) . '</span>';
        if ($g['name'] !== '') {
            $html .= '<span class="pl-title-name">' . esc_html($g['name']) . '</span>';
        }
        $html .= '</span>';
        if ($groupMeta($g) !== '') {
            $html .= '<span class="pl-group-meta">' . esc_html($groupMeta($g)) . '</span>';
        }
        $html .= '</span>';
        $html .= '<span class="pl-detail">' . sticpa_pl_icon('next') . '</span>';
        $html .= '</a>';
    }
    $html .= '</div>';
}
