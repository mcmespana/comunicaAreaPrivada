<?php
/**
 * COORDINACIÓN — todo lo del equipo de monitores, en una pantalla.
 * ----------------------------------------------------------------------------
 * Hasta el 28/09/2026 esto estaba repartido en cuatro sitios con nombres que no
 * se entendían juntos: «Monitores» (que era PASAR LISTA a los monitores, no ver
 * sus fichas), «Reuniones» (otra forma de pasar esa misma lista), la pestaña
 * «Monitores» de Mis grupos (que sí eran las fichas) y el bloque del final de
 * la portada de Pasar lista. Aquí queda una sola puerta, y de arriba abajo en el
 * orden en que se usa:
 *
 *   1. Lo que falta: la última reunión sin lista, en ámbar (como en Pasar lista).
 *   2. Las listas del equipo: la del sábado y las reuniones de programación.
 *      Solo coordinación.
 *   3. El resumen de grupos (qué listas faltan, datos por revisar).
 *   4. EL EQUIPO: los monitores del alcance, por etapa, con el buscador. Cada
 *      fila abre la ficha, y en la ficha están sus seguimientos.
 *
 * ACOMPAÑAMIENTO también entra y ve el 3 y el 4, que es justo lo suyo: las
 * fichas y los seguimientos. Antes le salía «Monitores» en el menú y la pantalla
 * le contestaba «Esta pantalla es de coordinación»: no tenía por dónde llegar a
 * las fichas que sí tiene permiso para leer.
 *
 * COSTE: las mismas consultas que ya hacían sus piezas por separado, en dos
 * tandas. Sin cargador propio.
 *
 * Diseño: docs/comunica/PASAR-LISTA-COORDINACION.md §5 quater
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageSettings['fileName'] = basename(__FILE__, ".php");

sticpa_pl_maybe_refresh($objSCP);

// ---------------------------------------------------------------------------
// Vincular a un grupo a un monitor que no lo tiene (solo coordinación)
// ---------------------------------------------------------------------------

/* Va ANTES de la tanda que lee: `sticpa_pl_assign_group()` vacía la caché al
 * escribir, y si se leyera primero, el monitor recién vinculado seguiría
 * saliendo suelto hasta recargar. El escritor comprueba por su cuenta que quien
 * lo llama coordina y que grupo y relación son de la delegación; aquí solo se
 * comprueba el nonce, que es de la pantalla. */
$asignarMsg = '';
if (!empty($_POST['pl_assign_rel'])) {
    if (!isset($_POST['pl_nonce']) || !wp_verify_nonce($_POST['pl_nonce'], 'pl_coordinacion')) {
        $asignarMsg = __('La sesión ha caducado. Vuelve a cargar la pantalla.', 'sticpa');
    } else {
        list($relAsignar, $grupoAsignar) = sticpa_pl_assign_post();
        $asignarMsg = sticpa_pl_assign_group($objSCP, $relAsignar, $grupoAsignar)
            ? __('Vinculado. Ya sale en la lista de su grupo.', 'sticpa')
            : __('No se ha podido vincular. Si no eres de coordinación, no puedes hacerlo desde aquí.', 'sticpa');
    }
}

// TANDA 1: quién mira y con qué alcance, y la gente de la delegación.
sticpa_pl_prime($objSCP, function () use ($objSCP) {
    sticpa_pl_coord_scope($objSCP);
    sticpa_pl_is_acompanante($objSCP);
    sticpa_pl_groups($objSCP);
    sticpa_pl_all_relationships($objSCP);
    sticpa_pl_events_raw($objSCP);
    sticpa_pl_all_listas_monitores($objSCP);
});

$scope = sticpa_pl_coord_scope($objSCP);
$isCoord = ($scope !== null);
$isAcomp = sticpa_pl_is_acompanante($objSCP);

if (!$isCoord && !$isAcomp) {
    $html .= '<div class="pl-head"><div class="pl-head-titles"><div class="pl-title">'
        . '<span class="pl-title-code pl-title-code--main">' . esc_html__('Coordinación', 'sticpa')
        . '</span></div></div></div>';
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('Esta pantalla es de coordinación y acompañamiento. Si crees que deberías verla, avisa a la oficina técnica.', 'sticpa')
        . '</span></p>';
    return;
}

$groups = sticpa_pl_groups($objSCP);
$reuEvent = $isCoord ? sticpa_pl_reuniones_event($objSCP) : null;
$events = sticpa_pl_etapa_events($objSCP);   // de `events_raw`, ya en la tanda 1

// TANDA 2: las sesiones de reuniones, para el aviso de «reunión sin pasar», y
// las de los eventos de etapa, para decir cómo va la última sesión (plan 042,
// COO-2). Por id y sin repetir: MIC y COM pueden compartir evento.
$eventIds = array_unique(array_column($events, 'id'));
if (is_array($reuEvent) && !empty($reuEvent['id'])) {
    $eventIds[] = $reuEvent['id'];
}
if (!empty($eventIds)) {
    sticpa_pl_prime($objSCP, function () use ($objSCP, $eventIds) {
        foreach (array_unique($eventIds) as $evId) {
            sticpa_pl_event_sessions($objSCP, $evId);
        }
    });
}

// ---------------------------------------------------------------------------
// Cabecera
// ---------------------------------------------------------------------------

/* El ALCANCE va de subtítulo (design.md §6.4): es lo que dice de quién es lo
 * que se ve. Quien solo acompaña no tiene alcance de coordinación: se le dice
 * su papel, que es lo que explica que vea esto. */
$subtitulo = $isCoord
    ? sticpa_pl_coord_scope_label($scope)
    : __('Acompañamiento', 'sticpa');

$html .= '<div class="pl-head">';
$html .= '<div class="pl-head-titles">';
$html .= '<div class="pl-title"><span class="pl-title-code pl-title-code--main">'
    . esc_html__('Coordinación', 'sticpa') . '</span></div>';
$html .= '<div class="pl-subtitle">' . esc_html(ucfirst($subtitulo)) . '</div>';
$html .= '</div>';
$html .= '<a class="pl-session-pick" href="?internalpage=single_stic_coordinacion&refrescar=1"'
    . ' aria-label="' . esc_attr__('Refrescar datos', 'sticpa') . '">' . sticpa_pl_icon('refresh') . '</a>';
$html .= '</div>';

if ($asignarMsg !== '') {
    $html .= '<p class="pl-notice"><span>' . esc_html($asignarMsg) . '</span></p>';
}

// ---------------------------------------------------------------------------
// Lo que falta, y las listas del equipo
// ---------------------------------------------------------------------------

/* La reunión sin lista, con su cabecera como en la portada de Pasar lista:
 * suelta bajo el título no se entendía de qué era el ámbar (plan 042, COO-2). */
$deudas = sticpa_pl_reunion_pendiente($objSCP, $reuEvent);
if (!empty($deudas)) {
    $html .= '<div class="pl-etapa-title pl-etapa-title--warn">'
        . sticpa_pl_icon('warn')
        . esc_html(sprintf(
            /* translators: %d: número de listas de reunión sin pasar */
            _n('Falta %d lista', 'Faltan %d listas', count($deudas), 'sticpa'),
            count($deudas)
        )) . '</div>';
    $html .= sticpa_pl_deudas_html($deudas);
}

/* CADA FILA DICE LO QUE HAY, NO LO QUE ES (plan 042, COO-2). La pregunta con
 * la que se abre Coordinación un lunes —¿se pasó lista el sábado, y quién
 * falta?— se contestaba un toque más abajo, en el Resumen. Ahora la contesta la
 * propia fila, con la MISMA cuenta que el Resumen (sticpa_pl_ultima_sesion_
 * estado) y con el alcance de quien mira. Sin ámbar: la lista de monitores
 * del sábado no avisa, a propósito (§5 ter); aquí solo se dice cómo está. */
$acotado = is_array($scope)
    && ((isset($scope['etapa']) && $scope['etapa'] !== '') || (isset($scope['segmento']) && $scope['segmento'] !== ''));
$ultima = sticpa_pl_ultima_sesion_estado(sticpa_pl_tiras_por_etapa(
    $objSCP,
    $acotado ? sticpa_pl_scoped_groups($objSCP, $scope) : $groups,
    $events,
    1   // solo hace falta la última sesión
));
$metaResumen = __('Qué listas faltan y datos por revisar', 'sticpa');
if ($ultima['total'] > 0) {
    $bits = array();
    if (count($ultima['fechas']) === 1) {
        $bits[] = date_i18n('D j M', (int) $ultima['fechas'][0]);
    }
    $bits[] = sprintf(
        /* translators: 1: listas hechas, 2: listas que tocaban */
        __('%1$d de %2$d listas', 'sticpa'),
        $ultima['done'],
        $ultima['total']
    );
    if (empty($ultima['faltan'])) {
        $bits[] = __('todas pasadas', 'sticpa');
    } else {
        // Hasta cuatro nombres: más no caben en una línea, y el Resumen los
        // dice todos.
        $codes = array_column($ultima['faltan'], 'code');
        $txt = implode(', ', array_slice($codes, 0, 4));
        if (count($codes) > 4) {
            $txt .= ' ' . sprintf(
                /* translators: %d: grupos que faltan y no caben en la línea */
                __('y %d más', 'sticpa'),
                count($codes) - 4
            );
        }
        $bits[] = sprintf(
            /* translators: %s: códigos de los grupos que no han pasado lista */
            _n('falta %s', 'faltan %s', count($codes), 'sticpa'),
            $txt
        );
    }
    $metaResumen = implode(' · ', $bits);
}

/* La lista de monitores de la última sesión celebrada: del evento de la etapa
 * del alcance, o del primero que haya, como la propia pantalla de la lista. */
$metaMonitores = __('Quién ha venido el sábado', 'sticpa');
if ($isCoord) {
    $evMon = null;
    if ($scope['etapa'] !== '' && isset($events[$scope['etapa']])) {
        $evMon = $events[$scope['etapa']];
    } else {
        foreach (array('COM', 'MIC', 'LC') as $e) {
            if (isset($events[$e])) {
                $evMon = $events[$e];
                break;
            }
        }
    }
    $pasadas = $evMon ? sticpa_pl_elapsed_sessions(sticpa_pl_event_sessions($objSCP, $evMon['id'])) : array();
    $ultimaMon = empty($pasadas) ? null : end($pasadas);
    if ($ultimaMon !== null) {
        $listasMon = sticpa_pl_all_listas_monitores($objSCP);
        $listaMon = isset($listasMon[$ultimaMon['id']]) ? $listasMon[$ultimaMon['id']] : null;
        $estadoMon = is_array($listaMon) ? (string) $listaMon['estado'] : '';
        if ($estadoMon === 'pasada') {
            $estadoTxt = sprintf(
                /* translators: 1: «N vinieron», 2: «N faltas» */
                __('pasada: %1$s, %2$s', 'sticpa'),
                sprintf(
                    /* translators: %d: monitores que vinieron */
                    _n('%d vino', '%d vinieron', (int) $listaMon['n_asistieron'], 'sticpa'),
                    (int) $listaMon['n_asistieron']
                ),
                sprintf(
                    /* translators: %d: monitores que faltaron */
                    _n('%d falta', '%d faltas', (int) $listaMon['n_faltaron'], 'sticpa'),
                    (int) $listaMon['n_faltaron']
                )
            );
        } elseif ($estadoMon === 'omitida') {
            $estadoTxt = __('sin registro', 'sticpa');
        } else {
            $estadoTxt = __('sin pasar', 'sticpa');
        }
        $metaMonitores = date_i18n('D j M', (int) $ultimaMon['start']) . ' · ' . $estadoTxt;
    }
}

// `desde=coordinacion` hace que la flecha de atrás de esas pantallas vuelva aquí
// y no a Pasar lista, que es la otra puerta por la que se llega a ellas.
// `pl-list--nav`: el buscador de abajo no filtra estas filas (js/stic-pasar-lista.js).
$html .= '<div class="pl-list pl-list--nav">';
if ($isCoord) {
    $html .= sticpa_pl_nav_row_html(
        '?internalpage=single_stic_pasar_lista_monitores&desde=coordinacion',
        __('Lista de monitores', 'sticpa'),
        $metaMonitores
    );
    $html .= sticpa_pl_nav_row_html(
        '?internalpage=single_stic_pasar_lista_reuniones&desde=coordinacion',
        __('Reuniones de programación', 'sticpa'),
        __('Crearlas y pasar lista', 'sticpa')
    );
}
$html .= sticpa_pl_nav_row_html(
    '?internalpage=single_stic_pasar_lista_resumen&desde=coordinacion',
    __('Resumen de grupos', 'sticpa'),
    $metaResumen
);
$html .= '</div>';

// ---------------------------------------------------------------------------
// El equipo
// ---------------------------------------------------------------------------

/* Con alcance `null` (solo acompaña), sticpa_pl_coord_monitors() devuelve los
 * monitores de toda la delegación: es el mismo conjunto que deja abrir la ficha
 * (pages/single_stic_pasar_lista_monitor.php), así que ninguna fila de aquí
 * lleva a un «no está en tu alcance». */
$monitors = sticpa_pl_coord_monitors($objSCP, $scope);

$html .= '<div class="pl-sec-row"><div class="pl-sec">' . esc_html__('Tu equipo', 'sticpa') . '</div>';
if (!empty($monitors)) {
    $html .= '<span class="pl-etapa-count">' . esc_html(sprintf(
        /* translators: %d: cuántos monitores hay en el alcance */
        _n('%d monitor', '%d monitores', count($monitors), 'sticpa'),
        count($monitors)
    )) . '</span>';
}
$html .= '</div>';

if (!empty($monitors)) {
    $html .= sticpa_pl_buscador_html(__('Buscar monitor o grupo…', 'sticpa'));
}
$html .= sticpa_pl_directorio_monitores_html($monitors, $groups, $isCoord, 'pl_coordinacion');
