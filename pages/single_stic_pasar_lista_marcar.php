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

// El botón de refrescar de la cabecera. Tiene que ir ANTES de la primera
// lectura: si no, se pinta con la caché vieja y hay que pulsarlo dos veces.
sticpa_pl_maybe_refresh($objSCP);

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
        // Los motivos viajan en su propio campo. Se sanean igual que las marcas:
        // solo gente de ESTE grupo, y recortado a lo que cabe en el CRM.
        $notes = array();
        if (!empty($_POST['pl_notes'])) {
            $decodedNotes = json_decode(wp_unslash($_POST['pl_notes']), true);
            if (is_array($decodedNotes)) {
                foreach ($decodedNotes as $cid => $txt) {
                    $cid = sticpa_pl_safe_id($cid);
                    if ($cid === '' || !is_string($txt)) {
                        continue;
                    }
                    $notes[$cid] = sanitize_textarea_field(mb_substr($txt, 0, 255));
                }
            }
        }

        $allowed = array();
        foreach ($people['participants'] as $p) {
            $allowed[$p['id']] = true;
        }
        $marks = array_intersect_key($marks, $allowed);
        $notes = array_intersect_key($notes, $allowed);

        $omitida = ($_POST['pl_action'] === 'skip');

        // GUARDAR SIN NADA MARCADO NO ESCRIBE NADA. Antes escribia la lista con
        // «0 vinieron, 0 ausencias», o sea afirmaba en el CRM que la lista de
        // ese sabado esta pasada y que no vino nadie. Un roce en el boton
        // dejaba un dato falso que nadie iba a revisar.
        //
        // «Sin registro» (skip) si escribe con cero marcas: ahi el cero es la
        // afirmacion, no un descuido.
        if (!$omitida && empty($marks)) {
            $html .= '<p class="pl-notice">' . sticpa_pl_icon('info') . '<span>'
                . esc_html__('No has marcado a nadie, así que no se ha guardado nada. Marca al menos a una persona, o usa «Sin registro» si no hubo sesión.', 'sticpa')
                . '</span></p>';
        } else {
            $saved = sticpa_pl_save($objSCP, $session['id'], $groupId, $marks, $omitida, $regMap, $notes);
        }
    }
}

// ---------------------------------------------------------------------------
// Estado actual
// ---------------------------------------------------------------------------

$attendances = sticpa_pl_session_attendances($objSCP, $session['id'], $regMap);
$lista = sticpa_pl_lista($objSCP, $session['id'], $groupId);

// El aviso de ausencias seguidas, que es lo que convierte la lista en algo más
// que un registro: tres faltas seguidas merecen una llamada a casa, y quien lo
// tiene que saber es el monitor que está marcando.
//
// Se calcula con las asistencias de las ÚLTIMAS `umbral` sesiones celebradas
// —tres consultas, no una por participante— y el resultado se cachea con el TTL
// de estado, así que solo la primera carga las paga. Más atrás no hace falta
// mirar: el aviso salta AL llegar al umbral, y decir "5 seguidas" en vez de
// "3 seguidas" no cambia lo que hay que hacer.
$streaks = sticpa_pl_group_streaks($objSCP, $sessions, $session['id'], $regMap);

$monitorNames = array();
foreach ($people['monitors'] as $m) {
    $monitorNames[] = $m['name'];
}

// ---------------------------------------------------------------------------
// Pintado
// ---------------------------------------------------------------------------

$html .= '<div data-pl-marcar'
    . ' data-session="' . esc_attr($session['id']) . '"'
    . ' data-group="' . esc_attr($groupId) . '"'
    . ' data-msg-draft="' . esc_attr__('Tienes marcas sin guardar de antes.', 'sticpa') . '"'
    . ' data-msg-offline="' . esc_attr__('Sin cobertura. Puedes marcar: se guardará en el móvil.', 'sticpa') . '"'
    . ' data-msg-queued="' . esc_attr__('Guardado en el móvil. Se enviará solo al volver la cobertura.', 'sticpa') . '"'
    . ' data-msg-sync="' . esc_attr__('Enviando lo que quedó pendiente…', 'sticpa') . '"'
    . ' data-msg-sent="' . esc_attr__('Lo pendiente ya está enviado.', 'sticpa') . '"'
    . '>';

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
// El selector de sesión es un desplegable NATIVO aquí mismo, no un viaje a otra
// pantalla: en el móvil es una rueda a pulgar y ahorra tres toques por lista de
// otro día. El historial con el estado de cada lista sigue en el árbol, que es
// donde tiene sentido verlo entero.
$html .= sticpa_pl_session_select_html($sessions, $session['id'], $groupId);
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
    // Y una salida: el caso normal es que se acabe de arreglar la relación en el
    // CRM y haga falta volver a preguntar. Sin este enlace hay que salir a la
    // portada, refrescar allí y volver a entrar — o esperar 12 horas a que
    // caduque la caché, que es lo que pasaba.
    $html .= '<p><a class="pl-session-pick" href="?internalpage=single_stic_pasar_lista_marcar&grupo='
        . rawurlencode($groupId) . '&sesion=' . rawurlencode($session['id']) . '&refrescar=1">'
        . sticpa_pl_icon('refresh') . '<span>'
        . esc_html__('Ya lo he arreglado, vuelve a mirar', 'sticpa') . '</span></a></p>';
    $html .= '</div>';
    return;
}

// `stic-loading-form` es lo que hace que js/stic-ui.js pinte el overlay de
// carga al enviar: entre el tap y el primer pintado está toda la ida y vuelta
// al CRM, y sin señal la lectura es "no ha hecho nada" y se toca otra vez.
$html .= '<form method="post" class="stic-loading-form" data-pl-form'
    . ' data-loading-text="' . esc_attr__('Guardando la lista…', 'sticpa') . '"'
    . ' data-loading-sub="' . esc_attr__('Un momento', 'sticpa') . '">';
$html .= wp_nonce_field('pl_save_' . $groupId, 'pl_nonce', true, false);
$html .= '<input type="hidden" name="pl_marks" value="" data-pl-marks>';
$html .= '<input type="hidden" name="pl_notes" value="" data-pl-notes>';

// "Han venido todos": desmarcar dos ausentes es más rápido que marcar diez.
$html .= '<button type="button" class="pl-all-present" data-pl-all-present>'
    . sticpa_pl_glyph('check') . esc_html__('Han venido todos', 'sticpa') . '</button>';

$html .= '<div class="pl-list">';
foreach ($people['participants'] as $p) {
    $state = isset($attendances[$p['id']]['status']) ? $attendances[$p['id']]['status'] : '';
    $streak = isset($streaks[$p['id']]) ? (int) $streaks[$p['id']] : 0;
    $fichaUrl = '?internalpage=single_stic_pasar_lista_ficha&participante=' . rawurlencode($p['id'])
        . '&grupo=' . rawurlencode($groupId);
    // El motivo que ya tenga la asistencia, para que la hoja lo enseñe en vez
    // de aparecer vacía sobre algo que ya estaba escrito.
    $motive = isset($attendances[$p['id']]['description']) ? $attendances[$p['id']]['description'] : '';
    $html .= sticpa_pl_row_html($p, $state, $streak, $fichaUrl, '', $motive);
}
$html .= '</div>';

$html .= sticpa_pl_legend_html();

// "Sin registro": cubre "no hubo reunión" y "se me olvidó y ya no me acuerdo".
// Un monitor honesto necesita poder cerrar el aviso sin inventarse datos.
$html .= '<button type="submit" name="pl_action" value="skip" class="pl-skip">'
    . sticpa_pl_icon('skip') . esc_html__('Sin registro — no me avises más', 'sticpa') . '</button>';

// Barra de guardado: contadores vivos y un solo botón.
$html .= '<div class="pl-savebar">';
$html .= '<p class="pl-status" data-pl-status hidden></p>';
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
    . ' data-label-partial="' . esc_attr__('Guardar ({n} sin marcar)', 'sticpa') . '"'
    . ' data-label-saving="' . esc_attr__('Guardando…', 'sticpa') . '">'
    . esc_html__('Guardar lista', 'sticpa') . '</button>';
$html .= '</div>';

$html .= '</form>';

// La hoja de los cuatro estados, una por pantalla.
$html .= sticpa_pl_sheet_html(sticpa_pl_session_label($session));

$html .= '</div>';
