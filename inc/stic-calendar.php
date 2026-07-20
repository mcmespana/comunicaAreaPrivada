<?php
/**
 * ============================================================================
 *  HELPER COMPARTIDO DEL CALENDARIO  (inc/stic-calendar.php)
 * ----------------------------------------------------------------------------
 * Una sola fuente de verdad para TODO lo relacionado con el calendario de
 * actividades del área privada, reutilizada por:
 *   · pages/single_stic_activities_calendar.php  → el calendario completo (FullCalendar).
 *   · pages/single_stic_home.php                 → el widget "Próximas actividades".
 *
 * Modelo de datos en el CRM (SinergiaCRM/SuiteCRM):
 *   Contacto ─(stic_registrations_contacts)→ Inscripción
 *   Inscripción ─(stic_registrations_stic_events)→ Evento
 *   Evento ─(stic_sessions_stic_events)→ Sesión              (fecha/hora inicio y fin)
 *   Inscripción ─(stic_attendances_stic_registrations)→ Asistencia
 *   Asistencia ─(stic_attendances_stic_sessions)→ Sesión     (hereda fecha; tiene `status`)
 *
 * Estados de asistencia (campo `status` de stic_Attendances):
 *   vacío → aún no marcada · "Sí" → asistió · "Parcial" → parcial ·
 *   "No, justificada" → falta justificada · "No, sin justificar" → falta sin justificar.
 * Las claves internas del desplegable varían entre instancias, así que el
 * clasificador (sticpa_classify_attendance) es tolerante: reconoce varias
 * convenciones de clave y, como último recurso, la etiqueta localizada.
 *
 * RENDIMIENTO: la recogida hace varias llamadas al CRM (N+1 por diseño de la
 * API v4.1). Se cachea en un transient por usuario (TTL corto) para que la home
 * y el calendario compartan el resultado y no machaquen el CRM en cada carga.
 * ============================================================================
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Paleta ÚNICA de colores + etiquetas de cada estado del calendario.
 *
 * Los hex coinciden a propósito con los design tokens de :root (§1 de
 * custom-style.css): azul --primary #1c6fb3, verde --success #2f9e44, ámbar
 * --warning #f59e0b, rojo --danger #dc2626, violeta --accent #6c4b9e, gris
 * --gray-400 #9ca3af. La única excepción es 'partial' (teal #0ca678), un matiz
 * emparentado con el verde de "asististe". Se usan como DATO en los eventos de
 * FullCalendar y como color en línea de los puntos de la leyenda/agenda, de modo
 * que leyenda y eventos SIEMPRE coinciden (misma fuente, cero desincronización).
 */
function sticpa_calendar_palette()
{
    return array(
        'upcoming' => array(
            'color' => '#1c6fb3', 'text' => '#ffffff',
            'label' => __('Próxima', 'sticpa'),
        ),
        'attended' => array(
            'color' => '#2f9e44', 'text' => '#ffffff',
            'label' => __('Asististe', 'sticpa'),
        ),
        'partial' => array(
            'color' => '#0ca678', 'text' => '#ffffff',
            'label' => __('Asistencia parcial', 'sticpa'),
        ),
        'absent_justified' => array(
            'color' => '#f59e0b', 'text' => '#3d2600',
            'label' => __('Falta justificada', 'sticpa'),
        ),
        'absent_unjustified' => array(
            'color' => '#dc2626', 'text' => '#ffffff',
            'label' => __('No asististe', 'sticpa'),
        ),
        'pending' => array(
            'color' => '#9ca3af', 'text' => '#ffffff',
            'label' => __('Sin registrar', 'sticpa'),
        ),
        'available_event' => array(
            'color' => '#6c4b9e', 'text' => '#ffffff',
            'label' => __('Abierto a inscripción', 'sticpa'),
        ),
        'registered_event' => array(
            'color' => '#155a92', 'text' => '#ffffff',
            'label' => __('Estás inscrito', 'sticpa'),
        ),
    );
}

/**
 * Clasifica el valor de `status` de una asistencia en una de las clases
 * semánticas: 'attended' | 'partial' | 'absent_justified' | 'absent_unjustified'
 * | '' (sin marcar / desconocido). Tolerante a la clave interna y a la etiqueta.
 */
function sticpa_classify_attendance($statusKey, $statusLabel = '')
{
    $k = strtolower(trim((string) $statusKey));
    $l = strtolower(trim((string) $statusLabel));
    // Normaliza acentos del label (es/ca) para comparar sin sorpresas.
    $l = strtr($l, array('í' => 'i', 'á' => 'a', 'é' => 'e', 'ó' => 'o', 'ú' => 'u', 'ï' => 'i'));

    if ($k === '' && $l === '') {
        return ''; // sin marcar
    }

    // --- Asistió (sesión completa) ---
    if (in_array($k, array('yes', 'si', '1', 'attended', 'present', 'asistio'), true)) {
        return 'attended';
    }
    // --- Parcial ---
    if (strpos($k, 'partial') !== false || strpos($k, 'parcial') !== false) {
        return 'partial';
    }

    // --- Ausencias: varias convenciones posibles de clave ---
    $looksAbsent = ($k === 'no' || $k === '0'
        || strpos($k, 'absent') !== false || strpos($k, 'ausen') !== false
        || strpos($k, 'justif') !== false || strpos($k, 'falta') !== false
        || strpos($k, 'unjust') !== false || strpos($k, 'no_') === 0);
    if ($looksAbsent) {
        if (strpos($k, 'sin') !== false || strpos($k, 'unjust') !== false
            || strpos($k, 'no_unjust') !== false || strpos($k, '_un') !== false) {
            return 'absent_unjustified';
        }
        if (strpos($k, 'justif') !== false) {
            return 'absent_justified';
        }
        return 'absent_unjustified'; // "no" a secas → sin justificar
    }

    // --- Fallback por etiqueta localizada ---
    if ($l !== '') {
        if (strpos($l, 'parcial') !== false) {
            return 'partial';
        }
        if ($l === 'si' || strpos($l, 'si,') !== false || strpos($l, 'asisti') !== false) {
            return 'attended';
        }
        if (strpos($l, 'sin justif') !== false) {
            return 'absent_unjustified';
        }
        if (strpos($l, 'justif') !== false) {
            return 'absent_justified';
        }
        if (strpos($l, 'no') === 0) {
            return 'absent_unjustified';
        }
    }
    return ''; // desconocido → se trata como sin marcar
}

/**
 * Decide el "bucket" de color de una SESIÓN combinando su estado de asistencia
 * y su fecha. Una sesión que aún no ha llegado siempre es 'upcoming' (todavía no
 * se puede haber asistido), pase lo que pase con el estado.
 */
function sticpa_session_bucket($statusClass, $startTs, $nowTs)
{
    if ($startTs !== null && $startTs !== false && $startTs > $nowTs) {
        return 'upcoming'; // aún no ha llegado / no se ha pasado
    }
    switch ($statusClass) {
        case 'attended':
            return 'attended';
        case 'partial':
            return 'partial';
        case 'absent_justified':
            return 'absent_justified';
        case 'absent_unjustified':
            return 'absent_unjustified';
    }
    return 'pending'; // ya pasada pero sin estado registrado
}

/**
 * Clave del transient de caché para el usuario/participante activo.
 */
function sticpa_calendar_cache_key()
{
    $userId = $_SESSION['scp_user_id'] ?? '';
    $module = function_exists('getDestinationModule') ? getDestinationModule() : 'Contacts';
    return 'sticpa_cal_' . md5($module . '|' . $userId);
}

/**
 * Invalida la caché del calendario del usuario activo (p. ej. tras inscribirse).
 */
function sticpa_calendar_flush_cache()
{
    if (!empty($_SESSION['scp_user_id'])) {
        delete_transient(sticpa_calendar_cache_key());
    }
}

/**
 * Recoge del CRM todo lo que el calendario necesita para el usuario activo.
 * Devuelve un array con:
 *   'sessions'              => [ ['id','title','event_id','event_name','start','end'], … ]
 *   'attendance_by_session' => [ sessionId => statusKey, … ]
 *   'registered_events'     => [ ['id','name','start','end'], … ]
 *   'available_events'      => [ ['id','name','start','end'], … ]   (no inscritos)
 * Cacheado en transient (TTL vía filtro 'sticpa_calendar_cache_ttl', 300s por defecto).
 */
function sticpa_gather_calendar_data($objSCP)
{
    $userId = $_SESSION['scp_user_id'] ?? '';
    $ttl = (int) apply_filters('sticpa_calendar_cache_ttl', 300);
    $cacheKey = sticpa_calendar_cache_key();

    if ($ttl > 0 && $userId) {
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $module = function_exists('getDestinationModule') ? getDestinationModule() : 'Contacts';
    $regRel = ($module === 'Accounts') ? 'stic_registrations_accounts' : 'stic_registrations_contacts';

    $registeredEventIds = array();
    $registeredEvents = array();   // evId => [...]
    $sessions = array();           // sessionId => [...]
    $sessionIdByName = array();    // nombre normalizado → sessionId (para el fallback de asistencias)
    $attendanceBySession = array();// sessionId => statusKey

    // 1) Inscripciones del usuario (se ignoran las canceladas).
    $regs = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => $module,
        'module_id' => $userId,
        'link_field_name' => $regRel,
        'related_fields' => array('id', 'status'),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
    ));

    if (is_array($regs)) {
        foreach ($regs as $reg) {
            $regId = $reg->name_value_list->id->value ?? ($reg->id ?? null);
            $regStatus = $reg->name_value_list->status->value ?? '';
            if (!$regId || $regStatus === 'cancelled') {
                continue;
            }

            // 1a) Eventos de la inscripción.
            $events = $objSCP->getRelatedElementsForLoggedUser(array(
                'module_name' => 'stic_Registrations',
                'module_id' => $regId,
                'link_field_name' => 'stic_registrations_stic_events',
                'related_fields' => array('id', 'name', 'start_date', 'end_date'),
                'related_module_link_name_to_fields_array' => array(),
                'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
            ));
            if (is_array($events)) {
                foreach ($events as $ev) {
                    $evData = $ev->name_value_list;
                    $evId = $evData->id->value ?? null;
                    if (!$evId) {
                        continue;
                    }
                    if (!isset($registeredEvents[$evId])) {
                        $registeredEvents[$evId] = array(
                            'id' => $evId,
                            'name' => $evData->name->value ?? '',
                            'start' => $evData->start_date->value ?? '',
                            'end' => $evData->end_date->value ?? '',
                        );
                        $registeredEventIds[] = $evId;
                    }

                    // 1b) Sesiones del evento.
                    $sess = $objSCP->getRelatedElementsForLoggedUser(array(
                        'module_name' => 'stic_Events',
                        'module_id' => $evId,
                        'link_field_name' => 'stic_sessions_stic_events',
                        'related_fields' => array('id', 'name', 'start_date', 'end_date'),
                        'related_module_link_name_to_fields_array' => array(),
                        'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
                    ));
                    if (is_array($sess)) {
                        foreach ($sess as $s) {
                            $sd = $s->name_value_list;
                            $sid = $sd->id->value ?? null;
                            if (!$sid || isset($sessions[$sid])) {
                                continue;
                            }
                            $sName = $sd->name->value ?? '';
                            $sessions[$sid] = array(
                                'id' => $sid,
                                'title' => $sName,
                                'event_id' => $evId,
                                'event_name' => $registeredEvents[$evId]['name'],
                                'start' => $sd->start_date->value ?? '',
                                'end' => $sd->end_date->value ?? '',
                            );
                            $nkey = strtolower(trim($sName));
                            if ($nkey !== '') {
                                // Solo sirve de fallback si el nombre es único.
                                $sessionIdByName[$nkey] = isset($sessionIdByName[$nkey]) ? false : $sid;
                            }
                        }
                    }
                }
            }

            // 1c) Asistencias de la inscripción → mapa sesión → estado.
            $atts = $objSCP->getRelatedElementsForLoggedUser(array(
                'module_name' => 'stic_Registrations',
                'module_id' => $regId,
                'link_field_name' => 'stic_attendances_stic_registrations',
                'related_fields' => array(
                    'id', 'status', 'start_date',
                    'stic_attendances_stic_sessionsstic_sessions_ida',
                    'stic_attendances_stic_sessions_name',
                ),
                'related_module_link_name_to_fields_array' => array(),
                'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
            ));
            if (is_array($atts)) {
                foreach ($atts as $a) {
                    $ad = $a->name_value_list;
                    $statusKey = $ad->status->value ?? '';
                    $sid = $ad->stic_attendances_stic_sessionsstic_sessions_ida->value ?? '';
                    // Fallback: si la API no devolvió el id de la sesión, casa por nombre único.
                    if ($sid === '' || $sid === null) {
                        $an = strtolower(trim($ad->stic_attendances_stic_sessions_name->value ?? ''));
                        if ($an !== '' && !empty($sessionIdByName[$an])) {
                            $sid = $sessionIdByName[$an];
                        }
                    }
                    if ($sid !== '' && $sid !== null) {
                        $attendanceBySession[$sid] = $statusKey;
                    }
                }
            }
        }
    }

    // 2) Todos los eventos en la ventana (-3 meses … +12 meses). Los ya inscritos
    //    se apartan; el resto son "abiertos a inscripción".
    $filter = "(stic_events.start_date BETWEEN DATE_ADD(curdate(), INTERVAL -3 MONTH) AND DATE_ADD(curdate(), INTERVAL 12 MONTH))";
    $allEvents = $objSCP->getRecordsModule('stic_Events', $filter, array('id', 'name', 'type', 'start_date', 'end_date'));
    $availableEvents = array();
    if (is_array($allEvents)) {
        foreach ($allEvents as $ev) {
            $evData = $ev->name_value_list;
            $evId = $evData->id->value ?? null;
            if (!$evId || in_array($evId, $registeredEventIds, true)) {
                continue;
            }
            $availableEvents[] = array(
                'id' => $evId,
                'name' => $evData->name->value ?? '',
                'start' => $evData->start_date->value ?? '',
                'end' => $evData->end_date->value ?? '',
            );
        }
    }

    $data = array(
        'sessions' => array_values($sessions),
        'attendance_by_session' => $attendanceBySession,
        'registered_events' => array_values($registeredEvents),
        'available_events' => $availableEvents,
    );

    if ($ttl > 0 && $userId) {
        set_transient($cacheKey, $data, $ttl);
    }
    return $data;
}

/**
 * FullCalendar espera el `end` de un evento de día completo en formato EXCLUSIVO
 * (un día más que el último día visible). Recibe una fecha inclusiva (Y-m-d…) y
 * devuelve el día siguiente.
 */
function sticpa_fc_all_day_end($dateStr)
{
    $d = substr((string) $dateStr, 0, 10);
    $ts = strtotime($d);
    if (!$ts) {
        return $d;
    }
    return date('Y-m-d', $ts + DAY_IN_SECONDS);
}

/**
 * Construye el array de eventos para FullCalendar a partir de los datos crudos.
 * Tres capas visuales:
 *   · Eventos inscritos      → barra de día completo, azul suave (contexto).
 *   · Eventos disponibles    → barra de día completo, violeta (CTA "inscríbete").
 *   · Sesiones               → bloques con hora, coloreados por asistencia.
 * Cada evento lleva extendedProps.href (destino al hacer clic) y .tooltip.
 */
function sticpa_calendar_fc_events($data)
{
    $palette = sticpa_calendar_palette();
    $now = time();
    $events = array();

    // --- Eventos en los que YA estás inscrito (contexto) ---
    foreach ($data['registered_events'] as $ev) {
        if (empty($ev['start'])) {
            continue;
        }
        $meta = $palette['registered_event'];
        $events[] = array(
            'title' => $ev['name'],
            'start' => substr($ev['start'], 0, 10),
            'end' => sticpa_fc_all_day_end($ev['end'] ?: $ev['start']),
            'allDay' => true,
            'display' => 'block',
            'color' => $meta['color'],
            'textColor' => $meta['text'],
            'classNames' => array('stic-fc-registered'),
            'extendedProps' => array(
                'kind' => 'registered_event',
                'href' => '?internalpage=list_stic_registrations',
                'tooltip' => $ev['name'] . ' · ' . $meta['label'],
            ),
        );
    }

    // --- Eventos abiertos a inscripción (CTA) ---
    foreach ($data['available_events'] as $ev) {
        if (empty($ev['start'])) {
            continue;
        }
        $meta = $palette['available_event'];
        $events[] = array(
            'title' => $ev['name'],
            'start' => substr($ev['start'], 0, 10),
            'end' => sticpa_fc_all_day_end($ev['end'] ?: $ev['start']),
            'allDay' => true,
            'display' => 'block',
            'color' => $meta['color'],
            'textColor' => $meta['text'],
            'classNames' => array('stic-fc-available'),
            'extendedProps' => array(
                'kind' => 'available_event',
                'href' => '?internalpage=single_stic_registrations&action=create&from=stic_events&id=' . $ev['id'],
                'tooltip' => $ev['name'] . ' · ' . $meta['label'],
            ),
        );
    }

    // --- Sesiones de mis eventos, coloreadas por asistencia ---
    foreach ($data['sessions'] as $s) {
        if (empty($s['start'])) {
            continue;
        }
        $statusKey = $data['attendance_by_session'][$s['id']] ?? '';
        $cls = sticpa_classify_attendance($statusKey);
        $startLocal = get_date_from_gmt($s['start']); // 'Y-m-d H:i:s' en hora local
        $startTs = strtotime($startLocal);
        $bucket = sticpa_session_bucket($cls, $startTs, $now);
        $meta = $palette[$bucket];
        $endLocal = !empty($s['end']) ? get_date_from_gmt($s['end']) : '';
        $events[] = array(
            'id' => $s['id'],
            'title' => $s['title'],
            'start' => str_replace(' ', 'T', $startLocal),
            'end' => $endLocal ? str_replace(' ', 'T', $endLocal) : null,
            'color' => $meta['color'],
            'textColor' => $meta['text'],
            'classNames' => array('stic-fc-session', 'stic-fc-' . $bucket),
            'extendedProps' => array(
                'kind' => 'session',
                'href' => '?internalpage=single_stic_sessions&action=detail&id=' . $s['id'],
                'tooltip' => $s['title']
                    . ($s['event_name'] ? ' · ' . $s['event_name'] : '')
                    . ' · ' . $meta['label'],
            ),
        );
    }

    return $events;
}

/**
 * Leyenda del calendario generada desde la paleta (así nunca se desincroniza de
 * los colores reales). Cada punto lleva su color en línea desde la misma fuente.
 * $buckets: claves de la paleta a mostrar, en orden.
 */
function sticpa_calendar_legend_html($buckets = null)
{
    $palette = sticpa_calendar_palette();
    if ($buckets === null) {
        $buckets = array(
            'upcoming', 'attended', 'partial',
            'absent_justified', 'absent_unjustified', 'pending',
            'available_event', 'registered_event',
        );
    }
    $out = "<ul class='stic-calendar-legend' aria-label='" . esc_attr__('Leyenda de colores', 'sticpa') . "'>";
    foreach ($buckets as $b) {
        if (!isset($palette[$b])) {
            continue;
        }
        $meta = $palette[$b];
        $out .= "<li class='stic-legend-item'>"
            . "<span class='stic-legend-dot' style='background:" . esc_attr($meta['color']) . "' aria-hidden='true'></span>"
            . "<span>" . esc_html($meta['label']) . "</span>"
            . "</li>";
    }
    $out .= "</ul>";
    return $out;
}

/**
 * Elementos "próximos" para el widget de la home: fusiona sesiones futuras (de
 * hoy en adelante) y eventos abiertos a inscripción, ordenados por fecha.
 * Devuelve como mucho $limit elementos.
 */
function sticpa_home_agenda_items($data, $limit = 5)
{
    $palette = sticpa_calendar_palette();
    $todayTs = strtotime('today');
    $items = array();

    foreach ($data['sessions'] as $s) {
        if (empty($s['start'])) {
            continue;
        }
        $startLocal = get_date_from_gmt($s['start']);
        $ts = strtotime($startLocal);
        if ($ts === false || $ts < $todayTs) {
            continue; // solo próximas
        }
        $items[] = array(
            'ts' => $ts,
            'has_time' => (strpos($s['start'], ' ') !== false && substr($startLocal, 11, 5) !== '00:00'),
            'type' => 'session',
            'title' => $s['title'],
            'subtitle' => $s['event_name'],
            'bucket' => 'upcoming',
            'href' => '?internalpage=single_stic_sessions&action=detail&id=' . $s['id'],
        );
    }

    foreach ($data['available_events'] as $ev) {
        if (empty($ev['start'])) {
            continue;
        }
        $ts = strtotime(substr($ev['start'], 0, 10));
        if ($ts === false || $ts < $todayTs) {
            continue;
        }
        $items[] = array(
            'ts' => $ts,
            'has_time' => false,
            'type' => 'available_event',
            'title' => $ev['name'],
            'subtitle' => $palette['available_event']['label'],
            'bucket' => 'available_event',
            'href' => '?internalpage=single_stic_registrations&action=create&from=stic_events&id=' . $ev['id'],
        );
    }

    usort($items, function ($a, $b) {
        return $a['ts'] <=> $b['ts'];
    });

    return array_slice($items, 0, max(1, (int) $limit));
}

/**
 * HTML del widget "Próximas actividades" de la home. Ligero (sin FullCalendar):
 * una tarjeta con la lista de próximos elementos y un botón al calendario.
 */
function sticpa_home_agenda_html($objSCP)
{
    $data = sticpa_gather_calendar_data($objSCP);
    $items = sticpa_home_agenda_items($data, 5);
    $palette = sticpa_calendar_palette();
    $calUrl = '?internalpage=single_stic_activities_calendar';

    $calIcon = "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'><rect x='3' y='4' width='18' height='18' rx='2'/><path d='M16 2v4M8 2v4M3 10h18'/></svg>";
    $goIcon = "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'><path d='M5 12h14'/><path d='m13 6 6 6-6 6'/></svg>";

    $html = "<section class='stic-agenda' aria-labelledby='stic-agenda-title'>";
    $html .= "<div class='stic-agenda-head'>"
        . "<span class='stic-agenda-ico'>" . $calIcon . "</span>"
        . "<h3 id='stic-agenda-title' class='stic-agenda-title'>" . esc_html__('Próximas actividades', 'sticpa') . "</h3>"
        . "</div>";

    if (empty($items)) {
        $html .= "<div class='stic-agenda-empty'>"
            . "<p class='stic-agenda-empty-title'>" . esc_html__('No tienes actividades próximas', 'sticpa') . "</p>"
            . "<p class='stic-agenda-empty-sub'>" . esc_html__('Cuando te inscribas a un evento o haya sesiones programadas, aparecerán aquí.', 'sticpa') . "</p>"
            . "</div>";
    } else {
        $html .= "<ul class='stic-agenda-list'>";
        foreach ($items as $it) {
            $meta = $palette[$it['bucket']] ?? $palette['upcoming'];
            $day = date_i18n('j', $it['ts']);
            $mon = date_i18n('M', $it['ts']);
            $weekday = date_i18n('D', $it['ts']);
            $timeStr = $it['has_time'] ? date_i18n('H:i', $it['ts']) : '';
            $badge = ($it['type'] === 'available_event')
                ? "<span class='stic-agenda-badge'>" . esc_html__('Inscríbete', 'sticpa') . "</span>"
                : ($timeStr ? "<span class='stic-agenda-time'>" . esc_html($timeStr) . "</span>" : '');

            $html .= "<li class='stic-agenda-item'>"
                . "<a class='stic-agenda-link' href='" . esc_url($it['href']) . "'>"
                . "<span class='stic-agenda-date' aria-hidden='true'>"
                . "<span class='stic-agenda-wd'>" . esc_html($weekday) . "</span>"
                . "<span class='stic-agenda-day'>" . esc_html($day) . "</span>"
                . "<span class='stic-agenda-mon'>" . esc_html($mon) . "</span>"
                . "</span>"
                . "<span class='stic-agenda-body'>"
                . "<span class='stic-agenda-item-title'>"
                . "<span class='stic-agenda-dot' style='background:" . esc_attr($meta['color']) . "' aria-hidden='true'></span>"
                . esc_html($it['title'])
                . "</span>"
                . ($it['subtitle'] ? "<span class='stic-agenda-sub'>" . esc_html($it['subtitle']) . "</span>" : '')
                . "</span>"
                . $badge
                . "</a></li>";
        }
        $html .= "</ul>";
    }

    $html .= "<a class='stic-agenda-cta' href='" . esc_url($calUrl) . "'>"
        . "<span>" . esc_html__('Ver calendario', 'sticpa') . "</span> " . $goIcon
        . "</a>";
    $html .= "</section>";

    return $html;
}
