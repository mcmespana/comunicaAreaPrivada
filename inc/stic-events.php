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
        //
        // ⚠️ ESTOS NOMBRES SON LOS DEL CRM DE VERDAD, comprobados por MCP el
        // 09/09/2026. Antes esta lista pedía `capacity`, `start_time` y
        // `registration_end`, que NO EXISTEN: existen con otro nombre. O sea
        // que el aforo, el horario y la ventana de inscripción estaban en el
        // CRM y no se pintaban, y la lista invitaba a crear campos duplicados.
        'location'          => array('label' => __('Lugar', 'sticpa'),        'icon' => 'pin',    'format' => 'text'),
        'city'              => array('label' => __('Población', 'sticpa'),    'icon' => 'pin',    'format' => 'text'),
        'address'           => array('label' => __('Dirección', 'sticpa'),    'icon' => 'pin',    'format' => 'text'),
        // El horario es TEXTO LIBRE en el CRM («De 17 a 19 h»), no una hora:
        // se pinta tal cual, que es lo que quien lo escribió quería decir.
        'timetable'         => array('label' => __('Horario', 'sticpa'),      'icon' => 'clock',  'format' => 'text'),
        // `skip_zero`: un 0 aquí NO es un dato, es el valor por defecto de
        // SuiteCRM. Los cinco eventos del CRM tienen `max_attendees = 0` y
        // `price = 0.00`, así que sin esto la ficha decía «Plazas 0» —que se
        // lee como "no hay plazas", justo lo contrario de "sin límite"— y
        // «Precio 0,00 €» en TODAS las actividades. Y con el precio no se
        // arregla poniendo «Gratis»: nadie ha dicho que sea gratis, solo que
        // el campo está sin rellenar. Ante la duda, no se dice nada.
        'max_attendees'     => array('label' => __('Plazas', 'sticpa'),       'icon' => 'users',  'format' => 'text',     'skip_zero' => true),
        'price'             => array('label' => __('Precio', 'sticpa'),       'icon' => 'euro',   'format' => 'currency', 'skip_zero' => true),
    ));
}

/**
 * Los dos campos de la VENTANA DE INSCRIPCIÓN. Van aparte de los opcionales
 * porque no se pintan como «etiqueta: valor»: los dos juntos son UN dato
 * («hasta el 25 de octubre», «se abre el 1 de octubre») y además deciden si se
 * ofrece el botón. Ver sticpa_event_registration_window().
 */
function sticpa_event_registration_fields()
{
    return array(
        (string) apply_filters('sticpa_event_reg_start_field', 'ajmcm_start_inscripcion_c'),
        (string) apply_filters('sticpa_event_reg_end_field', 'ajmcm_end_inscripcion_c'),
    );
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
    // `assigned_user_id` es BASE y no opcional: es la delegación del evento, y
    // de ella depende quién puede apuntarse (inc/stic-event-audience.php).
    $base = array('id', 'name', 'status', 'type', 'start_date', 'end_date', 'description', 'assigned_user_id');
    $wanted = array_keys(sticpa_event_optional_fields());
    // Los campos de AUDIENCIA se piden igual que los opcionales —solo si
    // existen— para que el filtro se pueda desplegar antes de crearlos en el
    // CRM: un campo que no está no restringe nada y no rompe la llamada.
    if (function_exists('sticpa_event_audience_fields')) {
        $wanted = array_merge($wanted, sticpa_event_audience_fields());
    }
    $wanted = array_merge($wanted, sticpa_event_registration_fields());
    $wanted = array_values(array_unique($wanted));
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
        // Un 0 en un campo marcado `skip_zero` es el valor por defecto de
        // SuiteCRM, no una respuesta: no se enseña. Ver la nota de
        // sticpa_event_optional_fields().
        if (!empty($meta['skip_zero']) && (float) $raw == 0) {
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
        // La ventana de inscripción, ya resuelta contra el día de hoy.
        'registro' => sticpa_event_registration_window($nvl),
        // A quién va dirigido (delegación, perfiles, cursos). Va en el modelo
        // para que listado y ficha decidan con lo mismo, igual que las fechas.
        'audiencia' => function_exists('sticpa_event_audience_from_nvl')
            ? sticpa_event_audience_from_nvl($nvl)
            : array('delegacion' => $val('assigned_user_id'), 'ambito' => '', 'perfiles' => array(), 'cursos' => array()),
    );
}

/**
 * LA VENTANA DE INSCRIPCIÓN, resuelta contra el día de hoy.
 *
 * `ajmcm_start_inscripcion_c` y `ajmcm_end_inscripcion_c` existen en el CRM
 * desde antes que esta pantalla, y no se miraban. Es justo lo que EVENTOS.md
 * decía que era «la forma limpia» de cerrar una inscripción sin que nadie
 * tenga que acordarse de cambiar el estado a mano.
 *
 * ESTADOS, y el que importa es el cuarto:
 *   'sin_datos' → los dos campos vacíos. **La inscripción está abierta.** Es la
 *                 regla de seguridad de la casa: hoy solo 1 de los 5 eventos
 *                 del CRM tiene estas fechas, así que tratar el vacío como
 *                 «cerrada» dejaría el área sin poder apuntarse a nada.
 *   'antes'     → aún no se ha abierto.
 *   'abierta'   → dentro de plazo.
 *   'cerrada'   → el plazo terminó.
 *
 * El día de FIN cuenta entero (hasta las 23:59): un plazo «hasta el 25» que se
 * cierra a las 00:00 del 25 le roba un día a la gente.
 *
 * @param object $nvl name_value_list del evento.
 * @return array estado, start_ts, end_ts, abierta (bool)
 */
function sticpa_event_registration_window($nvl)
{
    $fields = sticpa_event_registration_fields();
    $get = function ($field) use ($nvl) {
        return isset($nvl->$field->value) ? trim((string) $nvl->$field->value) : '';
    };
    $startRaw = $get($fields[0]);
    $endRaw = $get($fields[1]);

    $startTs = $startRaw !== '' ? strtotime($startRaw . ' 00:00:00') : null;
    $endTs = $endRaw !== '' ? strtotime($endRaw . ' 23:59:59') : null;
    if ($startTs === false) {
        $startTs = null;
    }
    if ($endTs === false) {
        $endTs = null;
    }

    $now = time();
    if ($startTs === null && $endTs === null) {
        $estado = 'sin_datos';
    } elseif ($startTs !== null && $now < $startTs) {
        $estado = 'antes';
    } elseif ($endTs !== null && $now > $endTs) {
        $estado = 'cerrada';
    } else {
        $estado = 'abierta';
    }

    return apply_filters('sticpa_event_registration_window', array(
        'estado'   => $estado,
        'start_ts' => $startTs,
        'end_ts'   => $endTs,
        // 'sin_datos' cuenta como abierta: ver la regla de seguridad de arriba.
        'abierta'  => ($estado === 'abierta' || $estado === 'sin_datos'),
    ), $nvl);
}

/**
 * El dato clave de la inscripción para la ficha ("Hasta el 25 de octubre").
 * Devuelve null cuando no hay nada útil que decir: un plazo que empezó y no
 * acaba nunca no es información, es ruido.
 */
function sticpa_event_registration_fact($registro)
{
    $fecha = function ($ts) {
        return $ts ? sticpa_record_date_line($ts, null) : '';
    };
    switch ($registro['estado'] ?? '') {
        case 'antes':
            $texto = $fecha($registro['start_ts']);
            if ($texto === '') {
                return null;
            }
            /* translators: %s = fecha */
            return array('icon' => 'clock', 'label' => __('Inscripción', 'sticpa'),
                'text' => sprintf(__('Se abre el %s', 'sticpa'), $texto));
        case 'abierta':
            $texto = $fecha($registro['end_ts']);
            if ($texto === '') {
                return null;   // abierta y sin fecha de cierre: no hay nada que contar
            }
            /* translators: %s = fecha */
            return array('icon' => 'clock', 'label' => __('Inscripción', 'sticpa'),
                'text' => sprintf(__('Hasta el %s', 'sticpa'), $texto));
        case 'cerrada':
            $texto = $fecha($registro['end_ts']);
            if ($texto === '') {
                return null;
            }
            /* translators: %s = fecha */
            return array('icon' => 'clock', 'label' => __('Inscripción', 'sticpa'),
                'text' => sprintf(__('Cerrada el %s', 'sticpa'), $texto));
    }
    return null;
}

/**
 * El CHIP del estado de la inscripción, cuando el plazo contradice al CRM.
 *
 * Y contradice a menudo: `status` está en `registration` («Inscripción
 * abierta») en los cinco eventos del CRM, lo pongan o no al día. Sin esto, una
 * tarjeta decía «INSCRIPCIÓN ABIERTA» y justo debajo «Cerrada el 6 de
 * septiembre», que es la clase de pantalla que hace que nadie se crea nada de
 * lo que pone. Cuando hay fechas, mandan las fechas.
 *
 * Devuelve null si el plazo no dice nada (y entonces manda el `status` del CRM).
 */
function sticpa_event_registration_chip($registro)
{
    switch ($registro['estado'] ?? '') {
        case 'cerrada':
            return array('label' => __('Inscripción cerrada', 'sticpa'), 'tone' => 'past');
        case 'antes':
            return array('label' => __('Inscripción no abierta', 'sticpa'), 'tone' => 'info');
    }
    return null;
}

/**
 * El motivo, en cristiano, por el que no se puede apuntar AHORA por fechas.
 * Cadena vacía si sí se puede.
 */
function sticpa_event_registration_note($registro)
{
    $fecha = function ($ts) {
        return $ts ? sticpa_record_date_line($ts, null) : '';
    };
    if (($registro['estado'] ?? '') === 'antes') {
        $texto = $fecha($registro['start_ts']);
        return $texto !== ''
            /* translators: %s = fecha */
            ? sprintf(__('La inscripción se abre el %s.', 'sticpa'), $texto)
            : __('La inscripción todavía no está abierta.', 'sticpa');
    }
    if (($registro['estado'] ?? '') === 'cerrada') {
        $texto = $fecha($registro['end_ts']);
        return $texto !== ''
            /* translators: %s = fecha */
            ? sprintf(__('El plazo de inscripción terminó el %s.', 'sticpa'), $texto)
            : __('El plazo de inscripción ya ha terminado.', 'sticpa');
    }
    return '';
}

/**
 * ¿POR QUÉ no puede esta persona apuntarse a este evento? Fuente única para
 * las cuatro pantallas que se lo preguntan (ficha, formulario, guardado y,
 * con el modelo ya cargado, el listado).
 *
 * Junta los dos motivos que existen y los ordena: primero la AUDIENCIA y
 * después las FECHAS. El orden no es un detalle — a quien es de otra
 * delegación, decirle «el plazo terminó» le hace pensar que llegó tarde a algo
 * que nunca fue suyo.
 *
 * @return array bloqueado (bool), titulo, texto
 */
function sticpa_event_signup_block($objSCP, $eventId, $nvl = null)
{
    $libre = array('bloqueado' => false, 'titulo' => '', 'texto' => '');
    $eventId = trim((string) $eventId);
    if ($eventId === '') {
        return $libre;
    }
    if ($nvl === null) {
        if ($objSCP === null) {
            return $libre;
        }
        $detail = $objSCP->getRecordDetail($eventId, 'stic_Events', sticpa_event_fields_to_request($objSCP));
        $nvl = $detail->entry_list[0]->name_value_list ?? null;
        if (!$nvl) {
            // El CRM no contesta sobre el evento: no se bloquea por eso.
            return $libre;
        }
    }

    if (function_exists('sticpa_event_audience_check')) {
        $verdict = sticpa_event_audience_check($objSCP, $eventId, $nvl);
        if (empty($verdict['ok'])) {
            return array(
                'bloqueado' => true,
                'titulo'    => __('Esta actividad no es para ti', 'sticpa'),
                'texto'     => sticpa_event_audience_verdict_notice($objSCP, $verdict),
            );
        }
    }

    $nota = sticpa_event_registration_note(sticpa_event_registration_window($nvl));
    if ($nota !== '') {
        return array(
            'bloqueado' => true,
            'titulo'    => __('La inscripción no está abierta', 'sticpa'),
            'texto'     => $nota,
        );
    }
    return $libre;
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

        $regChip = $event['is_past'] ? null : sticpa_event_registration_chip($event['registro']);

        // La ventana de inscripción, cuando dice algo, va en la tarjeta: es lo
        // accionable («te quedan días») y es la razón de que el botón esté o
        // no. Sin ella, un evento sin «Inscribirme» parece un error.
        //
        // Con el plazo YA CERRADO no se repite la fecha aquí: el chip ya dice
        // «Inscripción cerrada» y en una lista la fecha exacta de algo que se
        // pasó no sirve para nada. En la ficha sí se enseña, que es donde uno
        // va a mirar cuándo se le pasó.
        $regFact = sticpa_event_registration_fact($event['registro']);
        if (!$event['is_past'] && $regFact !== null && ($event['registro']['estado'] ?? '') !== 'cerrada') {
            $lines[] = array('icon' => $regFact['icon'], 'text' => $regFact['text']);
        }

        $chips = array();
        if ($event['is_past']) {
            $chips[] = array('label' => __('Ya celebrado', 'sticpa'), 'tone' => 'past');
        } elseif ($regChip !== null) {
            // Las fechas mandan sobre el `status` del CRM. Ver la nota de
            // sticpa_event_registration_chip().
            $chips[] = $regChip;
        } elseif (!empty($statusMap[$event['status']])) {
            $chips[] = array('label' => $statusMap[$event['status']], 'tone' => '');
        }

        $actions = array(array('label' => __('Ver detalle', 'sticpa'), 'url' => $detailUrl));
        // Fuera del plazo no se ofrece «Inscribirme»: el botón que lleva a un
        // formulario que va a rechazarte es peor que no tener botón.
        if (!$event['is_past'] && !empty($event['registro']['abierta'])) {
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
 * @param string $blockNote Motivo por el que esta actividad no admite tu
 *                          inscripción: audiencia (otra delegación, otro
 *                          perfil, otro curso) o fechas. Si viene, NO se
 *                          ofrece el botón y se explica por qué: a la ficha se
 *                          llega por enlaces que se pasan por WhatsApp, y un
 *                          «no puedes» sin motivo es la peor pantalla.
 */
function sticpa_event_detail_html($event, $statusLabel = '', $canSignUp = true, $blockNote = '')
{
    $dateLine = sticpa_record_date_line($event['start_ts'], $event['end_ts']);
    $signUpUrl = '?internalpage=single_stic_registrations&action=create&from=stic_events&id=' . rawurlencode($event['id']);

    $regChip = $event['is_past'] ? null : sticpa_event_registration_chip($event['registro']);

    $chips = array();
    if ($event['is_past']) {
        $chips[] = array('label' => __('Ya celebrado', 'sticpa'), 'tone' => 'past');
    } elseif ($regChip !== null) {
        // Las fechas del plazo mandan sobre el `status` del CRM: ver la nota de
        // sticpa_event_registration_chip().
        $chips[] = $regChip;
    } elseif ($statusLabel !== '' || $event['status'] !== '') {
        $chips[] = array('label' => $statusLabel !== '' ? $statusLabel : $event['status'], 'tone' => '');
    }

    $facts = array();
    // LA DURACIÓN SOLO SE CUENTA SI ES UNA DURACIÓN. «Sesiones semanales
    // 2026-2027» va de septiembre a junio, y la ficha decía «Duración: 231
    // días»: es cierto y no significa nada — eso no es una actividad de 231
    // días, es un curso entero. Por encima de un mes el número es ruido, y se
    // deja fuera; las fechas de inicio y fin ya están arriba, en la cabecera.
    $maxDias = (int) apply_filters('sticpa_event_max_dias_duracion', 31);
    if ($event['days'] !== null && $event['days'] > 1 && $event['days'] <= $maxDias) {
        $facts[] = array(
            'icon'  => 'clock',
            'label' => __('Duración', 'sticpa'),
            /* translators: %d = número de días */
            'text'  => sprintf(_n('%d día', '%d días', $event['days'], 'sticpa'), $event['days']),
        );
    }
    // La ventana de inscripción va ARRIBA de los datos clave: es lo único de
    // esta lista que caduca, y es lo que explica que haya botón o no.
    $regFact = sticpa_event_registration_fact($event['registro']);
    if (!$event['is_past'] && $regFact !== null) {
        $facts[] = $regFact;
    }
    foreach ($event['optional'] as $item) {
        $facts[] = $item;
    }

    $actions = array();
    $ctaNote = '';
    $blockNote = trim((string) $blockNote);
    // El ORDEN importa. «Ya estás inscrito» va antes que la audiencia porque
    // es un hecho sobre esta persona: a quien ya tiene su plaza no se le dice
    // «esta actividad no es para ti» aunque hoy no cumpla el filtro (le han
    // cambiado el curso, se ha ido de la delegación…). Primero lo que ES, y
    // solo después lo que puede hacer.
    if ($event['is_past']) {
        $ctaNote = __('Esta actividad ya se ha celebrado.', 'sticpa');
    } elseif (!$canSignUp) {
        $actions[] = array('label' => __('Ver mi inscripción', 'sticpa'), 'url' => '?internalpage=list_stic_registrations');
        $ctaNote = __('Ya tienes una inscripción para esta actividad.', 'sticpa');
    } elseif ($blockNote !== '') {
        // No es para ti, o no toca ahora: no se ofrece apuntarse, y se dice
        // por qué. El dato de la fecha ya está arriba, en los datos clave.
        $ctaNote = $blockNote;
        $actions[] = array('label' => __('Ver otras actividades', 'sticpa'), 'url' => '?internalpage=list_stic_events');
    } else {
        $actions[] = array(
            'label'   => __('Inscribirme en esta actividad', 'sticpa'),
            'url'     => $signUpUrl,
            'primary' => true,
            'icon'    => 'go',
        );
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
