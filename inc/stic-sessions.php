<?php
/**
 * SESIONES Y ASISTENCIAS — los listados.
 * ----------------------------------------------------------------------------
 * Los dos son historial, no gestión: no hay nada que editar ni que aprobar
 * desde el área privada. Una sesión es "el martes 4 de 17:30 a 19:00, del
 * campamento"; una asistencia es "a esa sesión fui" o "a esa no".
 *
 * Por eso ninguno de los dos abre ficha, y es a propósito. Una tarjeta que
 * lleva a una pantalla que repite la tarjeta no es navegación: son dos toques
 * para leer lo mismo. La tarjeta de una sesión sí enlaza —a la ACTIVIDAD, que
 * es otra cosa y sí aporta—; la de una asistencia no enlaza a ningún sitio y
 * usa la tarjeta estática que el componente ya sabe pintar.
 *
 * QUÉ CAMBIA RESPECTO DEL VOLCADO GENÉRICO
 *   · Las sesiones se leían "Fecha inicio: 04-11-2025 17:30:00 / Fecha fin:
 *     04-11-2025 19:00:00": dos filas, el día repetido y los segundos. Ahora es
 *     una cápsula con el día y una línea "17:30 – 19:00".
 *   · Las asistencias enseñaban `duration` en crudo (un decimal: "1.5"). Ahora
 *     es "1 h 30 min", y el estado es un chip con su color en vez de texto.
 *   · Los títulos eran "Sessions" y "Attendances", en inglés.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Una duración decimal del CRM, en lenguaje humano.
 *
 * El CRM guarda las horas como decimal (`1.5`), que es correcto para sumar y
 * pésimo para leer: nadie dice "una coma cinco horas". Se traduce a "1 h 30
 * min", y por debajo de la hora, solo a minutos.
 */
function sticpa_duration_text($decimalHours)
{
    $h = (float) $decimalHours;
    if ($h <= 0) {
        return '';
    }
    $minutosTotal = (int) round($h * 60);
    $horas = intdiv($minutosTotal, 60);
    $minutos = $minutosTotal % 60;

    if ($horas === 0) {
        /* translators: %d = minutos */
        return sprintf(__('%d min', 'sticpa'), $minutos);
    }
    if ($minutos === 0) {
        /* translators: %d = horas */
        return sprintf(_n('%d h', '%d h', $horas, 'sticpa'), $horas);
    }
    /* translators: 1 = horas, 2 = minutos */
    return sprintf(__('%1$d h %2$d min', 'sticpa'), $horas, $minutos);
}

/**
 * El tramo horario de algo que empieza y acaba el MISMO día: "17:30 – 19:00".
 *
 * Si cruza la medianoche (o no hay fin), devuelve solo la hora de inicio: un
 * rango que salta de día no se entiende sin la fecha, y la fecha ya está en la
 * cápsula de la tarjeta.
 */
function sticpa_time_range_text($startTs, $endTs = null)
{
    if (!$startTs) {
        return '';
    }
    $fmt = function ($ts) {
        return date_i18n(get_option('time_format') ?: 'H:i', $ts);
    };
    if (!$endTs || date('Y-m-d', $startTs) !== date('Y-m-d', $endTs) || $endTs <= $startTs) {
        return $fmt($startTs);
    }
    /* translators: 1 = hora de inicio, 2 = hora de fin */
    return sprintf(__('%1$s – %2$s', 'sticpa'), $fmt($startTs), $fmt($endTs));
}

/* ==========================================================================
   1. SESIONES
   ========================================================================== */

/** Campos que se piden al CRM para el listado de sesiones. */
function sticpa_session_list_fields()
{
    return array(
        'id',
        'name',
        'start_date',
        'end_date',
        'stic_sessions_stic_events_name',
        // El id del evento: es lo que hace que la tarjeta pueda llevar a la
        // actividad, que es el único sitio al que tiene sentido ir desde aquí.
        'stic_sessions_stic_eventsstic_events_ida',
    );
}

/** Normaliza una sesión del CRM. */
function sticpa_session_view_model($nvl)
{
    $val = function ($field) use ($nvl) {
        return isset($nvl->$field->value) ? trim((string) $nvl->$field->value) : '';
    };

    $name = $val('name');
    if ($name === '') {
        return null;
    }
    $startTs = $val('start_date') !== '' ? strtotime($val('start_date')) : null;
    $endTs   = $val('end_date') !== '' ? strtotime($val('end_date')) : null;

    return array(
        'id'         => $val('id'),
        'name'       => $name,
        'event_name' => $val('stic_sessions_stic_events_name'),
        'event_id'   => $val('stic_sessions_stic_eventsstic_events_ida'),
        'start_ts'   => $startTs,
        'end_ts'     => $endTs,
        'is_past'    => ($startTs !== null && $startTs < strtotime('today')),
    );
}

/** LISTADO de sesiones como tarjetas. */
function sticpa_sessions_list_html($rows)
{
    $models = array();
    foreach ((array) $rows as $row) {
        $nvl = $row->name_value_list ?? null;
        if (!$nvl) {
            continue;
        }
        $model = sticpa_session_view_model($nvl);
        if ($model) {
            $models[] = $model;
        }
    }

    if (empty($models)) {
        return sticpa_record_empty_html(
            'clock',
            __('No hay sesiones programadas', 'sticpa'),
            __('Cuando una actividad en la que estás inscrito tenga sesiones, aparecerán aquí con su día y su hora.', 'sticpa'),
            array('label' => __('Ver mis inscripciones', 'sticpa'), 'url' => '?internalpage=list_stic_registrations')
        );
    }

    // Lo que está por venir primero, y dentro de eso lo más próximo: a una
    // lista de sesiones se entra a saber cuándo es la siguiente.
    usort($models, function ($a, $b) {
        if ($a['is_past'] !== $b['is_past']) {
            return $a['is_past'] ? 1 : -1;
        }
        $cmp = ($a['start_ts'] ?? PHP_INT_MAX) <=> ($b['start_ts'] ?? PHP_INT_MAX);
        return $a['is_past'] ? -$cmp : $cmp;
    });

    $cards = array();
    foreach ($models as $ses) {
        $lines = array();
        $hora = sticpa_time_range_text($ses['start_ts'], $ses['end_ts']);
        if ($hora !== '') {
            $lines[] = array('icon' => 'clock', 'text' => $hora);
        }
        if ($ses['event_name'] !== '' && $ses['event_name'] !== $ses['name']) {
            $lines[] = array('icon' => 'calendar', 'text' => $ses['event_name']);
        }

        $cards[] = array(
            // Enlaza a la ACTIVIDAD, no a una ficha de la sesión: la sesión ya
            // está entera en la tarjeta y la actividad sí tiene más que contar.
            'url'     => $ses['event_id'] !== ''
                ? '?internalpage=single_stic_events&action=detail&id=' . rawurlencode($ses['event_id'])
                : '',
            'ts'      => $ses['start_ts'],
            'icon'    => 'clock',
            'name'    => $ses['name'],
            'lines'   => $lines,
            'is_past' => $ses['is_past'],
        );
    }

    return sticpa_record_list_html($cards);
}

/* ==========================================================================
   2. ASISTENCIAS
   ========================================================================== */

/** Campos que se piden al CRM para el listado de asistencias. */
function sticpa_attendance_list_fields()
{
    return array(
        'id',
        'name',
        'status',
        'start_date',
        'duration',
        'stic_attendances_stic_sessions_name',
        'stic_attendances_stic_registrations_name',
    );
}

/** Normaliza una asistencia del CRM. */
function sticpa_attendance_view_model($nvl)
{
    $val = function ($field) use ($nvl) {
        return isset($nvl->$field->value) ? trim((string) $nvl->$field->value) : '';
    };

    $sessionName = $val('stic_attendances_stic_sessions_name');
    $ownName = $val('name');
    // Se enseña la SESIÓN: es a lo que fuiste (o no). El nombre propio del
    // registro de asistencia es un código administrativo.
    $title = $sessionName !== '' ? $sessionName : $ownName;
    if ($title === '') {
        return null;
    }

    return array(
        'id'        => $val('id'),
        'title'     => $title,
        'status'    => $val('status'),
        'start_ts'  => $val('start_date') !== '' ? strtotime($val('start_date')) : null,
        'duration'  => $val('duration'),
        'reg_name'  => $val('stic_attendances_stic_registrations_name'),
    );
}

/**
 * LISTADO de asistencias como tarjetas.
 *
 * @param array $rows       Registros del CRM.
 * @param array $definition Definición cacheada del módulo (etiqueta del estado).
 */
function sticpa_attendances_list_html($rows, $definition = array())
{
    $models = array();
    foreach ((array) $rows as $row) {
        $nvl = $row->name_value_list ?? null;
        if (!$nvl) {
            continue;
        }
        $model = sticpa_attendance_view_model($nvl);
        if ($model) {
            $models[] = $model;
        }
    }

    if (empty($models)) {
        return sticpa_record_empty_html(
            'check',
            __('Todavía no hay asistencias registradas', 'sticpa'),
            __('Cuando se pase lista en una sesión, quedará aquí el registro de si fuiste.', 'sticpa')
        );
    }

    // Historial: lo más reciente arriba.
    usort($models, function ($a, $b) {
        return ($b['start_ts'] ?? 0) <=> ($a['start_ts'] ?? 0);
    });

    $cards = array();
    foreach ($models as $att) {
        $lines = array();
        $hora = $att['start_ts'] ? sticpa_time_range_text($att['start_ts']) : '';
        $duracion = sticpa_duration_text($att['duration']);
        // La hora y la duración, juntas en una línea: son la misma idea
        // ("cuándo y cuánto") y por separado gastan dos líneas de tarjeta.
        $cuando = implode(' · ', array_filter(array($hora, $duracion)));
        if ($cuando !== '') {
            $lines[] = array('icon' => 'clock', 'text' => $cuando);
        }
        if ($att['reg_name'] !== '' && $att['reg_name'] !== $att['title']) {
            $lines[] = array('icon' => 'calendar', 'text' => $att['reg_name']);
        }

        $chips = array();
        $statusLabel = sticpa_record_enum_label($definition, 'status', $att['status']);
        if ($statusLabel !== '') {
            $chips[] = array('label' => $statusLabel, 'tone' => sticpa_record_status_tone($att['status']));
        }

        $cards[] = array(
            // SIN enlace, a propósito: la tarjeta ya lo dice todo y no hay
            // ninguna pantalla que añada nada. El componente pinta la tarjeta
            // estática, sin fingir que se puede abrir.
            'ts'      => $att['start_ts'],
            'icon'    => 'check',
            'name'    => $att['title'],
            'lines'   => $lines,
            'chips'   => $chips,
            'actions' => array(),
        );
    }

    return sticpa_record_list_html($cards);
}
