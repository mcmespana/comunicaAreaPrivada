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

/**
 * Filtro SQL de la ventana temporal de eventos "vivos" (por defecto -14 … +12
 * meses). FUENTE ÚNICA: la comparten el calendario y el listado de Eventos.
 *
 * RENDIMIENTO: "Eventos" pedía al CRM TODOS los eventos históricos sin filtro
 * ni límite, así que el tiempo de respuesta, el JSON y el HTML crecían con la
 * antigüedad de la base de datos y no con lo que hay que mostrar.
 *
 * POR QUÉ 14 MESES HACIA ATRÁS (y no 3, que es lo que usaba el calendario):
 * las actividades del MCM son ANUALES y se repiten, así que la del año pasado
 * sigue siendo la referencia útil ("¿cuándo fue el campamento?", "esto ya lo
 * hicimos"). 14 = un ciclo anual completo + dos meses de margen, para que a
 * final de curso siga estando visible la edición anterior. Con 3 meses
 * desaparecía justo lo que la gente busca.
 *
 * Los eventos anteriores a la ventana tampoco se pierden del todo: las
 * inscripciones del usuario siguen listándose enteras en "Inscripciones". Y la
 * ventana se ajusta sin tocar código con los filtros
 * sticpa_events_window_months_back / _ahead.
 */
function sticpa_events_window_filter()
{
    $back  = (int) apply_filters('sticpa_events_window_months_back', 14);
    $ahead = (int) apply_filters('sticpa_events_window_months_ahead', 12);
    return "(stic_events.start_date BETWEEN DATE_ADD(curdate(), INTERVAL -{$back} MONTH) AND DATE_ADD(curdate(), INTERVAL {$ahead} MONTH))";
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
 * ALIAS de compatibilidad. El formato de evento se generalizó a "ficha de
 * registro" (inc/stic-record-view.php) para que lo compartan los ocho módulos
 * del área. Estos nombres se quedan porque los usan las plantillas y los tests
 * y no aportan nada renombrarlos; delegan y no duplican una sola regla.
 */
function sticpa_event_icon($name)
{
    return sticpa_record_icon($name);
}

function sticpa_event_date_line($startTs, $endTs)
{
    return sticpa_record_date_line($startTs, $endTs);
}

/** Chip de estado del evento (el estado del CRM, ya traducido). */
function sticpa_event_status_chip($event, $statusLabel = '')
{
    if (!empty($event['is_past'])) {
        return sticpa_record_chip(__('Ya celebrado', 'sticpa'), 'past');
    }
    return sticpa_record_chip($statusLabel !== '' ? $statusLabel : $event['status'], '');
}

/** Cápsula de fecha (día grande + mes) a la izquierda de la tarjeta. */
function sticpa_event_date_badge($event)
{
    return sticpa_record_date_badge($event['start_ts'] ?? null, !empty($event['is_past']), 'calendar');
}

/**
 * LISTADO DE EVENTOS como tarjetas.
 *
 * Dos acciones y bien separadas: "Ver detalle" (secundaria) e "Inscribirme"
 * (la principal). Antes solo había "Inscribirse", sin manera de saber a qué te
 * estabas apuntando.
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
        return sticpa_record_empty_html(
            'calendar',
            __('No hay eventos abiertos ahora mismo', 'sticpa'),
            __('Cuando se abra la inscripción de una actividad, aparecerá aquí. Los eventos en los que ya estás inscrito están en “Inscripciones”.', 'sticpa'),
            array('label' => __('Ver mis inscripciones', 'sticpa'), 'url' => '?internalpage=list_stic_registrations')
        );
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

    $cards = array();
    foreach ($models as $event) {
        $detailUrl = '?internalpage=single_stic_events&action=detail&id=' . rawurlencode($event['id']);
        $signUpUrl = '?internalpage=single_stic_registrations&action=create&from=stic_events&id=' . rawurlencode($event['id']);

        $lines = array();
        $dateLine = sticpa_record_date_line($event['start_ts'], $event['end_ts']);
        if ($dateLine !== '') {
            $lines[] = array('icon' => 'calendar', 'text' => $dateLine);
        }
        // Una sola línea extra (el lugar): en la tarjeta manda el "cuándo";
        // el resto se ve en la ficha.
        $place = $event['optional']['location']['text'] ?? ($event['optional']['city']['text'] ?? '');
        if ($place !== '') {
            $lines[] = array('icon' => 'pin', 'text' => $place);
        }

        $chips = array();
        if ($event['is_past']) {
            $chips[] = array('label' => __('Ya celebrado', 'sticpa'), 'tone' => 'past');
        } elseif (!empty($statusMap[$event['status']])) {
            $chips[] = array('label' => $statusMap[$event['status']], 'tone' => '');
        }

        $actions = array(array('label' => __('Ver detalle', 'sticpa'), 'url' => $detailUrl));
        if (!$event['is_past']) {
            $actions[] = array('label' => __('Inscribirme', 'sticpa'), 'url' => $signUpUrl, 'primary' => true);
        }

        $cards[] = array(
            'url'     => $detailUrl,
            'ts'      => $event['start_ts'],
            'name'    => $event['name'],
            'lines'   => $lines,
            'chips'   => $chips,
            'is_past' => $event['is_past'],
            'actions' => $actions,
        );
    }

    return sticpa_record_list_html($cards);
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
    $dateLine = sticpa_record_date_line($event['start_ts'], $event['end_ts']);
    $signUpUrl = '?internalpage=single_stic_registrations&action=create&from=stic_events&id=' . rawurlencode($event['id']);

    $chips = array();
    if ($event['is_past']) {
        $chips[] = array('label' => __('Ya celebrado', 'sticpa'), 'tone' => 'past');
    } elseif ($statusLabel !== '' || $event['status'] !== '') {
        $chips[] = array('label' => $statusLabel !== '' ? $statusLabel : $event['status'], 'tone' => '');
    }

    $facts = array();
    if ($event['days'] !== null && $event['days'] > 1) {
        $facts[] = array(
            'icon'  => 'clock',
            'label' => __('Duración', 'sticpa'),
            /* translators: %d = número de días */
            'text'  => sprintf(_n('%d día', '%d días', $event['days'], 'sticpa'), $event['days']),
        );
    }
    foreach ($event['optional'] as $item) {
        $facts[] = $item;
    }

    $actions = array();
    $ctaNote = '';
    if ($event['is_past']) {
        $ctaNote = __('Esta actividad ya se ha celebrado.', 'sticpa');
    } elseif ($canSignUp) {
        $actions[] = array(
            'label'   => __('Inscribirme en esta actividad', 'sticpa'),
            'url'     => $signUpUrl,
            'primary' => true,
            'icon'    => 'go',
        );
    } else {
        $actions[] = array('label' => __('Ver mi inscripción', 'sticpa'), 'url' => '?internalpage=list_stic_registrations');
        $ctaNote = __('Ya tienes una inscripción para esta actividad.', 'sticpa');
    }

    return sticpa_record_detail_html(array(
        'back'     => array('url' => '?internalpage=list_stic_events', 'label' => __('Eventos', 'sticpa')),
        'title'    => $event['name'],
        'meta'     => array(array('icon' => 'calendar', 'text' => $dateLine)),
        'chips'    => $chips,
        'facts'    => $facts,
        'sections' => array(array('title' => __('Sobre esta actividad', 'sticpa'), 'body' => $event['description'])),
        'actions'  => $actions,
        'cta_note' => $ctaNote,
    ));
}
