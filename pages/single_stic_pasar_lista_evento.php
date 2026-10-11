<?php
/**
 * PASAR LISTA EN UN EVENTO — el autobús.
 * ----------------------------------------------------------------------------
 * Sin `?evento=`: los eventos de la delegación del curso, con el de HOY (el
 * que empieza o acaba hoy) arriba y destacado. Con `?evento=<id>`: su lista,
 * por etapa, con filtro por curso, para marcar quién está.
 *
 * Mismo marcado y mismo JS que la lista de los sábados (marcar), así que
 * hereda el borrador en el móvil, la cola sin cobertura y la comprobación del
 * guardado contra el CRM. La lógica está en inc/stic-pasar-lista-eventos.php.
 *
 * Diseño: docs/comunica/PASAR-LISTA-EVENTOS.md
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageSettings['fileName'] = basename(__FILE__, ".php");

sticpa_pl_maybe_refresh($objSCP);

$eventId = isset($_REQUEST['evento']) ? sticpa_pl_safe_id($_REQUEST['evento']) : '';
$sessionId = isset($_REQUEST['sesion']) ? sticpa_pl_safe_id($_REQUEST['sesion']) : '';

// TANDA 1: lo que no depende de nada. Los eventos ya los ha pedido la portada
// de Pasar Lista (y están cacheados como estructura); los grupos y las
// relaciones dan el curso, el grupo y la etapa de cada uno, y el enlace a su
// ficha.
sticpa_pl_prime($objSCP, function () use ($objSCP, $eventId) {
    sticpa_pl_etapa_events($objSCP);
    sticpa_pl_groups($objSCP);
    sticpa_pl_all_relationships($objSCP);
    if ($eventId !== '') {
        sticpa_pl_event_sessions($objSCP, $eventId);
        sticpa_pl_ev_inscripciones($objSCP, $eventId);
    }
});

$eventos = sticpa_pl_ev_eventos($objSCP);
$course = sticpa_pl_course_for();

// ===========================================================================
// SIN EVENTO: ELEGIR
// ===========================================================================

if ($eventId === '') {
    $html .= '<div class="pl-head">';
    $html .= '<a class="pl-back" href="?internalpage=single_stic_pasar_lista" aria-label="' . esc_attr__('Volver a Pasar lista', 'sticpa') . '">'
        . sticpa_pl_icon('back') . '</a>';
    $html .= '<div class="pl-head-titles">';
    $html .= '<div class="pl-title"><span class="pl-title-code">' . esc_html__('Eventos', 'sticpa') . '</span></div>';
    $html .= '<div class="pl-subtitle">' . esc_html(sprintf(
        /* translators: %s: curso escolar, p. ej. 2026-2027 */
        __('Curso %s', 'sticpa'),
        $course['label']
    )) . '</div>';
    $html .= '</div>';
    $html .= '<a class="pl-session-pick" href="' . esc_url(sticpa_pl_ev_url() . '&refrescar=1') . '"'
        . ' aria-label="' . esc_attr__('Refrescar datos', 'sticpa') . '">' . sticpa_pl_icon('refresh') . '</a>';
    $html .= '</div>';

    $html .= sticpa_pl_queue_notice_html();

    if (empty($eventos)) {
        $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
            . esc_html__('Tu delegación no tiene eventos este curso. Las sesiones de los sábados están en Pasar lista.', 'sticpa')
            . '</span></p>';
        return;
    }

    $porCuando = array('hoy' => array(), 'proximo' => array(), 'pasado' => array());
    foreach ($eventos as $ev) {
        $porCuando[$ev['cuando']][] = $ev;
    }

    // Con muchos, buscador: «conviv» y listo. Filtra lo pintado, sin consultas.
    if (count($eventos) > 8) {
        $html .= sticpa_pl_buscador_html(__('Buscar un evento', 'sticpa'));
    }

    if (!empty($porCuando['hoy'])) {
        $html .= '<div class="pl-evhoy-list">';
        foreach ($porCuando['hoy'] as $ev) {
            $html .= sticpa_pl_ev_hoy_card_html($ev);
        }
        $html .= '</div>';
    }

    $secciones = array(
        'proximo' => __('Próximos', 'sticpa'),
        'pasado' => __('Ya pasados este curso', 'sticpa'),
    );
    foreach ($secciones as $k => $titulo) {
        if (empty($porCuando[$k])) {
            continue;
        }
        $html .= '<div class="pl-etapa-title"><span>' . esc_html($titulo) . '</span>'
            . '<span class="pl-etapa-count">' . count($porCuando[$k]) . '</span></div>';
        $html .= '<div class="pl-list">';
        foreach ($porCuando[$k] as $ev) {
            $html .= sticpa_pl_nav_row_html(sticpa_pl_ev_url($ev['id']), $ev['name'], sticpa_pl_ev_fechas($ev));
        }
        $html .= '</div>';
    }
    if (count($eventos) > 8) {
        $html .= sticpa_pl_buscador_vacio_html();
    }
    return;
}

// ===========================================================================
// UN EVENTO: LA LISTA
// ===========================================================================

$event = sticpa_pl_ev_evento($objSCP, $eventId);
if ($event === null) {
    // No es de tu delegación, no es de este curso o no existe. Se dice lo
    // mismo en los tres casos: no hay que enseñar si un id ajeno existe.
    $html .= '<div class="stic-entry-header"><h3>' . esc_html__('Pasar lista', 'sticpa') . '</h3></div>';
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('Este evento no está entre los de tu delegación de este curso.', 'sticpa') . '</span></p>';
    $html .= '<p><a class="pl-session-pick" href="' . esc_url(sticpa_pl_ev_url()) . '">'
        . esc_html__('Ver los eventos', 'sticpa') . '</a></p>';
    return;
}

$sessions = sticpa_pl_event_sessions($objSCP, $event['id']);
$session = null;
$pick = null;
if ($sessionId !== '') {
    foreach ($sessions as $s) {
        if ((string) $s['id'] === $sessionId) {
            $session = $s;
            break;
        }
    }
}
if ($session === null) {
    $pick = sticpa_pl_ev_pick($sessions);
    $session = ($pick !== null) ? $pick['session'] : null;
}

// El día de la lista cuando el evento NO tiene sesiones: viaja en la URL del
// formulario, para que un guardado que se envía más tarde desde la cola del
// móvil cree la sesión del día en que se marcó y no la del día en que llegó.
$dia = sticpa_pl_ev_dia(isset($_REQUEST['dia']) ? $_REQUEST['dia'] : '');
if ($dia === '') {
    $dia = date('Y-m-d', sticpa_pl_now());
}

$inscripciones = sticpa_pl_ev_inscripciones($objSCP, $event['id']);
$ids = array();
foreach ($inscripciones as $r) {
    if ($r['contact_id'] !== '') {
        $ids[] = $r['contact_id'];
    }
}

// TANDA 2: los nombres de la gente y, si hay sesión, sus asistencias. El mapa
// de inscripciones sale de lo ya leído, así que las dos van juntas.
$regMapPrevio = array();
$estadosFuera = sticpa_pl_ev_estados_fuera();
foreach ($inscripciones as $r) {
    if ($r['contact_id'] !== '' && !isset($estadosFuera[$r['status']])) {
        $regMapPrevio[$r['reg_id']] = $r['contact_id'];
    }
}
sticpa_pl_prime($objSCP, function () use ($objSCP, $ids, $session, $regMapPrevio) {
    sticpa_pl_ev_personas($objSCP, $ids);
    if ($session !== null) {
        sticpa_pl_session_attendances($objSCP, $session['id'], $regMapPrevio);
    }
});
$personas = sticpa_pl_ev_personas($objSCP, $ids);
// El curso y el grupo salen de la relación de participante vigente más
// reciente de cada uno, del mapa de relaciones ya cacheado: cero llamadas.
$relaciones = sticpa_pl_all_relationships($objSCP);
$lista = sticpa_pl_ev_lista(
    $inscripciones,
    $personas,
    sticpa_pl_ev_grupos_de($relaciones, sticpa_pl_groups($objSCP)),
    sticpa_pl_ev_cursos_de($relaciones)
);
$regMap = $lista['regMap'];

// ---------------------------------------------------------------------------
// Guardado
// ---------------------------------------------------------------------------

$saved = null;
$saveProblems = array();
$savedOk = false;
$marks = array();
$notes = array();
$isPost = (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'POST');
$marksRaw = isset($_POST['pl_marks']) ? (string) $_POST['pl_marks'] : '';
$nonceAction = 'pl_save_ev_' . $event['id'];

if (!empty($_POST['pl_action'])) {
    if (!isset($_POST['pl_nonce']) || !wp_verify_nonce($_POST['pl_nonce'], $nonceAction)) {
        sticpa_pl_log_save(array(
            'pantalla' => 'evento', 'motivo' => 'nonce',
            'grupo' => 'ev:' . $event['id'], 'sesion' => $session !== null ? $session['id'] : '',
            'marcas_post' => strlen($marksRaw),
        ));
        // La señal con la que la cola del móvil reintenta con el nonce nuevo
        // de esta misma pantalla (plan 042, PL-9).
        $html .= '<p class="pl-notice" data-pl-nonce-expired>' . sticpa_pl_icon('clock') . '<span>'
            . esc_html__('La sesión ha caducado. Vuelve a cargar la pantalla.', 'sticpa') . '</span></p>';
    } else {
        $activos = array();
        foreach ($lista['activos'] as $p) {
            $activos[$p['id']] = true;
        }
        if (!empty($_POST['pl_marks'])) {
            $decoded = json_decode(wp_unslash($_POST['pl_marks']), true);
            if (is_array($decoded)) {
                foreach ($decoded as $cid => $st) {
                    // Solo gente inscrita (y no cancelada) en ESTE evento, y
                    // estados que conocemos: el navegador no manda sobre el CRM.
                    $cid = sticpa_pl_safe_id($cid);
                    if ($cid === '' || !isset($activos[$cid]) || !sticpa_pl_is_state($st)) {
                        continue;
                    }
                    $marks[$cid] = $st;
                }
            }
        }
        if (!empty($_POST['pl_notes'])) {
            $decodedNotes = json_decode(wp_unslash($_POST['pl_notes']), true);
            if (is_array($decodedNotes)) {
                foreach ($decodedNotes as $cid => $txt) {
                    $cid = sticpa_pl_safe_id($cid);
                    if ($cid === '' || !isset($activos[$cid]) || !is_string($txt)) {
                        continue;
                    }
                    $notes[$cid] = sanitize_textarea_field(mb_substr($txt, 0, 255));
                }
            }
        }

        if (empty($marks)) {
            // Guardar sin marcar a nadie no escribe nada: igual que los sábados.
            sticpa_pl_log_save(array(
                'pantalla' => 'evento', 'motivo' => 'sin_marcas',
                'grupo' => 'ev:' . $event['id'], 'sesion' => $session !== null ? $session['id'] : '',
                'marcas_post' => strlen($marksRaw), 'marcas_usadas' => 0,
            ));
            $html .= '<p class="pl-notice">' . sticpa_pl_icon('info') . '<span>'
                . esc_html__('No has marcado a nadie, así que no se ha guardado nada.', 'sticpa')
                . '</span></p>';
        } else {
            // Sin sesiones en el CRM: se crea la del día marcado. Sin sesión no
            // hay asistencia posible.
            if (empty($sessions)) {
                $session = sticpa_pl_ev_sesion_del_dia($objSCP, $event, $dia);
                if ($session !== null) {
                    $sessions = array($session);
                }
            }
            $saved = sticpa_pl_ev_guardar($objSCP, $event['id'], $session, $marks, $regMap, $notes);
        }
    }
} elseif ($isPost) {
    $html .= '<p class="pl-notice pl-notice--error">' . sticpa_pl_icon('warn') . '<span>'
        . esc_html__('No se ha guardado: la petición llegó sin la orden de guardar. Vuelve a intentarlo.', 'sticpa')
        . '</span></p>';
}

// ---------------------------------------------------------------------------
// Estado actual (releído si se acaba de guardar: el guardado tira la caché)
// ---------------------------------------------------------------------------

$attendances = ($session !== null) ? sticpa_pl_session_attendances($objSCP, $session['id'], $regMap) : array();

if (is_array($saved)) {
    $saveProblems = sticpa_pl_check_saved($marks, null, $attendances, false, false);
    $savedOk = ((int) $saved['failed'] === 0 && empty($saveProblems));
    sticpa_pl_log_save(array(
        'pantalla' => 'evento',
        'motivo' => $savedOk ? 'ok' : 'fallos',
        'grupo' => 'ev:' . $event['id'], 'sesion' => $session !== null ? $session['id'] : '',
        'marcas_post' => strlen($marksRaw),
        'marcas_usadas' => count($marks),
        'saved' => (int) $saved['saved'],
        'failed' => (int) $saved['failed'],
        'lista_id' => '',
        'errores' => array_merge(
            isset($saved['errors']) ? (array) $saved['errors'] : array(),
            array_map(function ($p) { return array('paso' => 'relectura', 'error' => $p); }, $saveProblems)
        ),
    ));
}

// ---------------------------------------------------------------------------
// Pintado
// ---------------------------------------------------------------------------

$nActivos = count($lista['activos']);
// La clave del borrador y de la cola: la sesión si la hay; si no, la del día
// que se va a crear. Así un borrador no se mezcla con el de otro día.
$draftSession = ($session !== null) ? $session['id'] : ('nueva-' . $dia);
$cuandoTxt = ($session !== null) ? date_i18n('j M', (int) $session['start']) : date_i18n('j M', strtotime($dia));

$html .= '<div data-pl-marcar data-pl-evento'
    . ($savedOk ? ' data-pl-saved-ok' : '')
    . ' data-session="' . esc_attr($draftSession) . '"'
    . ' data-group="' . esc_attr('ev-' . $event['id']) . '"'
    . ' data-queue-label="' . esc_attr($event['name'] . ' · ' . $cuandoTxt) . '"'
    . ' data-msg-dirty="' . esc_attr__('Cambios sin guardar · los datos están solo en tu móvil', 'sticpa') . '"'
    . ' data-msg-draft="' . esc_attr__('Tienes marcas sin guardar de antes.', 'sticpa') . '"'
    . ' data-msg-offline="' . esc_attr__('Sin cobertura. Puedes marcar: se guardará en el móvil.', 'sticpa') . '"'
    . ' data-msg-queued="' . esc_attr__('Guardado en el móvil. Se enviará solo al volver la cobertura.', 'sticpa') . '"'
    . ' data-msg-sync="' . esc_attr__('Enviando lo que quedó pendiente…', 'sticpa') . '"'
    . ' data-msg-sent="' . esc_attr__('Lo pendiente ya está enviado.', 'sticpa') . '"'
    . ' data-msg-stuck="' . esc_attr__('No se ha podido enviar lo que quedó pendiente. Vuelve a marcar y guardar.', 'sticpa') . '"'
    . '>';

$html .= '<div class="pl-head">';
$html .= '<a class="pl-back" href="' . esc_url(sticpa_pl_ev_url()) . '" aria-label="' . esc_attr__('Volver a los eventos', 'sticpa') . '">'
    . sticpa_pl_icon('back') . '</a>';
$html .= '<div class="pl-head-titles">';
$html .= '<div class="pl-title"><span class="pl-title-code">' . esc_html($event['name']) . '</span></div>';
$sub = array(sticpa_pl_ev_fechas($event));
$sub[] = sprintf(
    /* translators: %d: personas con plaza en el evento */
    _n('%d persona', '%d personas', $nActivos, 'sticpa'),
    $nActivos
);
$html .= '<div class="pl-subtitle">' . esc_html(implode(' · ', $sub)) . '</div>';
$html .= '</div>';
$html .= ($session !== null)
    ? sticpa_pl_ev_sesiones_html($event['id'], $sessions, $session['id'])
    : '';
$html .= '</div>';

// De qué día es la lista, cuando no es la obvia.
if ($session === null) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html(sprintf(
            /* translators: %s: fecha, p. ej. «sábado 10 de octubre» */
            __('Este evento no tiene días en el CRM. Al guardar se crea la lista del %s.', 'sticpa'),
            function_exists('wp_date') ? wp_date('l j \d\e F', strtotime($dia)) : date_i18n('l j \d\e F', strtotime($dia))
        )) . '</span></p>';
} elseif (date('Y-m-d', (int) $session['start']) !== date('Y-m-d', sticpa_pl_now())) {
    $html .= '<p class="pl-notice">' . sticpa_pl_icon('clock') . '<span>'
        . esc_html(sprintf(
            /* translators: %s: fecha y hora de la sesión */
            __('Es la lista del %s.', 'sticpa'),
            sticpa_pl_session_label($session)
        )) . '</span></p>';
}

$html .= sticpa_pl_save_result_html($saved, $saveProblems, $objSCP);

if ($nActivos === 0) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('Todavía no hay nadie inscrito en este evento.', 'sticpa') . '</span></p>';
    $html .= sticpa_pl_ev_fuera_html($lista['fuera']);
    $html .= '</div>';
    return;
}

$formAction = sticpa_pl_ev_url($event['id'], $session !== null ? $session['id'] : '');
if ($session === null) {
    $formAction .= '&dia=' . rawurlencode($dia);
}
$html .= '<form method="post" action="' . esc_url($formAction) . '" class="stic-loading-form" data-pl-form'
    . ' data-loading-text="' . esc_attr__('Guardando la lista…', 'sticpa') . '"'
    . ' data-loading-sub="' . esc_attr__('Un momento', 'sticpa') . '">';
$html .= wp_nonce_field($nonceAction, 'pl_nonce', true, false);
$html .= '<input type="hidden" name="pl_action" value="save" data-pl-action>';
$html .= '<input type="hidden" name="pl_marks" value="" data-pl-marks>';
$html .= '<input type="hidden" name="pl_notes" value="" data-pl-notes>';

// Buscar a alguien («¿y Lucía?») y filtrar por curso. Todo en el navegador:
// la lista ya está entera en la página. Los chips se quedan pegados arriba al
// bajar; el buscador no (dos barras pegadas se comen media pantalla del móvil).
$html .= '<div class="pl-search">' . sticpa_pl_icon('search')
    . '<input type="search" data-pl-ev-buscar placeholder="' . esc_attr__('Buscar por nombre', 'sticpa') . '"'
    . ' aria-label="' . esc_attr__('Buscar por nombre', 'sticpa') . '" autocomplete="off" enterkeyhint="search"></div>';
$html .= sticpa_pl_ev_chips_html($lista['cursos'], $nActivos);

// «Están todos»: rellena SOLO lo que está sin marcar y SOLO lo que se ve (con
// un curso elegido, ese curso). Lo que ya está marcado no se toca.
$html .= '<button type="button" class="pl-all-present" data-pl-all-present'
    . ' data-label-all="' . esc_attr__('Están todos', 'sticpa') . '"'
    /* translators: {n}: cuántas personas quedan sin marcar */
    . ' data-label-rest="' . esc_attr__('El resto está ({n})', 'sticpa') . '">'
    . sticpa_pl_glyph('check') . '<span data-pl-all-label>' . esc_html__('Están todos', 'sticpa') . '</span></button>';

// Por etapa (MIC, COM, LC y, si hay, «Sin etapa») y, dentro, por curso; en
// cada curso, por apellidos.
$html .= sticpa_pl_ev_secciones_html($lista['activos'], function ($p) use ($attendances, $saved, $savedOk, $marks) {
    $state = isset($attendances[$p['id']]['status']) ? $attendances[$p['id']]['status'] : '';
    // Tras un guardado que NO se confirmó, lo que se pinta es lo marcado:
    // el monitor no puede perder sus marcas por un fallo del CRM.
    if (is_array($saved) && !$savedOk && isset($marks[$p['id']])) {
        $state = $marks[$p['id']];
    }
    $motive = isset($attendances[$p['id']]['description']) ? $attendances[$p['id']]['description'] : '';
    return sticpa_pl_ev_row_html($p, $state, $motive);
});
$html .= '<p class="pl-search-empty" data-pl-ev-vacio hidden>' . esc_html__('Nadie coincide con lo que buscas.', 'sticpa') . '</p>';

$html .= sticpa_pl_ev_fuera_html($lista['fuera']);
$html .= sticpa_pl_legend_html();

// La barra de guardado, la de siempre, con las palabras del autobús.
$html .= '<div class="pl-savebar">';
$html .= sticpa_pl_savebar_status_html(
    $saved,
    $savedOk,
    is_array($saved) ? sprintf(
        /* translators: 1: cuántas están, 2: cuántas faltan */
        __('Lista guardada · %1$s, %2$s', 'sticpa'),
        sprintf(_n('%d está', '%d están', (int) $saved['counts']['yes'], 'sticpa'), (int) $saved['counts']['yes']),
        sprintf(_n('%d falta', '%d faltan', (int) $saved['counts']['no'], 'sticpa'), (int) $saved['counts']['no'])
    ) : ''
);
$html .= '<div class="pl-counts" aria-live="polite" aria-atomic="true">';
$html .= '<span class="pl-count"><span class="pl-count-dot pl-count-dot--yes"></span>'
    . '<span data-pl-count-yes>0</span>&nbsp;<span data-pl-word="yes"'
    . ' data-one="' . esc_attr__('está', 'sticpa') . '" data-many="' . esc_attr__('están', 'sticpa') . '">'
    . esc_html__('están', 'sticpa') . '</span></span>';
$html .= '<span class="pl-count"><span class="pl-count-dot pl-count-dot--no"></span>'
    . '<span data-pl-count-no>0</span>&nbsp;<span data-pl-word="no"'
    . ' data-one="' . esc_attr__('falta', 'sticpa') . '" data-many="' . esc_attr__('faltan', 'sticpa') . '">'
    . esc_html__('faltan', 'sticpa') . '</span></span>';
$html .= '<span class="pl-count pl-count--none" data-pl-count-none-wrap hidden>'
    . '<span class="pl-count-dot pl-count-dot--none"></span>'
    . '<span data-pl-count-none>0</span>&nbsp;' . esc_html__('sin marcar', 'sticpa') . '</span>';
$html .= '</div>';
$html .= '<button type="submit" name="pl_action" value="save" class="pl-save' . ($savedOk ? ' is-saved' : '') . '" data-pl-save'
    . ' data-label-full="' . esc_attr__('Guardar lista', 'sticpa') . '"'
    . ' data-label-partial="' . esc_attr__('Guardar ({n} sin marcar)', 'sticpa') . '"'
    . ' data-label-saved="' . esc_attr__('Guardada ✓', 'sticpa') . '"'
    . ' data-label-saving="' . esc_attr__('Guardando…', 'sticpa') . '">'
    . ($savedOk ? esc_html__('Guardada ✓', 'sticpa') : esc_html__('Guardar lista', 'sticpa')) . '</button>';
$html .= '</div>';

$html .= '</form>';

$html .= sticpa_pl_sheet_html($session !== null ? sticpa_pl_session_label($session) : $event['name']);

$html .= '</div>';
