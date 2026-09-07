<?php
/**
 * INSCRIPCIONES — el listado y la ficha.
 * ----------------------------------------------------------------------------
 * Es la pantalla que más miran las familias, y era de las peores. Se pintaba
 * con el renderizador genérico de listados y tenía cuatro problemas de fondo:
 *
 *   1. La cápsula de fecha llevaba `registration_date`: el día en que te
 *      apuntaste. A una familia le da igual haberse apuntado el 14 de marzo;
 *      lo que necesita saber es que el campamento es del 1 al 10 de julio.
 *      Se enseñaba el dato administrativo y se escondía el útil.
 *   2. La acción principal de cada fila era **Editar**. La acción principal de
 *      una inscripción no es cambiarla: es verla, y si acaso pagarla.
 *   3. El título era "Registrations", en inglés y sin traducir.
 *   4. El detalle era el formulario genérico con todo deshabilitado.
 *
 * QUÉ SE PINTA Y DE DÓNDE SALE
 * Las fechas del evento NO están en la inscripción: están en stic_Events. Y
 * pedirlas por inscripción sería un 1+N, justo lo que persigue el plan 011. La
 * solución aquí es no pagar NADA: se aprovecha el transient del calendario
 * (`sticpa_gather_calendar_data`, 300s, que la home ya deja caliente) SOLO SI
 * está caliente. Si no lo está, la tarjeta enseña la fecha de inscripción y ya
 * está: se degrada, pero no añade ni un viaje al CRM. Es la misma doctrina que
 * el plan 029 aplicó al guard anti-duplicado.
 *
 * LOS CAMPOS son los que stic_Registrations tiene de verdad (consultados por
 * MCP). Los `ajmcm_*` son de nuestra adaptación: la clase y el curso escolar
 * del participante y los datos de los tutores. Todos se pintan SOLO si vienen
 * rellenos, así que una instancia que no los use no ve un hueco.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Campos que se piden al CRM para el LISTADO. Cortos a propósito: cada campo
 * de más viaja por cada inscripción.
 */
function sticpa_registration_list_fields()
{
    return array(
        'id',
        'name',
        'status',
        'registration_date',
        'stic_registrations_stic_events_name',
        // El id del evento, de verdad. `ajmcm_eventid_c` existe en el módulo
        // pero está VACÍO en los registros reales (comprobado por MCP): el que
        // vale es el campo `_ida` del enlace. Con él, "Ver la actividad"
        // funciona siempre y no hace falta casar eventos por su nombre.
        'stic_registrations_stic_eventsstic_events_ida',
        'ajmcm_clase_c',
        'ajmcm_curso_escolar_c',
    );
}

/**
 * Campos que se piden para la FICHA. Aquí sí interesa el detalle: es un solo
 * registro y la persona ha entrado a propósito a verlo.
 */
function sticpa_registration_detail_fields()
{
    return array_merge(sticpa_registration_list_fields(), array(
        'attendees',
        'attendance_percentage',
        'attended_hours',
        'participation_type',
        'special_needs',
        'special_needs_description',
        'ajmcm_registration_amount_c',
        'ajmcm_convivencia_c',
        'ajmcm_convivencia_fecha_c',
        'ajmcm_convivencia_precio_c',
        'ajmcm_tutor1_firstname_c',
        'ajmcm_tutor1_lastname_c',
        'ajmcm_tutor1_relationship_c',
        'ajmcm_tutor1_phone_c',
        'ajmcm_tutor1_email_c',
        'ajmcm_tutor2_firstname_c',
        'ajmcm_tutor2_lastname_c',
        'ajmcm_tutor2_relationship_c',
        'ajmcm_tutor2_phone_c',
        'ajmcm_tutor2_email_c',
    ));
}

/**
 * Mapa de datos del evento (por id y por nombre), SIN pagar una sola llamada.
 *
 * Sale del transient del calendario, que la home deja caliente con las fechas
 * de los eventos en los que estás inscrito. Si está frío, se devuelve un array
 * vacío y las tarjetas se apañan con lo que traen. Nunca se calienta desde
 * aquí: calentarlo cuesta el 1+N entero del calendario, y esta pantalla no
 * puede permitírselo.
 *
 * La clave es el NOMBRE normalizado porque es lo único que la inscripción trae
 * del evento (el campo relate `stic_registrations_stic_events_name`).
 */
function sticpa_registration_event_index()
{
    if (!function_exists('sticpa_calendar_cache_key') || !function_exists('get_transient')) {
        return array();
    }
    $cached = get_transient(sticpa_calendar_cache_key());
    if (!is_array($cached) || empty($cached['registered_events']) || !is_array($cached['registered_events'])) {
        return array();
    }
    $index = array();
    foreach ($cached['registered_events'] as $event) {
        $event = (array) $event;
        $name = trim((string) ($event['name'] ?? ''));
        $id   = trim((string) ($event['id'] ?? ''));
        $entry = array('id' => $id, 'name' => $name,
            'start' => (string) ($event['start'] ?? ''), 'end' => (string) ($event['end'] ?? ''));
        // Se indexa por las dos vías: por id, que es exacto, y por nombre, que
        // es el plan B si algún día el enlace no viniera.
        if ($id !== '') {
            $index['id:' . $id] = $entry;
        }
        if ($name !== '') {
            $index['name:' . sticpa_registration_name_key($name)] = $entry;
        }
    }
    return $index;
}

/**
 * Clave de comparación de un nombre de evento: sin mayúsculas, sin acentos y
 * sin espacios de más. El nombre viaja por dos caminos distintos (el campo
 * relate y el módulo de eventos) y basta un espacio doble para no casar.
 */
function sticpa_registration_name_key($name)
{
    $name = trim(mb_strtolower((string) $name, 'UTF-8'));
    $name = strtr($name, array(
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c',
    ));
    return preg_replace('/\s+/', ' ', $name);
}

/**
 * Normaliza una inscripción del CRM a lo que necesita la interfaz.
 *
 * @param object $nvl        name_value_list del registro.
 * @param array  $eventIndex Salida de sticpa_registration_event_index().
 * @return array|null null si es una fila sin nombre (basura).
 */
function sticpa_registration_view_model($nvl, $eventIndex = array())
{
    $val = function ($field) use ($nvl) {
        return isset($nvl->$field->value) ? trim((string) $nvl->$field->value) : '';
    };

    $eventName = $val('stic_registrations_stic_events_name');
    $ownName   = $val('name');
    // El nombre que se enseña es el del EVENTO: es a lo que te has apuntado.
    // El de la inscripción suele ser un código administrativo ("INS-000123").
    $title = $eventName !== '' ? $eventName : $ownName;
    if ($title === '') {
        return null;
    }

    $signedTs = $val('registration_date') !== '' ? strtotime($val('registration_date')) : null;

    // Fechas del evento, si el calendario está caliente. Por id primero.
    $eventId = $val('stic_registrations_stic_eventsstic_events_ida');
    $event = null;
    if ($eventId !== '' && isset($eventIndex['id:' . $eventId])) {
        $event = $eventIndex['id:' . $eventId];
    } elseif ($eventName !== '' && isset($eventIndex['name:' . sticpa_registration_name_key($eventName)])) {
        $event = $eventIndex['name:' . sticpa_registration_name_key($eventName)];
    }
    $startTs = ($event && $event['start'] !== '') ? strtotime($event['start']) : null;
    $endTs   = ($event && $event['end'] !== '') ? strtotime($event['end']) : null;

    // "Ya pasado" se decide por la fecha de FIN del evento (un campamento sigue
    // vigente mientras dura). Sin fechas de evento no se decide nada: una
    // inscripción no se apaga por ser antigua la fecha en que se hizo.
    $refTs = $endTs ?: $startTs;
    $isPast = ($refTs !== null && $refTs < strtotime('today'));

    return array(
        'id'         => $val('id'),
        'title'      => $title,
        'own_name'   => $ownName,
        'event_name' => $eventName,
        // El enlace manda sobre la caché: la caché puede estar fría, el
        // enlace viene siempre con el registro.
        'event_id'   => $eventId !== '' ? $eventId : (string) ($event['id'] ?? ''),
        'status'     => $val('status'),
        'signed_ts'  => $signedTs,
        'start_ts'   => $startTs,
        'end_ts'     => $endTs,
        'is_past'    => $isPast,
        'clase'      => $val('ajmcm_clase_c'),
        'curso'      => $val('ajmcm_curso_escolar_c'),
        'nvl'        => $nvl,
    );
}

/**
 * La línea de "cuándo" de una inscripción, y el icono que le toca.
 *
 * Con fechas del evento manda el evento; sin ellas, se dice claramente que lo
 * que se enseña es la fecha en que te apuntaste, para que nadie la confunda
 * con la fecha de la actividad.
 *
 * @return array{icon:string,text:string}|null
 */
function sticpa_registration_when_line($reg)
{
    $eventLine = sticpa_record_date_line($reg['start_ts'], $reg['end_ts']);
    if ($eventLine !== '') {
        return array('icon' => 'calendar', 'text' => $eventLine);
    }
    if ($reg['signed_ts']) {
        return array(
            'icon' => 'check',
            /* translators: %s = fecha en que se hizo la inscripción */
            'text' => sprintf(__('Te apuntaste el %s', 'sticpa'), sticpa_record_date_line($reg['signed_ts'])),
        );
    }
    return null;
}

/**
 * LISTADO de inscripciones como tarjetas.
 *
 * @param array $rows       Registros del CRM.
 * @param array $definition Definición de campos cacheada (para las etiquetas
 *                          de los desplegables; nunca se enseña la clave cruda).
 */
function sticpa_registrations_list_html($rows, $definition = array())
{
    $eventIndex = sticpa_registration_event_index();

    $models = array();
    foreach ((array) $rows as $row) {
        $nvl = $row->name_value_list ?? null;
        if (!$nvl) {
            continue;
        }
        $model = sticpa_registration_view_model($nvl, $eventIndex);
        if ($model) {
            $models[] = $model;
        }
    }

    if (empty($models)) {
        return sticpa_record_empty_html(
            'check',
            __('Todavía no tienes ninguna inscripción', 'sticpa'),
            __('Cuando te apuntes a una actividad aparecerá aquí, con su estado y lo que quede por hacer.', 'sticpa'),
            array('label' => __('Ver actividades abiertas', 'sticpa'), 'url' => '?internalpage=list_stic_events', 'primary' => true)
        );
    }

    // Lo que está por venir, arriba: es donde puede quedar algo por hacer.
    // Lo ya celebrado, abajo y de lo más reciente a lo más antiguo.
    usort($models, function ($a, $b) {
        // "Cerrada" = ya celebrada O cancelada. Una inscripción cancelada no
        // tiene nada pendiente, así que no ocupa la primera pantalla aunque su
        // actividad sea futura.
        $aCerrada = $a['is_past'] || sticpa_record_status_tone($a['status']) === 'danger';
        $bCerrada = $b['is_past'] || sticpa_record_status_tone($b['status']) === 'danger';
        if ($aCerrada !== $bCerrada) {
            return $aCerrada ? 1 : -1;
        }
        $aTs = $a['start_ts'] ?? $a['signed_ts'] ?? PHP_INT_MAX;
        $bTs = $b['start_ts'] ?? $b['signed_ts'] ?? PHP_INT_MAX;
        $cmp = $aTs <=> $bTs;
        // Lo cerrado, de lo más reciente a lo más antiguo: de una inscripción
        // de hace tres años ya no te acuerdas.
        return $aCerrada ? -$cmp : $cmp;
    });

    $cards = array();
    foreach ($models as $reg) {
        $lines = array();
        $when = sticpa_registration_when_line($reg);
        if ($when) {
            $lines[] = $when;
        }
        // El curso y la clase, en una sola línea: para una familia con varios
        // hijos es lo que distingue una inscripción de otra de un vistazo.
        $aula = array_filter(array($reg['curso'], $reg['clase']));
        if (!empty($aula)) {
            $lines[] = array('icon' => 'book', 'text' => implode(' · ', $aula));
        }

        $chips = array();
        $statusLabel = sticpa_record_enum_label($definition, 'status', $reg['status']);
        if ($statusLabel !== '') {
            $chips[] = array('label' => $statusLabel, 'tone' => sticpa_record_status_tone($reg['status']));
        }

        $detailUrl = '?internalpage=single_stic_registrations&action=detail&id=' . rawurlencode($reg['id']);

        // SIN barra de acciones, a propósito, y esto se decidió MIRANDO la
        // captura. Primero llevaba "Ver la inscripción": hace exactamente lo
        // mismo que tocar la tarjeta, y encima se llevaba el degradado de
        // marca, que con tres inscripciones aparecía tres veces y dejaba de
        // firmar nada (design.md §3). Luego llevaba "Ver la actividad", que es
        // un atajo de un atajo: la ficha ya ofrece ese enlace como su acción
        // principal. Las dos veces era una fila de botón que no añadía ningún
        // destino nuevo.
        //
        // Sin ella, la tarjeta mide ~50px menos y entran dos inscripciones más
        // sin hacer scroll, que en un móvil de 375px es lo que de verdad
        // importa. Si algún día hay algo que hacer DESDE la lista (pagar un
        // recibo pendiente, firmar una autorización), ese sí es un botón que
        // se gana su sitio: se añade aquí.
        $actions = array();

        $cards[] = array(
            'url'     => $detailUrl,
            // La cápsula lleva la fecha del EVENTO si se sabe; si no, la de la
            // inscripción, que es lo único que hay.
            'ts'      => $reg['start_ts'] ?: $reg['signed_ts'],
            'icon'    => 'check',
            'name'    => $reg['title'],
            'lines'   => $lines,
            'chips'   => $chips,
            'is_past' => $reg['is_past'] || sticpa_record_status_tone($reg['status']) === 'danger',
            'actions' => $actions,
        );
    }

    return sticpa_record_list_html($cards);
}

/**
 * Los datos de un tutor, si están. Devuelve null cuando no hay ni nombre: una
 * ficha con "Tutor 1: —" es peor que una ficha sin bloque de tutores.
 */
function sticpa_registration_tutor($nvl, $prefix, $definition)
{
    $val = function ($field) use ($nvl) {
        return isset($nvl->$field->value) ? trim((string) $nvl->$field->value) : '';
    };
    $nombre = trim($val($prefix . '_firstname_c') . ' ' . $val($prefix . '_lastname_c'));
    if ($nombre === '') {
        return null;
    }
    return array(
        'nombre'    => $nombre,
        'parentesco' => sticpa_record_enum_label($definition, $prefix . '_relationship_c', $val($prefix . '_relationship_c')),
        'telefono'  => $val($prefix . '_phone_c'),
        'email'     => $val($prefix . '_email_c'),
    );
}

/**
 * FICHA de una inscripción.
 *
 * @param array $reg        Modelo de sticpa_registration_view_model().
 * @param array $definition Definición de campos cacheada del módulo.
 */
function sticpa_registration_detail_html($reg, $definition = array())
{
    $nvl = $reg['nvl'];
    $val = function ($field) use ($nvl) {
        return isset($nvl->$field->value) ? trim((string) $nvl->$field->value) : '';
    };

    $statusLabel = sticpa_record_enum_label($definition, 'status', $reg['status']);
    $tone = sticpa_record_status_tone($reg['status']);

    $chips = array();
    if ($statusLabel !== '') {
        $chips[] = array('label' => $statusLabel, 'tone' => $tone);
    }
    if ($reg['is_past']) {
        $chips[] = array('label' => __('Ya celebrado', 'sticpa'), 'tone' => 'past');
    }

    // --- Avisos: lo que hay que saber antes que nada ---
    $notes = array();
    if ($tone === 'danger' && $statusLabel !== '') {
        $notes[] = array(
            'tone' => 'danger',
            /* translators: %s = estado de la inscripción, ya traducido por el CRM */
            'text' => sprintf(__('Esta inscripción está en estado «%s». Si crees que es un error, habla con tu delegación.', 'sticpa'), $statusLabel),
        );
    } elseif ($tone === 'warn' && $statusLabel !== '') {
        $notes[] = array(
            'tone' => 'warn',
            'text' => __('Todavía no está confirmada. Tu delegación la revisará y te avisará; no hace falta que hagas nada más.', 'sticpa'),
        );
    }
    $needsText = $val('special_needs_description');
    if ($needsText !== '') {
        $notes[] = array('tone' => 'info', 'icon' => 'info', 'text' => $needsText);
    }

    // --- El importe, si lo hay, es EL dato de la ficha ---
    $headline = null;
    $importe = $val('ajmcm_registration_amount_c');
    if ($importe !== '' && (float) $importe > 0) {
        $headline = array(
            'label' => __('Importe de la inscripción', 'sticpa'),
            'text'  => (string) formatValue($importe, 'currency'),
        );
    }

    // --- Datos clave ---
    $facts = array();
    // Solo si la cabecera está ocupada por las fechas del EVENTO. Si no, la
    // cabecera ya dice "Te apuntaste el…" y repetirlo aquí es decir dos veces
    // lo mismo en media pantalla (design.md §5).
    $when = sticpa_registration_when_line($reg);
    if ($reg['signed_ts'] && ($when['icon'] ?? '') === 'calendar') {
        $facts[] = array(
            'icon'  => 'check',
            'label' => __('Fecha de inscripción', 'sticpa'),
            'text'  => sticpa_record_date_line($reg['signed_ts']),
        );
    }
    if ($reg['curso'] !== '') {
        $facts[] = array('icon' => 'book', 'label' => __('Curso escolar', 'sticpa'), 'text' => $reg['curso']);
    }
    if ($reg['clase'] !== '') {
        $facts[] = array('icon' => 'users', 'label' => __('Clase', 'sticpa'), 'text' => $reg['clase']);
    }
    $tipo = sticpa_record_enum_label($definition, 'participation_type', $val('participation_type'));
    if ($tipo !== '') {
        $facts[] = array('icon' => 'tag', 'label' => __('Tipo de participación', 'sticpa'), 'text' => $tipo);
    }
    // La asistencia solo tiene sentido cuando la actividad ya ha empezado.
    $pct = $val('attendance_percentage');
    if ($pct !== '' && (float) $pct > 0) {
        $facts[] = array(
            'icon'  => 'clock',
            'label' => __('Asistencia', 'sticpa'),
            /* translators: %s = porcentaje de asistencia */
            'text'  => sprintf(__('%s %%', 'sticpa'), rtrim(rtrim(number_format_i18n((float) $pct, 1), '0'), ',.')),
        );
    }
    // El número de plazas de la inscripción solo se enseña si es más de una:
    // "1 persona" no le dice nada a nadie.
    $attendees = (int) $val('attendees');
    if ($attendees > 1) {
        $facts[] = array(
            'icon'  => 'users',
            'label' => __('Personas', 'sticpa'),
            /* translators: %d = número de personas de la inscripción */
            'text'  => sprintf(_n('%d persona', '%d personas', $attendees, 'sticpa'), $attendees),
        );
    }
    // El código administrativo, al final y solo si es distinto del título: es
    // lo que hay que decir por teléfono cuando algo va mal.
    if ($reg['own_name'] !== '' && $reg['own_name'] !== $reg['title']) {
        $facts[] = array('icon' => 'tag', 'label' => __('Referencia', 'sticpa'), 'text' => $reg['own_name']);
    }

    // --- Quién responde por el participante ---
    // Es LO que distingue esta pantalla del resto: aquí quien lee suele ser la
    // madre o el padre que hizo la inscripción, y necesita comprobar de un
    // vistazo que el teléfono que dejó es el bueno.
    $sections = array();
    $tutores = array_filter(array(
        sticpa_registration_tutor($nvl, 'ajmcm_tutor1', $definition),
        sticpa_registration_tutor($nvl, 'ajmcm_tutor2', $definition),
    ));
    if (!empty($tutores)) {
        $body = "<ul class='stic-rec-people'>";
        foreach ($tutores as $tutor) {
            $body .= "<li class='stic-rec-person'>";
            $body .= "<span class='stic-rec-person-ico'>" . sticpa_record_icon('user') . "</span>";
            $body .= "<span class='stic-rec-person-body'>";
            $body .= "<span class='stic-rec-person-name'>" . esc_html($tutor['nombre']) . "</span>";
            $meta = array_filter(array($tutor['parentesco'], $tutor['telefono'], $tutor['email']));
            if (!empty($meta)) {
                $body .= "<span class='stic-rec-person-meta'>" . esc_html(implode(' · ', $meta)) . "</span>";
            }
            $body .= "</span>";
            // El teléfono, pulsable: media pantalla de móvil y una llamada.
            if ($tutor['telefono'] !== '') {
                $tel = preg_replace('/[^0-9+]/', '', $tutor['telefono']);
                $body .= "<a class='stic-rec-person-call' href='tel:" . esc_attr($tel) . "'"
                    . " aria-label='" . esc_attr(sprintf(__('Llamar a %s', 'sticpa'), $tutor['nombre'])) . "'>"
                    . sticpa_record_icon('go') . "</a>";
            }
            $body .= "</li>";
        }
        $body .= "</ul>";
        $sections[] = array(
            'title' => __('Personas de contacto', 'sticpa'),
            'body'  => $body,
            'raw'   => true,
        );
    }

    // --- Qué se puede hacer desde aquí ---
    $actions = array();
    if ($reg['event_id'] !== '') {
        $actions[] = array(
            'label'   => __('Ver la actividad', 'sticpa'),
            'url'     => '?internalpage=single_stic_events&action=detail&id=' . rawurlencode($reg['event_id']),
            'primary' => true,
            'icon'    => 'go',
        );
    }
    $actions[] = array('label' => __('Mis pagos', 'sticpa'), 'url' => '?internalpage=list_stic_payments');

    return sticpa_record_detail_html(array(
        'back'     => array('url' => '?internalpage=list_stic_registrations', 'label' => __('Mis inscripciones', 'sticpa')),
        'title'    => $reg['title'],
        'meta'     => array($when ?: array('icon' => 'calendar', 'text' => '')),
        'chips'    => $chips,
        'headline' => $headline,
        'notes'    => $notes,
        'facts'    => $facts,
        'sections' => $sections,
        'actions'  => $actions,
    ));
}
