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

// El botón de refrescar de la cabecera. Tiene que ir ANTES de la primera
// lectura: si no, se pinta con la caché vieja y hay que pulsarlo dos veces.
sticpa_pl_maybe_refresh($objSCP);

// UNA TANDA en vez de cuatro viajes en fila. Lo que ya esté en caché no entra:
// la recolecta pasa por los mismos cargadores y ellos miran su caché primero.
sticpa_pl_prime($objSCP, function () use ($objSCP) {
    sticpa_pl_groups($objSCP);
    sticpa_pl_my_groups($objSCP);
    sticpa_pl_all_relationships($objSCP);
    sticpa_pl_etapa_events($objSCP);
    sticpa_pl_all_listas($objSCP);
});

$groups = sticpa_pl_groups($objSCP);
$myGroups = sticpa_pl_my_groups($objSCP);
$events = sticpa_pl_etapa_events($objSCP);

// TANDA 2: las sesiones de todas las etapas de golpe.
sticpa_pl_prime($objSCP, function () use ($objSCP, $events) {
    foreach ($events as $ev) {
        sticpa_pl_event_sessions($objSCP, $ev['id']);
    }
});

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
    $html .= '<div class="pl-subtitle">' . esc_html__('Historial de listas', 'sticpa') . '</div>';
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

/* La SECCIÓN, si se ha entrado por una: MIC, COM I… El artboard `Grupos` no
 * enseña las veintiocho filas de la delegación, enseña las cinco de su sección,
 * y la cabecera dice cuál es y cuántas hay. Sin `seccion` se ve el árbol
 * entero, que sigue existiendo como destino. */
$seccion = isset($_REQUEST['seccion']) ? (string) $_REQUEST['seccion'] : '';
$seccionLabel = '';
$dotsByEtapa = array('MIC' => 'var(--danger-color)', 'COM' => 'var(--success-color)', 'LC' => 'var(--primary-color)');
$seccionDot = '';

if ($seccion !== '') {
    foreach (sticpa_pl_group_buckets($groups) as $b) {
        if ($b['key'] === $seccion) {
            $seccionLabel = $b['label'];
            $seccionDot = isset($b['dot']) ? $b['dot'] : 'var(--gray-300)';
            break;
        }
    }
    // Una sección que no existe (url a mano, o el grupo cambió de segmento) no
    // deja la pantalla vacía: se cae al árbol entero.
    if ($seccionLabel === '') {
        $seccion = '';
    } else {
        $groups = array_filter($groups, function ($g) use ($seccion) {
            return sticpa_pl_group_in_bucket($g, $seccion);
        });
    }
}

$html .= '<div class="pl-head">';
$html .= '<a class="pl-back" href="?internalpage=single_stic_pasar_lista"'
    . ' aria-label="' . esc_attr__('Volver', 'sticpa') . '">' . sticpa_pl_icon('back') . '</a>';
$html .= '<div class="pl-head-titles">';
if ($seccion !== '') {
    $html .= '<div class="pl-title">'
        . '<span class="pl-etapa-dot" style="background:' . esc_attr($seccionDot) . '"></span>'
        . '<span class="pl-title-code">' . esc_html($seccionLabel) . '</span>'
        . '<span class="pl-title-count">' . esc_html(sprintf(
            /* translators: %d: número de grupos de la sección */
            _n('%d grupo', '%d grupos', count($groups), 'sticpa'),
            count($groups)
        )) . '</span></div>';
} else {
    $html .= '<div class="pl-title"><span class="pl-title-code">' . esc_html__('Pasar lista', 'sticpa') . '</span></div>';
    $html .= '<div class="pl-subtitle">' . esc_html(sticpa_pl_course_for()['label']) . '</div>';
}
$html .= '</div>';
$html .= '<a class="pl-session-pick" href="?internalpage=single_stic_pasar_lista_grupos&refrescar=1'
    . ($seccion !== '' ? '&seccion=' . rawurlencode($seccion) : '') . '"'
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

/* El estado de la ÚLTIMA lista de cada grupo. Sin esto el árbol es una lista de
 * nombres y no dice lo único que se quiere saber al entrar: de qué grupo falta.
 *
 * Es UNA consulta por etapa, no una por grupo: `sticpa_pl_listas_by_session()`
 * con límite 1 trae las listas de todos los grupos de esa sesión de golpe. Tres
 * etapas, tres llamadas, y las mismas tanto para 5 grupos como para 30. */
$lastMarks = array();
foreach ($events as $etapaKey => $event) {
    // Los grupos de ESTA etapa, los tuyos incluidos: "tu grupo" se pinta aparte
    // y arriba, pero el círculo de estado es el mismo dato y lo necesita igual.
    $etapaIds = array();
    foreach ($groups as $gid => $g) {
        if (sticpa_pl_group_etapa($g['level']) === $etapaKey) {
            $etapaIds[] = $gid;
        }
    }
    if (empty($etapaIds)) {
        continue;
    }
    $grid = sticpa_pl_listas_by_session($objSCP, sticpa_pl_event_sessions($objSCP, $event['id']), 1);
    foreach ($grid as $cell) {
        foreach ($etapaIds as $gid) {
            $lista = isset($cell['listas'][$gid]) ? $cell['listas'][$gid] : null;
            $lastMarks[$gid] = sticpa_pl_list_mark(
                ($lista !== null) ? $lista['estado'] : '',
                $cell['session']['start']
            );
        }
    }
}

/** El círculo de estado de un grupo, con el mismo glifo que la leyenda. */
$doneMark = function ($gid) use ($lastMarks) {
    $mark = isset($lastMarks[$gid]) ? $lastMarks[$gid] : '';
    if ($mark === 'ok') {
        return '<span class="pl-done pl-done--yes">' . sticpa_pl_glyph('check') . '</span>';
    }
    if ($mark === 'skip') {
        return '<span class="pl-done pl-done--skip">' . sticpa_pl_icon('skip') . '</span>';
    }
    if ($mark === 'gap') {
        return '<span class="pl-done pl-done--no"></span>';
    }
    return '';      // sin sesiones celebradas todavía: no hay nada que decir
};

// El buscador del artboard `Grupos`. Con veintiocho grupos en Castellón,
// encontrar el tuyo era leer la lista entera; el diseño lo pedía y no estaba.
//
// Filtra lo YA PINTADO, en el navegador: ni una consulta más, ni una recarga.
// Busca por código, por nombre del grupo, por curso y por los monitores, que es
// justo lo que la gente recuerda («el de Mercedes», «el de 1º ESO»).
// Se pinta solo si hay bastantes grupos para que haga falta: un buscador sobre
// cuatro filas es un trasto.
if (count($groups) >= 8) {
    $html .= '<div class="pl-search">';
    $html .= sticpa_pl_icon('search');
    $html .= '<input type="search" data-pl-filter'
        . ' placeholder="' . esc_attr__('Buscar grupo, monitor o curso…', 'sticpa') . '"'
        . ' aria-label="' . esc_attr__('Buscar grupo', 'sticpa') . '"'
        . ' autocomplete="off" enterkeyhint="search">';
    $html .= '</div>';
    $html .= '<p class="pl-search-empty" data-pl-filter-empty hidden>'
        . esc_html__('Ningún grupo coincide con lo que buscas.', 'sticpa') . '</p>';
}

// La leyenda, arriba y una sola vez. Los tres estados que puede tener el
// círculo, con el mismo glifo y el mismo color que en la fila.
if (!empty($lastMarks)) {
    $html .= '<div class="pl-tree-legend">';
    $html .= '<span class="pl-legend-item"><span class="pl-done pl-done--yes">'
        . sticpa_pl_glyph('check') . '</span><span class="pl-legend-label">'
        . esc_html__('Pasada', 'sticpa') . '</span></span>';
    $html .= '<span class="pl-legend-item"><span class="pl-done pl-done--no"></span>'
        . '<span class="pl-legend-label">' . esc_html__('Pendiente', 'sticpa') . '</span></span>';
    $html .= '<span class="pl-legend-item"><span class="pl-done pl-done--skip">'
        . sticpa_pl_icon('skip') . '</span><span class="pl-legend-label">'
        . esc_html__('No hubo', 'sticpa') . '</span></span>';
    $html .= '</div>';
}

// Si hay grupos fuera de Pasar Lista por la casilla del CRM, se DICE. Un grupo
// que existe y no aparece, sin explicación, se lee como que la pantalla está
// mal — y alguien acaba buscando el fallo donde no está.
$ocultos = sticpa_pl_grupos_ocultos($objSCP);
if ($ocultos > 0) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>' . esc_html(sprintf(
        /* translators: %d: cuántos grupos no salen */
        _n(
            'Hay %d grupo más en el CRM que no está marcado para Pasar Lista.',
            'Hay %d grupos más en el CRM que no están marcados para Pasar Lista.',
            $ocultos,
            'sticpa'
        ),
        $ocultos
    )) . '</span></p>';
}

/* La línea de datos del artboard `Grupos`: monitores · curso · N participantes.
 * Los tres salen del recuento nocturno que el Guardián deja en el propio grupo,
 * así que no cuestan ninguna consulta más (PASAR-LISTA-RECUENTOS.md).
 *
 * La ETAPA ya no va aquí: el árbol agrupa por etapa, así que repetirla en cada
 * fila era ruido — ponía «MIC · 4º Primaria» debajo de una cabecera que ya
 * dice MIC. */
$groupMeta = function ($g) {
    return implode(' · ', sticpa_pl_group_meta($g));
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
    $html .= $doneMark($id);
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
        $html .= $doneMark($id);
        $html .= '<span class="pl-detail">' . sticpa_pl_icon('next') . '</span>';
        $html .= '</a>';
    }
    $html .= '</div>';
}

// ---------------------------------------------------------------------------
// Los que no aparecen en ninguna lista
// ---------------------------------------------------------------------------

/* La tarjeta ámbar con borde discontinuo del artboard `Grupos`, al final del
 * árbol. Va AQUÍ y no solo en el resumen porque es donde se nota el problema:
 * pasas lista, faltan chavales, y la explicación es que no están en ningún
 * grupo. Enlaza al resumen, que es donde coordinación puede arreglarlo.
 *
 * No cuesta una llamada: sale del mismo mapa de relaciones que ya trajo las
 * personas de los grupos. */
$sinGrupo = sticpa_pl_participants_without_group($objSCP);
if (!empty($sinGrupo)) {
    $html .= '<a class="pl-orphans" href="?internalpage=single_stic_pasar_lista_resumen">';
    $html .= '<span class="pl-orphans-icon">' . sticpa_pl_icon('person') . '</span>';
    $html .= '<span class="pl-orphans-body">';
    $html .= '<span class="pl-orphans-title">' . esc_html(sprintf(
        /* translators: %d: participantes sin grupo asignado */
        _n('%d participante sin grupo asignado', '%d participantes sin grupo asignado', count($sinGrupo), 'sticpa'),
        count($sinGrupo)
    )) . '</span>';
    $html .= '<span class="pl-orphans-sub">' . esc_html__('No aparecen en ninguna lista', 'sticpa') . '</span>';
    $html .= '</span>';
    $html .= '<span class="pl-detail">' . sticpa_pl_icon('next') . '</span>';
    $html .= '</a>';
}
