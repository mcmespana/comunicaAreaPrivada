<?php
/**
 * PASAR LISTA — ficha del monitor (coordinación).
 * ----------------------------------------------------------------------------
 * La de los chavales sin familia y sin salud —un monitor es un adulto— y con
 * otra mitad de abajo. Lo que hace falta cuando coordinación abre a un monitor
 * es saber **si está en regla y si viene**, así que el orden es ese:
 *
 *   1. El certificado de delitos sexuales. Primero porque es una obligación
 *      legal y porque es lo que se reclama.
 *   2. Asistencia hasta hoy, con denominador.
 *   3. Titulaciones, con aviso si el título dice sí pero falta el archivo.
 *   4. Contacto.
 *
 * Diseño: docs/comunica/PASAR-LISTA-COORDINACION.md §4
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageSettings['fileName'] = basename(__FILE__, ".php");

$scope = sticpa_pl_coord_scope($objSCP);
$isAcomp = sticpa_pl_is_acompanante($objSCP);

// Acompañar da acceso a la ficha aunque no se coordine: es justo la gente que
// tiene que poder leer los datos de los monitores.
if ($scope === null && !$isAcomp) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('Esta pantalla es de coordinación.', 'sticpa') . '</span></p>';
    return;
}

$segRoles = sticpa_pl_seg_roles($scope !== null, $isAcomp);

$monitorId = isset($_REQUEST['monitor']) ? sticpa_pl_safe_id($_REQUEST['monitor']) : '';
if ($monitorId === '') {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No se ha indicado ningún monitor.', 'sticpa') . '</span></p>';
    return;
}

// El monitor tiene que estar en un grupo del alcance. El CRM ya limita por
// delegación, pero esto impide abrir a un monitor de otra etapa cambiando la URL.
$groups = sticpa_pl_scoped_groups($objSCP, $scope);
$monitors = sticpa_pl_monitors_of($objSCP, $groups);

$mine = null;
foreach ($monitors as $m) {
    if ($m['id'] === $monitorId) {
        $mine = $m;
        break;
    }
}
if ($mine === null) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('Este monitor no está en los grupos de tu alcance.', 'sticpa') . '</span></p>';
    return;
}

// ---------------------------------------------------------------------------
// Alta de un seguimiento
// ---------------------------------------------------------------------------

$segMsg = '';
if (!empty($_POST['pl_seg_texto'])) {
    if (!isset($_POST['pl_nonce']) || !wp_verify_nonce($_POST['pl_nonce'], 'pl_seg_' . $monitorId)) {
        $segMsg = __('La sesión ha caducado. Vuelve a cargar la pantalla.', 'sticpa');
    } else {
        $ok = sticpa_pl_create_seguimiento(
            $objSCP,
            $monitorId,
            isset($_POST['pl_seg_tipo']) ? $_POST['pl_seg_tipo'] : '',
            $_POST['pl_seg_texto'],
            isset($_POST['pl_seg_fecha']) ? $_POST['pl_seg_fecha'] : '',
            $segRoles
        );
        $segMsg = $ok
            ? __('Guardado.', 'sticpa')
            : __('No se ha podido guardar.', 'sticpa');
    }
}

$ficha = sticpa_pl_monitor_ficha($objSCP, $monitorId);
if ($ficha === null) {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No se han podido cargar los datos.', 'sticpa') . '</span></p>';
    return;
}

// ---------------------------------------------------------------------------
// Cabecera
// ---------------------------------------------------------------------------

$html .= '<div class="pl-head">';
$html .= '<a class="pl-back" href="?internalpage=single_stic_pasar_lista_monitores"'
    . ' aria-label="' . esc_attr__('Volver a monitores', 'sticpa') . '">' . sticpa_pl_icon('back') . '</a>';
$html .= '<div class="pl-head-titles">';
$html .= '<div class="pl-title"><span class="pl-title-code">' . esc_html($ficha['name']) . '</span></div>';

$bits = array();
if (!empty($mine['groups'])) {
    $bits[] = implode(' · ', $mine['groups']);
}
$since = sticpa_pl_monitor_since($ficha['ajmcm_monitor_desde_c']);
if ($since !== '') {
    $bits[] = sprintf(
        /* translators: %s: año en que empezó como monitor/a */
        __('monitor/a desde %s', 'sticpa'),
        $since
    );
}
$html .= '<div class="pl-subtitle">' . esc_html(implode(' · ', $bits)) . '</div>';
$html .= '</div>';
$html .= '</div>';

// ---------------------------------------------------------------------------
// Certificado de delitos sexuales: lo primero
// ---------------------------------------------------------------------------

$ds = sticpa_pl_ds_state($ficha);
$dsMeta = array(
    'auto' => array(
        'label' => __('Automático', 'sticpa'),
        'sub' => __('Autorizó al MCM a pedirlo cada año', 'sticpa'),
        'class' => 'pl-ds--ok',
    ),
    'uploaded' => array(
        'label' => __('Entregado', 'sticpa'),
        'sub' => __('Lo subió a mano y está archivado', 'sticpa'),
        'class' => 'pl-ds--ok',
    ),
    'missing' => array(
        'label' => __('Falta', 'sticpa'),
        'sub' => __('Hay que reclamarlo: es obligatorio por ley', 'sticpa'),
        'class' => 'pl-ds--missing',
    ),
);
$m = $dsMeta[$ds];

$html .= '<div class="pl-sec">' . esc_html__('Certificado de delitos sexuales', 'sticpa') . '</div>';
$html .= '<div class="pl-ds ' . esc_attr($m['class']) . '">';
$html .= '<span class="pl-ds-icon">'
    . sticpa_pl_glyph($ds === 'missing' ? 'cross' : 'check') . '</span>';
$html .= '<span class="pl-ds-body">';
$html .= '<span class="pl-ds-label">' . esc_html($m['label']) . '</span>';
$html .= '<span class="pl-ds-sub">' . esc_html($m['sub']) . '</span>';
$html .= '</span>';
$html .= '</div>';

// ---------------------------------------------------------------------------
// Asistencia hasta hoy
// ---------------------------------------------------------------------------

$events = sticpa_pl_etapa_events($objSCP);
// Quien solo acompaña no tiene alcance, así que no hay etapa de la que tirar:
// se coge el primer evento que haya, que para las asistencias da igual porque
// las sesiones son las mismas fechas.
$etapaKey = ($scope !== null && $scope['etapa'] !== '') ? $scope['etapa'] : '';
$event = ($etapaKey !== '' && isset($events[$etapaKey])) ? $events[$etapaKey] : null;
if ($event === null) {
    foreach (array('COM', 'MIC', 'LC') as $e) {
        if (isset($events[$e])) {
            $event = $events[$e];
            break;
        }
    }
}

if ($event !== null) {
    $sessions = sticpa_pl_event_sessions($objSCP, $event['id']);
    $regMap = sticpa_pl_event_registrations($objSCP, $event['id']);
    $regId = array_search($monitorId, $regMap, true);

    if ($regId !== false) {
        $marks = sticpa_pl_contact_marks($objSCP, $regId);
        $att = sticpa_pl_attendance($sessions, $marks);

        $html .= '<div class="pl-sec">' . esc_html__('Asistencia', 'sticpa') . '</div>';
        $html .= '<div class="pl-att">';
        $html .= '<div class="pl-att-pct">' . esc_html($att['pct']) . '<span>%</span></div>';
        $html .= '<div class="pl-att-body">';
        $html .= '<div class="pl-att-main">' . esc_html(sprintf(
            /* translators: 1: sesiones a las que vino, 2: sesiones celebradas */
            __('%1$d de %2$d sesiones', 'sticpa'),
            $att['attended'],
            $att['elapsed']
        )) . '</div>';
        $html .= '<div class="pl-att-meta">' . esc_html(sprintf(
            /* translators: 1: horas asistidas, 2: horas celebradas */
            __('%1$s h de %2$s h hasta hoy', 'sticpa'),
            $att['hours'],
            $att['hours_total']
        )) . '</div>';
        $html .= '</div>';
        $html .= '</div>';
    } else {
        // No está inscrito al evento, así que no hay asistencias que contar. Se
        // dice, porque si no parece un 0 % y es otra cosa.
        $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
            . esc_html__('No está inscrito al evento de sesiones, así que no hay asistencias suyas todavía.', 'sticpa')
            . '</span></p>';
    }
}

// ---------------------------------------------------------------------------
// Titulaciones
// ---------------------------------------------------------------------------

$titulaciones = sticpa_pl_titulaciones($ficha);
if (!empty($titulaciones)) {
    $html .= '<div class="pl-sec">' . esc_html__('Titulaciones', 'sticpa') . '</div>';
    $html .= '<div class="pl-list pl-list--data">';
    foreach ($titulaciones as $t) {
        $html .= '<div class="pl-flagrow">';
        $html .= '<span>' . esc_html($t['label']);
        if ($t['year'] !== '') {
            $html .= ' <span class="pl-rowsub">' . esc_html($t['year']) . '</span>';
        }
        $html .= '</span>';
        if ($t['gap']) {
            // El descuadre típico: dice que la tiene y no hay archivo. Meses
            // después nadie sabe si falta el papel o falta el curso.
            $html .= '<span class="pl-flag pl-flag--warn">' . esc_html__('sin archivo', 'sticpa') . '</span>';
        } else {
            $html .= '<span class="pl-flag pl-flag--yes">' . esc_html__('Sí', 'sticpa') . '</span>';
        }
        $html .= '</div>';
    }
    $html .= '</div>';
} else {
    $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('No tiene ninguna titulación registrada.', 'sticpa') . '</span></p>';
}

// ---------------------------------------------------------------------------
// Contacto
// ---------------------------------------------------------------------------

$phone = sticpa_pl_phone($ficha['phone_mobile']);
$email = trim($ficha['email1']);

if ($phone !== null || $email !== '') {
    $html .= '<div class="pl-sec">' . esc_html__('Contacto', 'sticpa') . '</div>';
    $html .= '<div class="pl-list">';
    if ($phone !== null) {
        $html .= '<div class="pl-phone">';
        $html .= '<div class="pl-phone-who"><span class="pl-phone-label">' . esc_html__('Móvil', 'sticpa') . '</span>'
            . '<span class="pl-phone-num">' . esc_html($phone['display']) . '</span></div>';
        $html .= '<div class="pl-phone-acts">';
        $html .= '<a class="pl-phone-btn pl-phone-btn--wa" href="https://wa.me/' . esc_attr($phone['wa']) . '"'
            . ' target="_blank" rel="noopener" aria-label="' . esc_attr__('WhatsApp', 'sticpa') . '">'
            . '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm5.3 14.1c-.2.6-1.2 1.2-1.7 1.2-.5 0-1 .1-1.8-.2a12 12 0 0 1-5-4.4c-.5-.9-.8-1.7-.8-2.4 0-.7.4-1.4.7-1.7.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .5.4l.7 1.7c.1.2 0 .4-.1.5l-.4.5c-.1.2-.2.3 0 .5.5.9 1.5 1.8 2.4 2.2.2.1.4.1.5 0l.6-.6c.2-.2.3-.1.5-.1l1.6.8c.2.1.3.2.3.3 0 .2 0 .8-.2 1.2Z"/></svg></a>';
        $html .= '<a class="pl-phone-btn pl-phone-btn--call" href="tel:' . esc_attr($phone['tel']) . '"'
            . ' aria-label="' . esc_attr__('Llamar', 'sticpa') . '">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2 4.2 2 2 0 0 1 4 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.4 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.4 1.8.6 2.8.8a2 2 0 0 1 1.7 2Z"/></svg></a>';
        $html .= '</div></div>';
    }
    if ($email !== '') {
        $html .= '<div class="pl-phone">';
        $html .= '<div class="pl-phone-who"><span class="pl-phone-label">' . esc_html__('Correo', 'sticpa') . '</span>'
            . '<span class="pl-phone-num">' . esc_html($email) . '</span></div>';
        $html .= '</div>';
    }
    $html .= '</div>';
}

// ---------------------------------------------------------------------------
// Seguimientos
// ---------------------------------------------------------------------------

/* La sección solo existe si este usuario puede leer o escribir algo. No se
 * pinta un bloque vacío: enseñar una sección que no hace nada insinúa que hay
 * algo detrás, y aquí eso es peor que no enseñarla. */
$segReadable = sticpa_pl_seg_readable($segRoles);
$segWritable = sticpa_pl_seg_writable($segRoles);
$segTipos = sticpa_pl_seg_tipos();

if (sticpa_pl_seguimientos_enabled() && (!empty($segReadable) || !empty($segWritable))) {
    $items = sticpa_pl_seguimientos($objSCP, $monitorId, $segRoles);

    $html .= '<div class="pl-sec">' . esc_html__('Seguimientos', 'sticpa') . '</div>';

    if ($segMsg !== '') {
        $html .= '<p class="pl-notice"><span>' . esc_html($segMsg) . '</span></p>';
    }

    if (!empty($items)) {
        $html .= '<div class="pl-list">';
        foreach ($items as $it) {
            $meta = $segTipos[$it['tipo']];
            $html .= '<div class="pl-seg" style="border-left-color:' . esc_attr($meta['color']) . '">';
            $html .= '<div class="pl-seg-head">';
            $html .= '<span class="pl-seg-tipo" style="background:' . esc_attr($meta['color']) . '">'
                . esc_html($meta['label']) . '</span>';
            if ($it['ts'] > 0) {
                $html .= '<span class="pl-seg-when">' . esc_html(date_i18n('j M Y', $it['ts'])) . '</span>';
            }
            if ($it['autor'] !== '') {
                $html .= '<span class="pl-seg-who">' . esc_html($it['autor']) . '</span>';
            }
            $html .= '</div>';
            $html .= '<div class="pl-seg-text">' . nl2br(esc_html($it['texto'])) . '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';
    } elseif (!empty($segReadable)) {
        $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
            . esc_html__('Todavía no hay seguimientos de esta persona.', 'sticpa') . '</span></p>';
    }

    // El alta: solo los tipos que ESTE usuario puede escribir. Coordinación no
    // ve la opción de acompañamiento, y el guardado lo vuelve a comprobar.
    if (!empty($segWritable)) {
        $html .= '<form method="post" class="pl-newmeet stic-loading-form"'
            . ' data-loading-text="' . esc_attr__('Guardando…', 'sticpa') . '">';
        $html .= wp_nonce_field('pl_seg_' . $monitorId, 'pl_nonce', true, false);

        $html .= '<div class="pl-field-row">';
        $html .= '<label class="pl-field">';
        $html .= '<span class="pl-field-label">' . esc_html__('Tipo', 'sticpa') . '</span>';
        $html .= '<select name="pl_seg_tipo">';
        foreach ($segWritable as $key) {
            $html .= '<option value="' . esc_attr($key) . '">'
                . esc_html($segTipos[$key]['label']) . '</option>';
        }
        $html .= '</select>';
        $html .= '</label>';

        $html .= '<label class="pl-field pl-field--date">';
        $html .= '<span class="pl-field-label">' . esc_html__('Día', 'sticpa') . '</span>';
        // La fecha del HECHO, no la de hoy: se escribe el lunes lo del sábado.
        // Por defecto hoy, que es el caso más frecuente.
        $html .= '<input type="date" name="pl_seg_fecha" value="'
            . esc_attr(date('Y-m-d', sticpa_pl_now())) . '">';
        $html .= '</label>';
        $html .= '</div>';

        $html .= '<label class="pl-field">';
        $html .= '<span class="pl-field-label">' . esc_html__('Qué pasó', 'sticpa') . '</span>';
        $html .= '<textarea name="pl_seg_texto" rows="3" required'
            . ' placeholder="' . esc_attr__('En dos líneas, para acordarse.', 'sticpa') . '"></textarea>';
        $html .= '</label>';

        $html .= '<button type="submit" class="pl-save">' . esc_html__('Guardar seguimiento', 'sticpa') . '</button>';

        if (in_array('acompanamiento', $segWritable, true)) {
            $html .= '<p class="pl-seg-warn">' . sticpa_pl_icon('info') . '<span>'
                . esc_html__('Las notas de acompañamiento solo las ve quien acompaña. Ni coordinación ni la propia persona.', 'sticpa')
                . '</span></p>';
        }
        $html .= '</form>';
    }
}
