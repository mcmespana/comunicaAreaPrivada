<?php
/**
 * PASAR LISTA — resumen para coordinación.
 * ----------------------------------------------------------------------------
 * Tres cosas, en este orden:
 *   1. Cuántos hay por etapa.
 *   2. Qué listas faltan, con la TIRA por grupo: una marca por sesión ya
 *      celebrada, la más reciente a la derecha. Un grupo al día es una tira
 *      verde; un grupo dejado se ve como un tramo de huecos, y se ve DÓNDE
 *      empezó a dejarse. Eso contesta a la vez "¿pasaron la última?" y "¿qué
 *      días les faltan?", que era la duda.
 *   3. Datos por revisar: los problemas clásicos que en el CRM cuestan de ver.
 *      Coordinación los arregla desde aquí; un monitor los ve y no los edita.
 *
 * Diseño: docs/comunica/PASAR-LISTA.md §6.5
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageSettings['fileName'] = basename(__FILE__, ".php");

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
$events = sticpa_pl_etapa_events($objSCP);

// TANDA 2: las sesiones de todas las etapas de golpe. El resumen las pide por
// etapa y eran tantos viajes como etapas.
sticpa_pl_prime($objSCP, function () use ($objSCP, $events) {
    // POR ID Y SIN REPETIR. `$events` va por etapa, y MIC y COM comparten el
    // mismo evento: el bucle pedía sus sesiones DOS veces. Dentro de una tanda
    // eso son dos peticiones de verdad, porque el memo se consume de un solo
    // uso (la lección de las dos parejas de consultas del 28/08).
    foreach (array_unique(array_column($events, 'id')) as $evId) {
        sticpa_pl_event_sessions($objSCP, $evId);
    }
});
$course = sticpa_pl_course_for();
$isCoord = sticpa_pl_is_coordinator($objSCP);

// Cuántas sesiones entran en la tira. Cada una es una consulta, así que se
// limita y se DICE, en vez de recortar en silencio.
$stripLimit = (int) apply_filters('sticpa_pl_resumen_strip_sessions', 12);

// ---------------------------------------------------------------------------
// Asignar grupo (solo coordinación)
// ---------------------------------------------------------------------------

$assignMsg = '';
if (!empty($_POST['pl_assign_rel'])) {
    if (!isset($_POST['pl_nonce']) || !wp_verify_nonce($_POST['pl_nonce'], 'pl_resumen')) {
        $assignMsg = __('La sesión ha caducado. Vuelve a cargar la pantalla.', 'sticpa');
    } elseif (!$isCoord) {
        $assignMsg = __('Solo coordinación puede asignar grupos.', 'sticpa');
    } else {
        $ok = sticpa_pl_assign_group($objSCP, $_POST['pl_assign_rel'], isset($_POST['pl_assign_group']) ? $_POST['pl_assign_group'] : '');
        $assignMsg = $ok
            ? __('Grupo asignado.', 'sticpa')
            : __('No se ha podido asignar el grupo.', 'sticpa');
        $groups = sticpa_pl_groups($objSCP);
    }
}

// ---------------------------------------------------------------------------
// Cabecera
// ---------------------------------------------------------------------------

$html .= '<div class="pl-head">';
$html .= '<a class="pl-back" href="?internalpage=single_stic_pasar_lista"'
    . ' aria-label="' . esc_attr__('Volver', 'sticpa') . '">' . sticpa_pl_icon('back') . '</a>';
$html .= '<div class="pl-head-titles">';
$html .= '<div class="pl-title"><span class="pl-title-code">' . esc_html__('Resumen de grupos', 'sticpa') . '</span></div>';
$html .= '<div class="pl-subtitle">' . esc_html($course['label']) . '</div>';
$html .= '</div>';
$html .= '<a class="pl-session-pick" href="?internalpage=single_stic_pasar_lista_resumen&refrescar=1"'
    . ' aria-label="' . esc_attr__('Refrescar datos', 'sticpa') . '">' . sticpa_pl_icon('refresh') . '</a>';
$html .= '</div>';

if ($assignMsg !== '') {
    $html .= '<p class="pl-notice"><span>' . esc_html($assignMsg) . '</span></p>';
}

if (empty($groups)) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No hay grupos de tu delegación en este curso.', 'sticpa') . '</span></p>';
    return;
}

// ---------------------------------------------------------------------------
// Recuentos por etapa
// ---------------------------------------------------------------------------

$byEtapa = array();
foreach ($groups as $id => $g) {
    $etapa = sticpa_pl_group_etapa($g['level']);
    if ($etapa === '') {
        $etapa = '?';
    }
    $byEtapa[$etapa][$id] = $g;
}

$etapaColors = array('MIC' => 'var(--danger-color)', 'COM' => 'var(--success-color)', 'LC' => 'var(--primary-color)');

$html .= '<div class="pl-cards">';
foreach (array('MIC', 'COM', 'LC') as $etapa) {
    if (empty($byEtapa[$etapa])) {
        continue;
    }
    /* El artboard `Resumen` pone el número de PARTICIPANTES como número grande
     * y «8 grupos · 12 mon.» debajo. El número grande era el de grupos, que ya
     * está en la línea de abajo: dos veces el mismo dato y ninguno el que se
     * busca al abrir el resumen. Los participantes y los monitores salen del
     * recuento nocturno, así que no cuestan una consulta.
     *
     * Si no hay recuentos frescos en esa etapa, el número grande vuelve a ser
     * el de grupos y la línea de abajo NO lo repite: un número inventado no, y
     * el mismo dato dos veces tampoco. */
    $nGrupos = count($byEtapa[$etapa]);
    $nPart = 0;
    $nMon = 0;
    $fresco = false;
    foreach ($byEtapa[$etapa] as $g) {
        if (!sticpa_pl_recuento_fresco(isset($g['recuento_al']) ? $g['recuento_al'] : '')) {
            continue;
        }
        if (isset($g['n_participantes']) && (int) $g['n_participantes'] >= 0) {
            $nPart += (int) $g['n_participantes'];
            $fresco = true;
        }
        if (isset($g['n_monitores']) && (int) $g['n_monitores'] >= 0) {
            $nMon += (int) $g['n_monitores'];
        }
    }

    $html .= '<div class="pl-card">';
    $html .= '<div class="pl-card-head"><span class="pl-etapa-dot" style="background:'
        . esc_attr($etapaColors[$etapa]) . '"></span>' . esc_html($etapa) . '</div>';
    $html .= '<div class="pl-card-num">' . esc_html($fresco ? (string) $nPart : (string) $nGrupos) . '</div>';

    $meta = array();
    if ($fresco) {
        $meta[] = sprintf(
            /* translators: %d: número de grupos de la etapa */
            _n('%d grupo', '%d grupos', $nGrupos, 'sticpa'),
            $nGrupos
        );
        if ($nMon > 0) {
            $meta[] = sprintf(
                /* translators: %d: número de monitores. Abreviado: cabe poco. */
                __('%d mon.', 'sticpa'),
                $nMon
            );
        }
    } else {
        $meta[] = _n('grupo', 'grupos', $nGrupos, 'sticpa');
    }
    $html .= '<div class="pl-card-meta">' . esc_html(implode(' · ', $meta)) . '</div>';
    $html .= '</div>';
}
$html .= '</div>';

// ---------------------------------------------------------------------------
// La tira de listas, por etapa
// ---------------------------------------------------------------------------

$estados = sticpa_pl_lista_estados();

/* La tira se pinta en un buffer y no directamente, porque la tarjeta de "la
 * última sesión" va ENCIMA y sale de estos mismos datos. Recorrer dos veces
 * costaría el doble de consultas; pintar en orden inverso al que se lee, un
 * bloque desordenado. Así se recorre una vez y se emite en el orden bueno. */
$stripHtml = '';
$lastDone = 0;      // listas de la última sesión que ya están (pasada o sin registro)
$lastTotal = 0;     // grupos que deberían tenerla
$lastDates = array();

foreach (array('MIC', 'COM', 'LC') as $etapa) {
    if (empty($byEtapa[$etapa]) || !isset($events[$etapa])) {
        continue;
    }
    $sessions = sticpa_pl_event_sessions($objSCP, $events[$etapa]['id']);
    $grid = sticpa_pl_listas_by_session($objSCP, $sessions, $stripLimit);
    if (empty($grid)) {
        continue;
    }

    $stripHtml .= '<div class="pl-etapa-title">'
        . '<span class="pl-etapa-dot" style="background:' . esc_attr($etapaColors[$etapa]) . '"></span>'
        . esc_html($etapa) . '</div>';
    $stripHtml .= '<div class="pl-list">';

    foreach ($byEtapa[$etapa] as $gid => $g) {
        $cells = '';
        $gaps = 0;
        $lastMark = '';

        foreach ($grid as $sid => $cell) {
            $lista = isset($cell['listas'][$gid]) ? $cell['listas'][$gid] : null;
            $estado = ($lista !== null) ? $lista['estado'] : '';
            $mark = sticpa_pl_list_mark($estado, $cell['session']['start']);
            if ($mark === 'gap') {
                $gaps++;
            }
            $lastMark = $mark;
            $cells .= '<span class="pl-cell pl-cell--' . esc_attr($mark) . '"'
                . ' title="' . esc_attr(sticpa_pl_session_label($cell['session'], false)) . '"></span>';
        }

        // La pastilla dice el estado de la ÚLTIMA sesión, que es la pregunta
        // frecuente; el número de huecos contesta la de fondo.
        $badge = __('Al día', 'sticpa');
        $badgeClass = 'pl-badge--ok';
        if ($lastMark === 'gap') {
            $badge = __('Falta', 'sticpa');
            $badgeClass = 'pl-badge--gap';
        } elseif ($lastMark === 'skip') {
            $badge = __('Sin registro', 'sticpa');
            $badgeClass = 'pl-badge--skip';
        }

        $stripHtml .= '<a class="pl-grouprow" href="?internalpage=single_stic_pasar_lista_marcar&grupo=' . esc_attr($gid) . '">';
        $stripHtml .= '<div class="pl-grouprow-top">';
        $stripHtml .= '<span class="pl-group-body">';
        $stripHtml .= '<span class="pl-title"><span class="pl-title-code">' . esc_html($g['code']) . '</span>';
        if ($g['name'] !== '') {
            $stripHtml .= '<span class="pl-title-name">' . esc_html($g['name']) . '</span>';
        }
        $stripHtml .= '</span></span>';
        $stripHtml .= '<span class="pl-badge ' . esc_attr($badgeClass) . '">' . esc_html($badge) . '</span>';
        $stripHtml .= '</div>';
        $stripHtml .= '<div class="pl-strip">' . $cells
            . '<span class="pl-strip-note' . ($gaps > 0 ? ' pl-strip-note--gap' : '') . '">'
            . esc_html($gaps === 0
                ? __('todas pasadas', 'sticpa')
                : sprintf(
                    /* translators: %d: listas sin pasar */
                    _n('%d sin pasar', '%d sin pasar', $gaps, 'sticpa'),
                    $gaps
                ))
            . '</span></div>';
        $stripHtml .= '</a>';

        /* La última sesión de ESTA etapa: `$lastMark` acaba de quedarse con la
         * marca de la celda más a la derecha, que es justo esa. "Hecha" incluye
         * el "sin registro": una lista que alguien ha cerrado a conciencia no
         * es una que falte. */
        if ($lastMark !== '') {
            $lastTotal++;
            if ($lastMark === 'ok' || $lastMark === 'skip') {
                $lastDone++;
            }
        }
    }
    $stripHtml .= '</div>';

    // La fecha de la última sesión celebrada de la etapa, para poder decir en
    // la tarjeta de arriba de qué día se está hablando.
    $lastCell = end($grid);
    if (is_array($lastCell) && !empty($lastCell['session']['start'])) {
        $lastDates[(int) $lastCell['session']['start']] = true;
    }
}

/* La pregunta con la que se abre esta pantalla: "¿pasaron lista el sábado?".
 * Va arriba porque es la primera que se hace, y con numerador Y denominador:
 * "17" a secas no dice si van bien o mal.
 *
 * Si las etapas tienen su última sesión en días distintos, la tarjeta no se
 * inventa una fecha común: dice "última sesión de cada etapa". Poner una sola
 * fecha sería mentir sobre la mitad del recuento. */
if ($lastTotal > 0) {
    $pct = (int) round(($lastDone / $lastTotal) * 100);
    $when = (count($lastDates) === 1)
        ? sprintf(
            /* translators: %s: fecha corta de la última sesión */
            __('Última sesión · %s', 'sticpa'),
            date_i18n('D j M', (int) key($lastDates))
        )
        : __('Última sesión de cada etapa', 'sticpa');

    $html .= '<div class="pl-lasthero">';
    $html .= '<div class="pl-lasthero-body">';
    $html .= '<span class="pl-lasthero-when">' . esc_html($when) . '</span>';
    $html .= '<span class="pl-lasthero-num">' . esc_html(sprintf(
        /* translators: 1: listas hechas, 2: listas que tocaban */
        __('%1$d de %2$d listas', 'sticpa'),
        $lastDone,
        $lastTotal
    )) . '</span>';
    $missing = $lastTotal - $lastDone;
    $html .= '<span class="pl-lasthero-meta">' . esc_html($missing === 0
        ? __('todas pasadas', 'sticpa')
        : sprintf(
            /* translators: %d: grupos que no la han pasado */
            _n('%d grupo sin pasarla todavía', '%d grupos sin pasarla todavía', $missing, 'sticpa'),
            $missing
        )) . '</span>';
    $html .= '</div>';
    $html .= '<span class="pl-lasthero-pct">' . esc_html($pct) . '%</span>';
    $html .= '</div>';
}

$html .= $stripHtml;

// La leyenda de la tira, una sola vez.
$html .= '<div class="pl-legend">';
foreach (array(
    'ok' => __('Pasada', 'sticpa'),
    'gap' => __('Falta', 'sticpa'),
    'skip' => __('Sin registro', 'sticpa'),
) as $mark => $label) {
    $html .= '<span class="pl-legend-item">'
        . '<span class="pl-cell pl-cell--' . esc_attr($mark) . '"></span>'
        . '<span class="pl-legend-label">' . esc_html($label) . '</span></span>';
}
$html .= '</div>';
$html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>' . esc_html(sprintf(
    /* translators: %d: número de sesiones que entran en la tira */
    _n('La tira enseña la última sesión.', 'La tira enseña las últimas %d sesiones.', $stripLimit, 'sticpa'),
    $stripLimit
)) . '</span></p>';

// ---------------------------------------------------------------------------
// Datos por revisar
// ---------------------------------------------------------------------------

$noGroup = sticpa_pl_participants_without_group($objSCP);
// El código es lo que se lee en pantalla; sin él, el grupo se identifica por su
// nombre largo y la lista se vuelve ilegible.
$noCode = array();
foreach ($groups as $gid => $g) {
    if (trim($g['code']) === '') {
        $noCode[$gid] = $g;
    }
}

if (!empty($noGroup) || !empty($noCode)) {
    $html .= '<div class="pl-etapa-title">'
        . '<span class="pl-etapa-dot" style="background:var(--warning-color)"></span>'
        . esc_html__('Datos por revisar', 'sticpa') . '</div>';
    $html .= '<div class="pl-review">';

    if (!empty($noGroup)) {
        $html .= '<div class="pl-review-head">' . esc_html(sprintf(
            /* translators: %d: participantes sin grupo */
            _n('%d participante sin grupo asignado', '%d participantes sin grupo asignado', count($noGroup), 'sticpa'),
            count($noGroup)
        )) . '</div>';

        $html .= '<form method="post">';
        $html .= wp_nonce_field('pl_resumen', 'pl_nonce', true, false);
        foreach ($noGroup as $row) {
            $html .= '<div class="pl-review-row">';
            $html .= '<span class="pl-avatar">' . esc_html($row['initials']) . '</span>';
            $html .= '<span class="pl-review-name">' . esc_html($row['name']) . '</span>';

            if ($isCoord) {
                // Coordinación lo arregla aquí mismo: es más fácil de ver aquí
                // que en el CRM, y por eso se puede tocar aquí.
                $html .= '<select name="pl_assign_group" class="pl-review-select">';
                $html .= '<option value="">' . esc_html__('Elegir grupo…', 'sticpa') . '</option>';
                foreach ($groups as $gid => $g) {
                    $html .= '<option value="' . esc_attr($gid) . '">'
                        . esc_html($g['code'] . ($g['name'] !== '' ? ' · ' . $g['name'] : '')) . '</option>';
                }
                $html .= '</select>';
                $html .= '<button type="submit" name="pl_assign_rel" value="' . esc_attr($row['rel_id'])
                    . '" class="pl-review-btn">' . esc_html__('Asignar', 'sticpa') . '</button>';
            }
            $html .= '</div>';
        }
        $html .= '</form>';
    }

    if (!empty($noCode)) {
        $html .= '<div class="pl-review-head">' . esc_html(sprintf(
            /* translators: %d: grupos sin código corto */
            _n('%d grupo sin código corto', '%d grupos sin código corto', count($noCode), 'sticpa'),
            count($noCode)
        )) . '</div>';
        foreach ($noCode as $g) {
            $html .= '<div class="pl-review-row"><span class="pl-review-name">'
                . esc_html($g['name'] !== '' ? $g['name'] : $g['code']) . '</span></div>';
        }
        $html .= '<p class="pl-hint" style="padding-left:0.85rem"><span>'
            . esc_html__('El código corto se pone en el CRM, en la ficha del grupo.', 'sticpa')
            . '</span></p>';
    }

    $html .= '</div>';

    if (!$isCoord) {
        $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
            . esc_html__('Coordinación puede arreglarlo desde aquí. Tú puedes verlo, pero no editarlo.', 'sticpa')
            . '</span></p>';
    }
}
