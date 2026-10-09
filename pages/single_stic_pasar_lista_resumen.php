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
    // El alcance viaja con lo demás: antes se pedía suelto, después de las
    // tandas, solo para saber si se podía asignar (plan 042, COO-1).
    sticpa_pl_coord_scope($objSCP);
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

/* EL ALCANCE (plan 042, COO-1). Coordinación, Lista de monitores y Reuniones
 * filtran por él y lo dicen en la cabecera; el Resumen contaba la delegación
 * entera, y a quien coordina el COM le decía «1 de 4 listas» cuando lo suyo
 * era 1 de 2: los otros dos huecos eran de MIC y de LC. Ahora lo de su alcance
 * va arriba y es lo único que se CUENTA; el resto de la delegación se puede
 * ver (un monitor raso ya ve el resumen entero), pero plegado y aparte, para
 * que nadie lo tome por suyo. Sin alcance acotado —toda la delegación, o quien
 * no coordina— la pantalla es la de siempre. */
$scope = sticpa_pl_coord_scope($objSCP);
$acotado = is_array($scope)
    && ((isset($scope['etapa']) && $scope['etapa'] !== '') || (isset($scope['segmento']) && $scope['segmento'] !== ''));
$mios = $acotado ? sticpa_pl_scoped_groups($objSCP, $scope) : $groups;
$resto = $acotado ? array_diff_key($groups, $mios) : array();

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
        list($relAsignar, $grupoAsignar) = sticpa_pl_assign_post();
        $ok = sticpa_pl_assign_group($objSCP, $relAsignar, $grupoAsignar);
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
// El alcance, de subtítulo como en las demás pantallas de coordinación
// (design.md §6.4): es lo que dice de quién es lo que se cuenta.
$html .= '<div class="pl-subtitle">' . esc_html(is_array($scope)
    ? $course['label'] . ' · ' . sticpa_pl_coord_scope_label($scope)
    : $course['label']) . '</div>';
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
foreach ($mios as $id => $g) {
    $etapa = sticpa_pl_group_etapa($g['level']);
    if ($etapa === '') {
        $etapa = '?';
    }
    $byEtapa[$etapa][$id] = $g;
}

$etapaColors = array('MIC' => 'var(--danger-color)', 'COM' => 'var(--success-color)', 'LC' => 'var(--primary-color)');

// Con alcance acotado puede haber una sola tarjeta (quien coordina el COM): la
// rejilla se ajusta a las que hay en vez de dejar dos huecos a la derecha.
$nCards = count(array_intersect_key($byEtapa, array('MIC' => 1, 'COM' => 1, 'LC' => 1)));
$html .= '<div class="pl-cards' . ($nCards < 3 ? ' pl-cards--n' . (int) $nCards : '') . '">';
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

/* La cuenta y las tiras salen de sticpa_pl_tiras_por_etapa(), que es la misma
 * que usa la portada de Coordinación: las dos dicen lo mismo del mismo sábado.
 * Se pintan en un buffer porque la tarjeta de «la última sesión» va ENCIMA y
 * sale de estos mismos datos. */
$tirasMias = sticpa_pl_tiras_por_etapa($objSCP, $mios, $events, $stripLimit);

/** Una tira por etapa: título y una fila por grupo. */
$pintaTiras = function ($tiras) use ($etapaColors) {
    $out = '';
    foreach ($tiras as $etapa => $tira) {
        $out .= '<div class="pl-etapa-title">'
            . '<span class="pl-etapa-dot" style="background:' . esc_attr($etapaColors[$etapa]) . '"></span>'
            . esc_html($etapa) . '</div>';
        $out .= '<div class="pl-list">';
        foreach ($tira['filas'] as $gid => $fila) {
            $g = $fila['group'];
            $gaps = $fila['gaps'];
            $lastMark = $fila['last'];
            $cells = '';
            foreach ($fila['marks'] as $sid => $mark) {
                /* CADA CELDA ABRE SU LISTA. Antes la tira era decoración dentro
                 * del enlace de la fila: veías el hueco de hace tres sábados y
                 * para corregirlo tenías que entrar al grupo y buscar la fecha
                 * a mano. Ahora es un toque.
                 *
                 * Van FUERA del `<a>` de la fila y no dentro: un enlace dentro
                 * de otro no es HTML válido y deja el de fuera inalcanzable con
                 * el teclado — el mismo motivo por el que la fila de marcar y
                 * su flecha son hermanas y no una dentro de otra. */
                $etiqueta = sticpa_pl_session_label($tira['grid'][$sid]['session'], false);
                $cells .= '<a class="pl-cell pl-cell--' . esc_attr($mark) . '"'
                    . ' href="?internalpage=single_stic_pasar_lista_marcar&grupo=' . esc_attr($gid)
                    . '&sesion=' . esc_attr($sid) . '"'
                    . ' title="' . esc_attr($etiqueta) . '"'
                    . ' aria-label="' . esc_attr(sprintf(
                        /* translators: 1: código del grupo, 2: fecha de la sesión */
                        __('Lista de %1$s del %2$s', 'sticpa'),
                        $g['code'],
                        $etiqueta
                    )) . '"></a>';
            }

            // La pastilla dice el estado de la ÚLTIMA sesión, que es la pregunta
            // frecuente; el número de huecos contesta la de fondo. Con el mismo
            // vocabulario que la tira, y «Al día» SOLO si no hay ningún hueco
            // (plan 042, PL-6): antes decía «Al día» con «2 sin pasar» debajo.
            $badge = __('Al día', 'sticpa');
            $badgeClass = 'pl-badge--ok';
            if ($lastMark === 'gap') {
                $badge = __('Falta', 'sticpa');
                $badgeClass = 'pl-badge--gap';
            } elseif ($lastMark === 'skip') {
                $badge = __('Sin registro', 'sticpa');
                $badgeClass = 'pl-badge--skip';
            } elseif ($gaps > 0) {
                $badge = __('Pasada', 'sticpa');
            }

            $out .= '<div class="pl-grouprow">';
            $out .= '<a class="pl-grouprow-top" href="?internalpage=single_stic_pasar_lista_marcar&grupo='
                . esc_attr($gid) . '">';
            $out .= '<span class="pl-group-body">';
            $out .= '<span class="pl-title"><span class="pl-title-code">' . esc_html($g['code']) . '</span>';
            if ($g['name'] !== '') {
                $out .= '<span class="pl-title-name">' . esc_html($g['name']) . '</span>';
            }
            $out .= '</span></span>';
            $out .= '<span class="pl-badge ' . esc_attr($badgeClass) . '">' . esc_html($badge) . '</span>';
            $out .= '</a>';
            /* EN EL MÓVIL LA TIRA ES UN SOLO ENLACE (plan 042, PL-6). Cada celda
             * mide 14×7 px con 3 px de aire: doce enlaces que un dedo no
             * distingue. Con puntero grueso las celdas son decoración y un
             * enlace que cubre la tira entera lleva al historial del grupo, que
             * pinta cada fecha en una fila de 44 px con su estado. Con ratón,
             * las celdas siguen abriendo su lista. Es HERMANO de las celdas, no
             * las envuelve: un enlace dentro de otro no es HTML válido. */
            $cells .= '<a class="pl-strip-link" href="?internalpage=single_stic_pasar_lista_grupos&grupo='
                . esc_attr($gid) . '&sesiones=1" aria-label="' . esc_attr(sprintf(
                    /* translators: %s: código del grupo */
                    __('Historial de listas de %s', 'sticpa'),
                    $g['code']
                )) . '"></a>';
            $out .= '<div class="pl-strip">' . $cells
                . '<span class="pl-strip-note' . ($gaps > 0 ? ' pl-strip-note--gap' : '') . '">'
                . esc_html($gaps === 0
                    ? __('todas pasadas', 'sticpa')
                    : sprintf(
                        /* translators: %d: listas sin pasar */
                        _n('%d sin pasar', '%d sin pasar', $gaps, 'sticpa'),
                        $gaps
                    ))
                . '</span></div>';
            $out .= '</div>';
        }
        $out .= '</div>';
    }
    return $out;
};

/* La pregunta con la que se abre esta pantalla: "¿pasaron lista el sábado?".
 * Va arriba porque es la primera que se hace, y con numerador Y denominador:
 * "17" a secas no dice si van bien o mal. Cuenta SOLO lo del alcance.
 *
 * Si las etapas tienen su última sesión en días distintos, la tarjeta no se
 * inventa una fecha común: dice "última sesión de cada etapa". Poner una sola
 * fecha sería mentir sobre la mitad del recuento. */
$ultima = sticpa_pl_ultima_sesion_estado($tirasMias);
$lastDone = $ultima['done'];
$lastTotal = $ultima['total'];
if ($lastTotal > 0) {
    $pct = (int) round(($lastDone / $lastTotal) * 100);
    $when = (count($ultima['fechas']) === 1)
        ? sprintf(
            /* translators: %s: fecha corta de la última sesión */
            __('Última sesión · %s', 'sticpa'),
            date_i18n('D j M', (int) $ultima['fechas'][0])
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
    /* Y CUÁLES (plan 042, COO-2): «3 grupos sin pasarla» obligaba a recorrer
     * las tiras de todas las etapas buscando la pastilla «Falta». Cada nombre
     * abre su lista de esa sesión. */
    if (!empty($ultima['faltan'])) {
        $html .= '<span class="pl-lasthero-miss">';
        foreach ($ultima['faltan'] as $f) {
            $html .= '<a class="pl-lasthero-chip" href="?internalpage=single_stic_pasar_lista_marcar&grupo='
                . esc_attr($f['gid']) . '&sesion=' . esc_attr($f['sid']) . '"'
                . ' aria-label="' . esc_attr(sprintf(
                    /* translators: %s: código del grupo */
                    __('Pasar la lista de %s', 'sticpa'),
                    $f['code']
                )) . '">' . esc_html($f['code']) . '</a>';
        }
        $html .= '</span>';
    }
    $html .= '</div>';
    $html .= '<span class="pl-lasthero-pct">' . esc_html($pct) . '%</span>';
    $html .= '</div>';
}

$html .= $pintaTiras($tirasMias);

/* EL RESTO DE LA DELEGACIÓN, plegado: se puede mirar, pero no se cuenta arriba
 * ni se mezcla con lo propio. Con `<details>`, que es nativo y no pide JS. */
if (!empty($resto)) {
    $tirasResto = sticpa_pl_tiras_por_etapa($objSCP, $resto, $events, $stripLimit);
    if (!empty($tirasResto)) {
        $nResto = 0;
        foreach ($tirasResto as $tira) {
            $nResto += count($tira['filas']);
        }
        $html .= '<details class="pl-fold pl-fold--resto">';
        $html .= '<summary class="pl-fold-sum">' . esc_html__('El resto de la delegación', 'sticpa')
            . '<span class="pl-fold-count">' . esc_html(sprintf(
                /* translators: %d: grupos de la delegación fuera del alcance */
                _n('%d grupo', '%d grupos', $nResto, 'sticpa'),
                $nResto
            )) . '</span></summary>';
        $html .= '<div class="pl-fold-body">' . $pintaTiras($tirasResto) . '</div>';
        $html .= '</details>';
    }
}

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

        /* AQUÍ SOLO SE DICE QUIÉNES SON; VINCULARLOS SE HACE EN «MIS GRUPOS».
         *
         * Este bloque tenía su propio formulario de asignar, y desde el
         * 01/09/2026 es un duplicado peor del de `?ver=sueltos`: allí la fila
         * lleva foto, edad y el CURSO de cada grupo en el desplegable —que es
         * lo que hace falta para decidir— y aquí no.
         *
         * Dos formularios que escriben lo mismo acaban divergiendo: se arregla
         * un fallo en uno y el otro se queda como estaba. Así que este se queda
         * como AVISO, que es lo que el resumen sabe hacer bien —enseñar lo que
         * está mal en el conjunto—, y la acción vive en un solo sitio.
         *
         * El manejador del POST de arriba se deja: un enlace viejo o un botón
         * atrás con el formulario ya enviado tiene que seguir funcionando. */
        foreach ($noGroup as $row) {
            $html .= '<div class="pl-review-row">';
            $html .= '<span class="pl-avatar" aria-hidden="true">' . esc_html($row['initials']) . '</span>';
            $html .= '<span class="pl-review-name">' . esc_html($row['name']) . '</span>';
            $html .= '</div>';
        }
        if ($isCoord) {
            $html .= '<div class="pl-review-row">';
            $html .= '<a class="pl-review-link" href="?internalpage=single_stic_mis_grupos&ver=sueltos">'
                . esc_html__('Vincularlos a su grupo', 'sticpa')
                . sticpa_pl_icon('next') . '</a>';
            $html .= '</div>';
        }
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
