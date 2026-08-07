<?php
/**
 * EVENTOS — presentación (tarjetas del listado y ficha de detalle).
 * ----------------------------------------------------------------------------
 * Antes, "Eventos" se pintaba con el renderizador genérico de listados
 * (makeList), que produce filas "ETIQUETA: valor". Para un evento eso es un
 * mal formato: lo que una persona necesita saber es CUÁNDO es y SI puede
 * apuntarse, y eso quedaba repartido en tres filas de jerga administrativa
 * (Estado / Fecha inicio / Fecha fin) que ocupaban media pantalla de móvil.
 *
 * Aquí vive el formato propio de evento:
 *   · sticpa_event_view_model()  — normaliza un registro del CRM (fechas,
 *     estado, duración) para que listado y detalle digan exactamente lo mismo.
 *   · sticpa_event_date_line()   — el rango de fechas en lenguaje humano
 *     ("del 1 al 10 de julio de 2026", "5 de mayo de 2026").
 *   · sticpa_events_list_html()  — el listado como tarjetas.
 *   · sticpa_event_detail_html() — la ficha del evento.
 *
 * CAMPOS DEL CRM: se usa lo que hoy expone stic_Events (name, status, type,
 * start_date, end_date, description). Todo lo demás es OPCIONAL y se pinta
 * solo si existe, así que añadir campos en SinergiaCRM (lugar, plazas, precio,
 * hora…) los hace aparecer sin tocar este archivo: basta con incluirlos en
 * $optional de sticpa_event_view_model(). Ver docs/comunica/EVENTOS.md.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Campos OPCIONALES de stic_Events que la ficha sabe pintar si existen en el
 * CRM. Clave = nombre del campo en SinergiaCRM; valor = cómo mostrarlo.
 * Añadir aquí un campo nuevo es todo lo que hace falta para que salga.
 */
function sticpa_event_optional_fields()
{
    return apply_filters('sticpa_event_optional_fields', array(
        // campo CRM        => array(etiqueta, icono, formato)
        'location'          => array('label' => __('Lugar', 'sticpa'),        'icon' => 'pin',    'format' => 'text'),
        'city'              => array('label' => __('Población', 'sticpa'),    'icon' => 'pin',    'format' => 'text'),
        'address'           => array('label' => __('Dirección', 'sticpa'),    'icon' => 'pin',    'format' => 'text'),
        'start_time'        => array('label' => __('Hora', 'sticpa'),         'icon' => 'clock',  'format' => 'text'),
        'capacity'          => array('label' => __('Plazas', 'sticpa'),       'icon' => 'users',  'format' => 'text'),
        'price'             => array('label' => __('Precio', 'sticpa'),       'icon' => 'euro',   'format' => 'currency'),
        'registration_end'  => array('label' => __('Inscripción hasta', 'sticpa'), 'icon' => 'clock', 'format' => 'date'),
    ));
}

/**
 * Campos que hay que PEDIR al CRM para pintar un evento: los básicos más los
 * opcionales QUE EXISTAN de verdad en este SinergiaCRM.
 *
 * Pedir a get_entry_list un campo inexistente es buscarse un problema (según la
 * versión, devuelve error en vez de ignorarlo), así que primero se pregunta al
 * módulo qué campos tiene — get_module_fields solo devuelve los que existen — y
 * se cruza con la lista de deseados. La definición va cacheada 6h
 * (sticpa_cached_field_definition), así que esto no añade una llamada por vista.
 */
function sticpa_event_fields_to_request($objSCP)
{
    $base = array('id', 'name', 'status', 'type', 'start_date', 'end_date', 'description');
    $wanted = array_keys(sticpa_event_optional_fields());
    if (empty($wanted) || !function_exists('sticpa_cached_field_definition')) {
        return $base;
    }
    $definition = sticpa_cached_field_definition($objSCP, 'stic_Events', array_merge($base, $wanted));
    $existing = is_array($definition) ? array_keys($definition) : array();
    return array_values(array_unique(array_merge($base, array_intersect($wanted, $existing))));
}

/** Iconos en línea de la ficha (mismo trazo que el resto del área). */
function sticpa_event_icon($name)
{
    $paths = array(
        'calendar' => "<rect x='3' y='4' width='18' height='18' rx='2'/><path d='M16 2v4M8 2v4M3 10h18'/>",
        'pin'      => "<path d='M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z'/><circle cx='12' cy='10' r='3'/>",
        'clock'    => "<circle cx='12' cy='12' r='9'/><path d='M12 7v5l3 2'/>",
        'users'    => "<path d='M16 21v-2a4 4 0 0 0-8 0v2'/><circle cx='12' cy='7' r='4'/>",
        'euro'     => "<path d='M18 7a6 6 0 1 0 0 10'/><path d='M4 10h8M4 14h8'/>",
        'tag'      => "<path d='M20.6 13.4 12 22l-9-9V4h9z'/><circle cx='7.5' cy='7.5' r='1.5'/>",
        'go'       => "<path d='M5 12h14'/><path d='m13 6 6 6-6 6'/>",
        'back'     => "<path d='M19 12H5'/><path d='m11 18-6-6 6-6'/>",
    );
    $d = $paths[$name] ?? $paths['calendar'];
    return "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'>{$d}</svg>";
}

/**
 * Normaliza un evento del CRM a lo que necesita la interfaz. Devuelve null si
 * el registro no tiene ni nombre (fila basura).
 *
 * @param object $nvl name_value_list del registro (getRecordsModule/getRecordDetail).
 */
function sticpa_event_view_model($nvl)
{
    $val = function ($field) use ($nvl) {
        return isset($nvl->$field->value) ? trim((string) $nvl->$field->value) : '';
    };

    $name = $val('name');
    if ($name === '') {
        return null;
    }

    $start = $val('start_date');
    $end = $val('end_date');
    $startTs = $start !== '' ? strtotime($start) : null;
    $endTs = $end !== '' ? strtotime($end) : null;

    // "Ya pasado" se decide por la fecha de FIN (un campamento sigue vigente
    // mientras dura); sin fecha de fin manda la de inicio.
    $refTs = $endTs ?: $startTs;
    $isPast = ($refTs !== null && $refTs < strtotime('today'));

    // Días que dura (inclusivo): 1-10 de julio son 10 días, no 9.
    $days = null;
    if ($startTs && $endTs && $endTs >= $startTs) {
        $days = (int) round(($endTs - $startTs) / 86400) + 1;
    }

    $optional = array();
    foreach (sticpa_event_optional_fields() as $field => $meta) {
        $raw = $val($field);
        if ($raw === '') {
            continue;
        }
        $text = $raw;
        if ($meta['format'] === 'date') {
            $text = formatValue($raw, 'date');
        } elseif ($meta['format'] === 'currency') {
            $text = formatValue($raw, 'currency');
        }
        if ((string) $text === '') {
            continue;
        }
        $optional[$field] = array('label' => $meta['label'], 'icon' => $meta['icon'], 'text' => $text);
    }

    return array(
        'id' => $val('id'),
        'name' => $name,
        'description' => $val('description'),
        'status' => $val('status'),
        'type' => $val('type'),
        'start_ts' => $startTs,
        'end_ts' => $endTs,
        'is_past' => $isPast,
        'days' => $days,
        'optional' => $optional,
    );
}

/**
 * Rango de fechas en lenguaje natural. Un evento de un día no debe leerse
 * "01-07-2026 – 01-07-2026", y uno dentro del mismo mes no repite el mes.
 */
function sticpa_event_date_line($startTs, $endTs)
{
    if (!$startTs) {
        return '';
    }
    $long = function ($ts) {
        return date_i18n('j \d\e F \d\e Y', $ts);
    };
    if (!$endTs || date('Y-m-d', $endTs) === date('Y-m-d', $startTs)) {
        return $long($startTs);
    }
    // Mismo mes y año: "del 1 al 10 de julio de 2026".
    if (date('Y-m', $startTs) === date('Y-m', $endTs)) {
        return sprintf(
            /* translators: 1: día inicio, 2: "10 de julio de 2026" */
            __('del %1$s al %2$s', 'sticpa'),
            date_i18n('j', $startTs),
            $long($endTs)
        );
    }
    return sprintf(__('del %1$s al %2$s', 'sticpa'), $long($startTs), $long($endTs));
}

/** Chip de estado del evento (usa el estado del CRM ya traducido). */
function sticpa_event_status_chip($event, $statusLabel = '')
{
    if ($event['is_past']) {
        return "<span class='stic-ev-chip stic-ev-chip--past'>" . esc_html__('Ya celebrado', 'sticpa') . "</span>";
    }
    $label = $statusLabel !== '' ? $statusLabel : $event['status'];
    if ($label === '') {
        return '';
    }
    return "<span class='stic-ev-chip'>" . esc_html($label) . "</span>";
}

/** Cápsula de fecha (día grande + mes) a la izquierda de la tarjeta. */
function sticpa_event_date_badge($event)
{
    if (!$event['start_ts']) {
        return "<span class='stic-ev-badge stic-ev-badge--empty' aria-hidden='true'>" . sticpa_event_icon('calendar') . "</span>";
    }
    return "<span class='stic-ev-badge" . ($event['is_past'] ? ' is-past' : '') . "' aria-hidden='true'>"
        . "<span class='stic-ev-badge-day'>" . esc_html(date_i18n('j', $event['start_ts'])) . "</span>"
        . "<span class='stic-ev-badge-mon'>" . esc_html(date_i18n('M', $event['start_ts'])) . "</span>"
        . "</span>";
}

/**
 * LISTADO DE EVENTOS como tarjetas.
 *
 * @param array $events    Registros del CRM (objetos con ->name_value_list).
 * @param array $statusMap Mapa valor→etiqueta del enum `status` (del CRM).
 */
function sticpa_events_list_html($events, $statusMap = array())
{
    $models = array();
    foreach ((array) $events as $row) {
        $nvl = $row->name_value_list ?? null;
        if (!$nvl) {
            continue;
        }
        $model = sticpa_event_view_model($nvl);
        if ($model) {
            $models[] = $model;
        }
    }

    if (empty($models)) {
        return "
        <div class='stic-empty-state'>
            <span class='stic-empty-ico'>" . sticpa_event_icon('calendar') . "</span>
            <p class='stic-empty-title'>" . esc_html__('No hay eventos abiertos ahora mismo', 'sticpa') . "</p>
            <p class='stic-empty-sub'>" . esc_html__('Cuando se abra la inscripción de una actividad, aparecerá aquí. Los eventos en los que ya estás inscrito están en “Inscripciones”.', 'sticpa') . "</p>
        </div>";
    }

    // Próximos primero (por fecha) y los ya celebrados al final: la pantalla
    // sirve para APUNTARSE, así que lo accionable va arriba.
    usort($models, function ($a, $b) {
        if ($a['is_past'] !== $b['is_past']) {
            return $a['is_past'] ? 1 : -1;
        }
        // Próximos: lo que antes ocurre, arriba. Ya celebrados: al revés, lo más
        // reciente primero (de un evento de hace tres años ya no te acuerdas).
        $cmp = ($a['start_ts'] ?? PHP_INT_MAX) <=> ($b['start_ts'] ?? PHP_INT_MAX);
        return $a['is_past'] ? -$cmp : $cmp;
    });

    $html = "<div class='stic-ev-list'>";
    foreach ($models as $event) {
        $detailUrl = '?internalpage=single_stic_events&action=detail&id=' . rawurlencode($event['id']);
        $signUpUrl = '?internalpage=single_stic_registrations&action=create&from=stic_events&id=' . rawurlencode($event['id']);
        $dateLine = sticpa_event_date_line($event['start_ts'], $event['end_ts']);
        $statusLabel = $statusMap[$event['status']] ?? '';

        $html .= "<article class='stic-ev-card" . ($event['is_past'] ? ' is-past' : '') . "'>";
        $html .= "<a class='stic-ev-main' href='" . esc_url($detailUrl) . "'>";
        $html .= sticpa_event_date_badge($event);
        $html .= "<span class='stic-ev-body'>";
        $html .= "<span class='stic-ev-name'>" . esc_html($event['name']) . "</span>";
        if ($dateLine !== '') {
            $html .= "<span class='stic-ev-when'>" . sticpa_event_icon('calendar') . "<span>" . esc_html($dateLine) . "</span></span>";
        }
        // Una sola línea de datos extra (lugar si lo hay): en la tarjeta manda
        // el "cuándo"; el resto se ve en el detalle.
        $place = $event['optional']['location']['text'] ?? ($event['optional']['city']['text'] ?? '');
        if ($place !== '') {
            $html .= "<span class='stic-ev-where'>" . sticpa_event_icon('pin') . "<span>" . esc_html($place) . "</span></span>";
        }
        $html .= "<span class='stic-ev-chips'>" . sticpa_event_status_chip($event, $statusLabel) . "</span>";
        $html .= "</span>";
        $html .= "</a>";

        $html .= "<div class='stic-ev-actions'>";
        $html .= "<a class='stic-ev-btn stic-ev-btn--ghost' href='" . esc_url($detailUrl) . "'>" . esc_html__('Ver detalle', 'sticpa') . "</a>";
        if (!$event['is_past']) {
            $html .= "<a class='stic-ev-btn stic-ev-btn--primary' href='" . esc_url($signUpUrl) . "'>" . esc_html__('Inscribirme', 'sticpa') . "</a>";
        }
        $html .= "</div>";
        $html .= "</article>";
    }
    $html .= "</div>";

    return $html;
}

/**
 * FICHA DE DETALLE del evento. Antes esta pantalla era el formulario genérico
 * con todos los campos deshabilitados (cajas grises que no se pueden tocar):
 * parecía un error, no una ficha.
 *
 * @param array  $event       Modelo de sticpa_event_view_model().
 * @param string $statusLabel Etiqueta traducida del estado.
 * @param bool   $canSignUp   Si se ofrece el botón de inscripción.
 */
function sticpa_event_detail_html($event, $statusLabel = '', $canSignUp = true)
{
    $dateLine = sticpa_event_date_line($event['start_ts'], $event['end_ts']);
    $signUpUrl = '?internalpage=single_stic_registrations&action=create&from=stic_events&id=' . rawurlencode($event['id']);

    $html = "<div class='stic-ev-detail'>";

    // --- Cabecera con la identidad del evento ---
    $html .= "<header class='stic-ev-hero'>";
    $html .= "<a class='stic-ev-back' href='?internalpage=list_stic_events'>" . sticpa_event_icon('back') . "<span>" . esc_html__('Eventos', 'sticpa') . "</span></a>";
    $html .= "<h3 class='stic-ev-hero-title'>" . esc_html($event['name']) . "</h3>";
    $html .= "<div class='stic-ev-hero-meta'>";
    if ($dateLine !== '') {
        $html .= "<span class='stic-ev-hero-when'>" . sticpa_event_icon('calendar') . "<span>" . esc_html($dateLine) . "</span></span>";
    }
    $html .= sticpa_event_status_chip($event, $statusLabel);
    $html .= "</div>";
    $html .= "</header>";

    // --- Datos clave (solo los que el CRM tenga rellenos) ---
    $facts = array();
    if ($event['days'] !== null && $event['days'] > 1) {
        $facts[] = array(
            'icon' => 'clock',
            'label' => __('Duración', 'sticpa'),
            /* translators: %d = número de días */
            'text' => sprintf(_n('%d día', '%d días', $event['days'], 'sticpa'), $event['days']),
        );
    }
    foreach ($event['optional'] as $item) {
        $facts[] = $item;
    }
    if (!empty($facts)) {
        $html .= "<ul class='stic-ev-facts'>";
        foreach ($facts as $fact) {
            $html .= "<li class='stic-ev-fact'>"
                . "<span class='stic-ev-fact-ico'>" . sticpa_event_icon($fact['icon']) . "</span>"
                . "<span class='stic-ev-fact-body'>"
                . "<span class='stic-ev-fact-label'>" . esc_html($fact['label']) . "</span>"
                . "<span class='stic-ev-fact-text'>" . esc_html($fact['text']) . "</span>"
                . "</span></li>";
        }
        $html .= "</ul>";
    }

    // --- Descripción ---
    if ($event['description'] !== '') {
        $html .= "<section class='stic-ev-desc'>";
        $html .= "<h4>" . esc_html__('Sobre esta actividad', 'sticpa') . "</h4>";
        // nl2br + esc_html: el CRM guarda texto plano con saltos de línea.
        $html .= "<div class='stic-ev-desc-body'>" . nl2br(esc_html($event['description'])) . "</div>";
        $html .= "</section>";
    }

    // --- Llamada a la acción ---
    $html .= "<div class='stic-ev-cta-row'>";
    if ($event['is_past']) {
        $html .= "<p class='stic-ev-cta-note'>" . esc_html__('Esta actividad ya se ha celebrado.', 'sticpa') . "</p>";
    } elseif ($canSignUp) {
        $html .= "<a class='stic-ev-btn stic-ev-btn--primary stic-ev-btn--lg' href='" . esc_url($signUpUrl) . "'>"
            . esc_html__('Inscribirme en esta actividad', 'sticpa') . sticpa_event_icon('go') . "</a>";
    } else {
        $html .= "<p class='stic-ev-cta-note'>" . esc_html__('Ya tienes una inscripción para esta actividad. Puedes verla en “Inscripciones”.', 'sticpa') . "</p>";
    }
    $html .= "</div>";

    $html .= "</div>";
    return $html;
}
