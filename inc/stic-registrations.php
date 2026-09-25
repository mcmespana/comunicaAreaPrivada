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
 * @param array $extra      Lo que la página ha averiguado aparte (EV-2/6/7):
 *   'aviso'       => nota tras guardar (sticpa_registration_saved_note()),
 *   'pagos'       => compromisos de pago de la inscripción (filas del CRM),
 *   'metodos'     => clave => etiqueta de `payment_method`,
 *   'pagar_url'   => a dónde lleva «Pagar con tarjeta» si no hay compromiso,
 *   'precio'      => precio del evento (float),
 *   'derechos'    => sticpa_registration_manage_rights(),
 *   'gestion'     => HTML de sticpa_registration_manage_html().
 */
function sticpa_registration_detail_html($reg, $definition = array(), $extra = array())
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
    if (!empty($extra['aviso'])) {
        $notes[] = $extra['aviso'];
    }
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
    // Lo que contestó al inscribirse (EV-6). El texto de la opción ya se
    // entiende solo («Sí, voy en autobús»): no hace falta ir a buscar la
    // pregunta al evento.
    $respuestas = array();
    foreach (sticpa_event_question_answer_fields() as $campo) {
        if ($val($campo) !== '') {
            $respuestas[] = $val($campo);
        }
    }
    foreach ($respuestas as $i => $respuesta) {
        $facts[] = array(
            'icon'  => 'check',
            'label' => count($respuestas) > 1
                /* translators: %d = número de la respuesta */
                ? sprintf(__('Tu respuesta (%d)', 'sticpa'), $i + 1)
                : __('Tu respuesta', 'sticpa'),
            'text'  => $respuesta,
        );
    }
    // El pago (EV-7): el compromiso que dejó la inscripción, con su medio.
    $metodos = (array) ($extra['metodos'] ?? array());
    foreach ((array) ($extra['pagos'] ?? array()) as $pago) {
        $pnvl = $pago->name_value_list ?? null;
        $pid = (string) ($pago->id ?? ($pnvl->id->value ?? ''));
        $importe = trim((string) ($pnvl->amount->value ?? ''));
        $metodo = trim((string) ($pnvl->payment_method->value ?? ''));
        $partes = array_filter(array(
            $importe !== '' ? (string) formatValue($importe, 'currency') : '',
            $metodos[$metodo] ?? '',
            trim((string) ($pnvl->end_date->value ?? '')) !== '' ? __('dado de baja', 'sticpa') : '',
        ));
        if (empty($partes) || $pid === '') {
            continue;
        }
        $facts[] = array(
            'icon'  => 'card',
            'label' => __('Pago', 'sticpa'),
            'text'  => implode(' · ', $partes),
            'link'  => array(
                'url' => '?internalpage=single_stic_payment_commitments&action=detail&id=' . rawurlencode($pid),
                'label' => __('Ver el compromiso de pago', 'sticpa'),
            ),
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

    // --- ¿Necesitas cambiar algo? (EV-2) ---
    if (!empty($extra['gestion'])) {
        $sections[] = array(
            'title' => __('¿Necesitas cambiar algo?', 'sticpa'),
            'body'  => $extra['gestion'],
            'raw'   => true,
            'class' => 'stic-reg-gestion-sec',
        );
    } elseif (!empty($extra['derechos']['motivo'])) {
        $notes[] = array('tone' => 'info', 'icon' => 'info', 'text' => $extra['derechos']['motivo']);
    }

    // --- Qué se puede hacer desde aquí ---
    $actions = array();
    // Una actividad con precio SIN compromiso de pago: o se eligió tarjeta y el
    // pago no se terminó, o la inscripción es de antes de que el área dejara
    // el pago anotado. Se ofrece pagar, con cuidado de no afirmar lo que no
    // sabemos: el formulario de pago con tarjeta no se ata a la inscripción.
    $pagarUrl = (string) ($extra['pagar_url'] ?? '');
    if ($pagarUrl !== '' && empty($extra['pagos']) && (float) ($extra['precio'] ?? 0) > 0 && $tone !== 'danger' && !$reg['is_past']) {
        $actions[] = array(
            /* translators: %s = precio de la actividad, ya formateado */
            'label' => sprintf(__('Pagar %s con tarjeta', 'sticpa'), (string) formatValue((string) $extra['precio'], 'currency')),
            'url' => $pagarUrl,
        );
    }
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

/* ==========================================================================
   GESTIONAR LA INSCRIPCIÓN — preguntas, pago, cancelar y modificar
   (TODO EV-6, EV-7 y EV-2, 25/09/2026)
   --------------------------------------------------------------------------
   Hasta aquí, desde el área solo se podía APUNTARSE: ni contestar a lo que la
   actividad necesita saber, ni dejar el pago en marcha, ni borrarse ni
   corregir nada. Tres piezas, y las tres pasan por el mismo formulario y el
   mismo handler (`prefix_admin_single_stic_registrations()`):

     · PREGUNTAS SIMPLES (EV-6). Un evento sí/no con una o dos preguntas
       («¿vas en autobús?») no debería necesitar un formulario web avanzado.
     · EL PAGO (EV-7). Una actividad con precio deja creado su compromiso de
       pago al inscribirse, con el medio que elija la persona.
     · CANCELAR Y MODIFICAR (EV-2). Mientras el plazo esté abierto.
   ========================================================================== */

/* ---- 1. Preguntas simples (EV-6) --------------------------------------- */

/**
 * Las parejas «pregunta del evento → respuesta de la inscripción».
 *
 * ⚠️ NOMBRES PROPUESTOS, NO CREADOS (25/09/2026). Ver `CAMPOS.md` → Eventos →
 * «Preguntas simples». Hasta que existan en Studio, el área no los pide ni
 * enseña nada: cada campo se cruza con la definición del CRM antes de usarlo.
 * Si se crean con otro nombre, se cambia aquí (o con el filtro) y ya está.
 */
function sticpa_event_question_fields()
{
    return (array) apply_filters('sticpa_event_question_fields', array(
        array('evento' => 'ajmcm_pregunta_1_c', 'respuesta' => 'ajmcm_respuesta_1_c'),
        array('evento' => 'ajmcm_pregunta_2_c', 'respuesta' => 'ajmcm_respuesta_2_c'),
    ));
}

/** Los campos de `stic_Events` con las preguntas. */
function sticpa_event_question_event_fields()
{
    return array_values(array_filter(array_map(function ($p) {
        return (string) ($p['evento'] ?? '');
    }, sticpa_event_question_fields())));
}

/** Los campos de `stic_Registrations` donde se guardan las respuestas. */
function sticpa_event_question_answer_fields()
{
    return array_values(array_filter(array_map(function ($p) {
        return (string) ($p['respuesta'] ?? '');
    }, sticpa_event_question_fields())));
}

/**
 * Una pregunta tal como se escribe en el CRM → pregunta y opciones.
 *
 * El formato es el que se escribe en una caja de texto sin pensar:
 *
 *     Sí, voy en autobús;No, voy por mi cuenta
 *     ¿Cómo vienes? | Sí, voy en autobús; No, voy por mi cuenta
 *
 * Las opciones van separadas por `;` (no por comas: «Sí, voy en autobús» lleva
 * una). La pregunta es opcional y va delante de una `|`. Una sola opción no es
 * una pregunta: se ignora, antes que obligar a contestar lo único que hay.
 *
 * @return array|null pregunta (puede ser ''), opciones (2 o más).
 */
function sticpa_event_question_parse($raw)
{
    $t = function_exists('mcm_cuerpo_normalizar')
        ? mcm_cuerpo_normalizar((string) $raw)
        : trim(html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $t = trim(preg_replace('/\s+/u', ' ', $t));
    if ($t === '') {
        return null;
    }
    $pregunta = '';
    if (strpos($t, '|') !== false) {
        list($pregunta, $t) = explode('|', $t, 2);
    }
    $opciones = array();
    foreach (explode(';', $t) as $op) {
        $op = trim($op);
        // 255: lo que cabe en el campo de la respuesta.
        $op = function_exists('mb_substr') ? mb_substr($op, 0, 255, 'UTF-8') : substr($op, 0, 255);
        if ($op !== '' && !in_array($op, $opciones, true)) {
            $opciones[] = $op;
        }
    }
    if (count($opciones) < 2) {
        return null;
    }
    return array('pregunta' => trim($pregunta), 'opciones' => $opciones);
}

/**
 * Las preguntas de un evento que se pueden contestar AQUÍ: las que tienen
 * texto en el evento Y su campo de respuesta existe en la inscripción.
 *
 * @param object $eventNvl   name_value_list del evento.
 * @param array  $regDefinition Definición de `stic_Registrations` (la de
 *                           sticpa_registration_definition()).
 * @return array lista de n, campo (el de la respuesta), pregunta, opciones.
 */
function sticpa_event_questions($eventNvl, $regDefinition)
{
    $out = array();
    if (!$eventNvl) {
        return $out;
    }
    $n = 0;
    foreach (sticpa_event_question_fields() as $par) {
        $n++;
        $campoEvento = (string) ($par['evento'] ?? '');
        $campoRespuesta = (string) ($par['respuesta'] ?? '');
        if ($campoEvento === '' || $campoRespuesta === '' || !isset($regDefinition[$campoRespuesta])) {
            continue;
        }
        $q = sticpa_event_question_parse($eventNvl->$campoEvento->value ?? '');
        if ($q === null) {
            continue;
        }
        $out[] = array('n' => $n, 'campo' => $campoRespuesta) + $q;
    }
    return $out;
}

/**
 * Los campos de formulario de las preguntas: un grupo de opciones cada una.
 * El valor que viaja es el NÚMERO de la opción, no su texto: así se comprueba
 * en el servidor contra el evento (sticpa_event_questions_resolve()) y no se
 * puede guardar una respuesta que no estaba entre las opciones.
 *
 * @param array $questions Salida de sticpa_event_questions().
 * @param object|null $regNvl La inscripción, al editar (para marcar lo que ya
 *                    se contestó).
 */
function sticpa_event_question_form_fields($questions, $regNvl = null)
{
    $fields = array();
    foreach ($questions as $i => $q) {
        $opciones = array();
        foreach ($q['opciones'] as $k => $op) {
            $opciones[(string) ($k + 1)] = array('title' => $op);
        }
        $actual = '';
        if ($regNvl) {
            $campo = $q['campo'];
            $guardada = trim((string) ($regNvl->$campo->value ?? ''));
            $pos = array_search($guardada, $q['opciones'], true);
            if ($pos !== false) {
                $actual = (string) ($pos + 1);
            }
        }
        $label = $q['pregunta'] !== ''
            ? $q['pregunta']
            : (count($questions) > 1
                /* translators: %d = número de la pregunta */
                ? sprintf(__('Elige una opción (%d)', 'sticpa'), $i + 1)
                : __('Elige una opción', 'sticpa'));
        $fields[] = array(
            'name' => $q['campo'] . '_row',
            'type' => 'html',
            // El <input> lo pinta la tarjeta: se declara para que se guarde.
            'posts' => array($q['campo']),
            'html' => sticpa_registration_choice_html($q['campo'], $label, $opciones, $actual),
        );
    }
    return $fields;
}

/**
 * Un grupo de opciones como TARJETAS (el componente de design-system §4,
 * «tarjetas de opción», en su versión compacta): se toca la tarjeta entera,
 * no un circulito de 20 px. Obligatorio: sin elegir no se manda.
 *
 * @param string $name    Nombre del campo (y id del grupo, para
 *                        `data-visible-when`).
 * @param array  $options clave => array('title', 'icon'?, 'desc'?).
 * @param string $aside   HTML a la derecha de la pregunta (el precio).
 */
function sticpa_registration_choice_html($name, $label, $options, $current = '', $aside = '')
{
    $labelId = $name . '_label';
    $html = "<li class='stic-option-row stic-choice'>"
        . "<div class='stic-choice-head'><span class='stic-choice-label' id='" . esc_attr($labelId) . "'>" . esc_html($label) . "</span>" . $aside . "</div>"
        . "<div class='stic-option-grid stic-choice-grid' role='radiogroup' aria-labelledby='" . esc_attr($labelId) . "' id='" . esc_attr($name) . "'>";
    foreach ($options as $key => $op) {
        $checked = ($current !== '' && (string) $current === (string) $key) ? ' checked' : '';
        $html .= "<label class='stic-option-card stic-choice-card'>"
            . "<input type='radio' name='" . esc_attr($name) . "' value='" . esc_attr((string) $key) . "' required{$checked}>"
            . "<span class='stic-option-title'>" . ($op['icon'] ?? '') . "<span>" . esc_html($op['title']) . "</span></span>"
            . (!empty($op['desc']) ? "<span class='stic-option-desc'>" . esc_html($op['desc']) . "</span>" : '')
            . "</label>";
    }
    return $html . "</div></li>";
}

/**
 * Cambia el número de opción que llega del formulario por su TEXTO, que es lo
 * que se guarda (se lee en el CRM sin tener que ir al evento). Si alguna
 * pregunta se queda sin contestar o con un número que no existe, false: no se
 * guarda nada.
 */
function sticpa_event_questions_resolve($questions, &$moduleData)
{
    foreach ($questions as $q) {
        $campo = $q['campo'];
        $idx = isset($moduleData[$campo]) ? (int) $moduleData[$campo] : 0;
        if ($idx < 1 || $idx > count($q['opciones'])) {
            return false;
        }
        $moduleData[$campo] = $q['opciones'][$idx - 1];
    }
    return true;
}

/**
 * LA definición de `stic_Registrations` para inscribirse, ver y editar: una
 * sola lista, una sola clave de caché (el mismo cuidado que
 * sticpa_event_field_definition()). Lleva `status` —así nunca viene vacía y
 * se cachea aunque las respuestas aún no existan— y los campos de respuesta.
 */
function sticpa_registration_definition($objSCP)
{
    if (!function_exists('sticpa_cached_field_definition') || $objSCP === null) {
        return array();
    }
    $def = sticpa_cached_field_definition($objSCP, 'stic_Registrations', array_merge(
        array('status', 'participation_type', 'ajmcm_tutor1_relationship_c', 'ajmcm_tutor2_relationship_c'),
        sticpa_event_question_answer_fields()
    ));
    return is_array($def) ? $def : array();
}

/** Los campos de respuesta que EXISTEN en el CRM (para pedirlos en la ficha). */
function sticpa_registration_existing_answer_fields($regDefinition)
{
    return array_values(array_filter(sticpa_event_question_answer_fields(), function ($f) use ($regDefinition) {
        return isset($regDefinition[$f]);
    }));
}

/* ---- 2. El pago (EV-7) ------------------------------------------------- */

/** El precio del evento, o 0 si no tiene (un 0,00 es el valor por defecto). */
function sticpa_event_price($eventNvl)
{
    $raw = trim((string) ($eventNvl->price->value ?? ''));
    $raw = str_replace(',', '.', $raw);
    return is_numeric($raw) && (float) $raw > 0 ? round((float) $raw, 2) : 0.0;
}

/** Un desplegable de la definición del CRM → clave => etiqueta. */
function sticpa_crm_enum_options($definition, $field)
{
    $out = array();
    foreach ((array) ($definition[$field]['options'] ?? array()) as $key => $item) {
        $name = is_array($item) ? (string) ($item['name'] ?? $key) : (string) $key;
        $label = is_array($item) ? (string) ($item['value'] ?? $name) : (string) $item;
        if (trim($name) !== '') {
            $out[$name] = $label !== '' ? $label : $name;
        }
    }
    return $out;
}

/**
 * Los medios de pago que se ofrecen al inscribirse, con la etiqueta del CRM.
 *
 * Se ofrecen SOLO los que existen en el desplegable del CRM (se lee su
 * definición, que sí trae las opciones). La API acepta cualquier cadena en un
 * `enum` sin rechistar, y en `CAMPOS.md` solo están confirmadas `direct_debit`
 * y `card`: una clave inventada no daría error, se guardaría rota. Así que la
 * lista de abajo es de CANDIDATAS; lo que sale es lo que el CRM dice que hay.
 *
 * `card` es distinto de los demás: no se deja un compromiso, se pasa al
 * formulario de pago con tarjeta (ver el handler).
 */
function sticpa_registration_payment_methods($objSCP)
{
    if ($objSCP === null || !function_exists('sticpa_cached_field_definition')) {
        return array();
    }
    // La misma lista que el formulario de pago: una sola clave de caché.
    $def = sticpa_cached_field_definition($objSCP, 'stic_Payment_Commitments', array('payment_method'));
    $crm = sticpa_crm_enum_options($def, 'payment_method');
    $candidatas = (array) apply_filters('sticpa_registration_payment_methods',
        array('bizum', 'transfer', 'transfer_received', 'cash', 'direct_debit', 'card'));
    $out = array();
    foreach ($candidatas as $key) {
        if (isset($crm[$key])) {
            $out[$key] = $crm[$key];
        }
    }
    return $out;
}

/** El tipo de cobro de una inscripción a una actividad. */
function sticpa_registration_payment_type()
{
    // `services` es el que pone el propio CRM a los compromisos que crea para
    // las convivencias (visto por MCP el 25/09/2026); `fee` (cuota) es para las
    // cuotas del curso. Ver CAMPOS.md → Compromisos de pago.
    return (string) apply_filters('sticpa_registration_payment_type', 'services');
}

/** El icono de cada medio de pago (los que no conocemos, sin icono). */
function sticpa_payment_method_icon($key)
{
    $map = array(
        'bizum' => 'phone',
        'transfer' => 'swap',
        'transfer_received' => 'swap',
        'cash' => 'cash',
        'direct_debit' => 'bank',
        'card' => 'card',
    );
    return isset($map[$key]) ? sticpa_record_icon($map[$key]) : '';
}

/**
 * Los campos del formulario de inscripción para el pago: la pregunta con el
 * precio al lado y los medios como tarjetas. Nada más: el precio se ve, y lo
 * que pasa después se ve al elegir (el IBAN solo con domiciliación).
 */
function sticpa_registration_payment_form_fields($price, $methods)
{
    if ($price <= 0 || empty($methods)) {
        return array();
    }
    $opciones = array();
    foreach ($methods as $key => $label) {
        $opciones[$key] = array(
            'title' => $label,
            'icon' => sticpa_payment_method_icon($key),
            // Lo único que no se deduce del nombre: con tarjeta se paga ya.
            'desc' => $key === 'card' ? __('Pago online', 'sticpa') : '',
        );
    }
    $fields = array();
    $fields[] = array(
        'name' => 'sticpa_pago_row',
        'type' => 'html',
        'posts' => array('sticpa_pago_metodo'),
        'html' => sticpa_registration_choice_html(
            'sticpa_pago_metodo',
            __('¿Cómo pagas?', 'sticpa'),
            $opciones,
            '',
            "<span class='stic-choice-price'>" . esc_html((string) formatValue((string) $price, 'currency')) . "</span>"
        ),
    );
    if (isset($methods['direct_debit'])) {
        // Obligatorio cuando se ve: el JS de los campos condicionales le quita
        // el `required` mientras está escondido (js/stic-ui.js). El servidor
        // comprueba además que sea un IBAN de verdad.
        $fields[] = array(
            'name' => 'sticpa_pago_iban',
            'type' => 'text',
            'label' => __('IBAN', 'sticpa'),
            'required' => true,
            'placeholder' => 'ES00 0000 0000 0000 0000 0000',
            'attributes' => array(
                'data-visible-when' => 'sticpa_pago_metodo:direct_debit',
                'autocomplete' => 'off',
                'spellcheck' => 'false',
            ),
        );
    }
    return $fields;
}

/** El IBAN sin espacios y en mayúsculas (así se guarda, ver CAMPOS.md). */
function sticpa_iban_normalize($iban)
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $iban));
}

/** ¿Es un IBAN bien formado? Dígitos de control por módulo 97 (ISO 13616). */
function sticpa_iban_is_valid($iban)
{
    $iban = sticpa_iban_normalize($iban);
    if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
        return false;
    }
    if (strpos($iban, 'ES') === 0 && strlen($iban) !== 24) {
        return false;
    }
    $reordenado = substr($iban, 4) . substr($iban, 0, 4);
    $resto = 0;
    foreach (str_split($reordenado) as $c) {
        $n = ctype_alpha($c) ? (string) (ord($c) - 55) : $c;
        foreach (str_split($n) as $d) {
            $resto = ($resto * 10 + (int) $d) % 97;
        }
    }
    return $resto === 1;
}

/**
 * Saca del formulario la elección de pago (no son campos del CRM: no pueden
 * llegar a `set_entry`) y la comprueba.
 *
 * @return array|null metodo, iban; null si falta o no vale.
 */
function sticpa_registration_take_payment_choice(&$moduleData, $methods)
{
    $metodo = (string) ($moduleData['sticpa_pago_metodo'] ?? '');
    $iban = sticpa_iban_normalize($moduleData['sticpa_pago_iban'] ?? '');
    unset($moduleData['sticpa_pago_metodo'], $moduleData['sticpa_pago_iban']);
    if ($metodo === '' || !isset($methods[$metodo])) {
        return null;
    }
    if ($metodo === 'direct_debit' && !sticpa_iban_is_valid($iban)) {
        return null;
    }
    return array('metodo' => $metodo, 'iban' => $metodo === 'direct_debit' ? $iban : '');
}

/**
 * Quién paga y para quién es. Mismo criterio que el formulario de pago: si la
 * sesión es de un participante al que ve su familiar, paga el familiar y el
 * participante es el destinatario (SinergiaCRM separa las dos personas para
 * este caso exacto; ver sticpa_commitment_lo_paga_otra_persona()).
 *
 * @return array pagador, destinatario ('' si es la misma persona).
 */
function sticpa_registration_payer()
{
    $participante = (string) ($_SESSION['scp_user_id'] ?? '');
    $pagador = !empty($_SESSION['scp_user_adult'])
        ? $participante
        : (string) ($_SESSION['scp_tutor_user_id'] ?? $participante);
    if ($pagador === '') {
        $pagador = $participante;
    }
    return array('pagador' => $pagador, 'destinatario' => $pagador !== $participante ? $participante : '');
}

/** Los compromisos de pago que cuelgan de una inscripción. null si el CRM no contesta. */
function sticpa_registration_commitments($objSCP, $regId, $fields = array('id', 'name', 'amount', 'payment_method', 'active', 'end_date'))
{
    if ($objSCP === null || trim((string) $regId) === '') {
        return array();
    }
    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'stic_Registrations',
        'module_id' => (string) $regId,
        'link_field_name' => 'stic_payment_commitments_stic_registrations',
        'related_fields' => $fields,
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
    ));
    return is_array($rows) ? $rows : null;
}

/**
 * EL COMPROMISO DE PAGO DE UNA INSCRIPCIÓN: exactamente uno.
 *
 * ⚠️ EL CRM TIENE SU PROPIO AUTOMATISMO. Al guardar una inscripción con el
 * IBAN del tutor y un importe (lo que manda la renovación), crea él solo un
 * compromiso «… - Domiciliación - importe». La renovación creaba además el
 * suyo y COBRÓ DOS VECES (22/09/2026). Las del área no mandan ni el IBAN del
 * tutor ni el importe de la inscripción, así que el automatismo no salta —por
 * eso no había compromiso—; pero su condición exacta no se ve por MCP (es
 * código del CRM, no un workflow). Así que antes de crear se mira si la
 * inscripción ya tiene uno: si lo tiene, se COMPLETA ese; si no, se crea. Es
 * la misma salvaguarda que dejó la renovación (`renovCrearCompromiso()`).
 *
 * @return array estado ('creado'|'completado'|'error'), id.
 */
function sticpa_registration_ensure_commitment($objSCP, $regId, $eventNvl, $choice, $delegacion)
{
    $price = sticpa_event_price($eventNvl);
    if ($price <= 0 || $choice === null) {
        return array('estado' => 'omitido', 'id' => '');
    }
    $personas = sticpa_registration_payer();
    $eventName = trim(function_exists('mcm_cuerpo_normalizar')
        ? mcm_cuerpo_normalizar((string) ($eventNvl->name->value ?? ''))
        : (string) ($eventNvl->name->value ?? ''));
    $participante = trim((string) ($_SESSION['scp_user_contact_name'] ?? ''));
    $hoy = date('Y-m-d');

    $campos = array(
        'name' => trim($participante . ' - ' . $eventName, ' -'),
        // Lo que se ve en el extracto del banco: el nombre de la actividad, y
        // no más de lo que cabe en un concepto SEPA.
        'banking_concept' => function_exists('mb_substr') ? mb_substr($eventName, 0, 140, 'UTF-8') : substr($eventName, 0, 140),
        'amount' => number_format($price, 2, '.', ''),
        'payment_method' => $choice['metodo'],
        'payment_type' => sticpa_registration_payment_type(),
        'periodicity' => 'punctual',
        // Obligatorio en el módulo. Hoy: la persona se acaba de apuntar a algo
        // que cuesta dinero, y el pago se espera ya (la domiciliación entra en
        // la siguiente remesa de su delegación).
        'first_payment_date' => $hoy,
        'signature_date' => $hoy,
        'active' => 1,
        'description' => sprintf('Creado desde el área privada al inscribirse a «%s» el %s.', $eventName, date('d/m/Y')),
    );
    if ($choice['iban'] !== '') {
        $campos['bank_account'] = $choice['iban'];
    }
    if ($delegacion !== '') {
        // Por delegación, como todo lo demás: de aquí cuelga el grupo de
        // seguridad y así cada MCM Local cobra lo suyo.
        $campos['assigned_user_id'] = $delegacion;
    }

    $existentes = sticpa_registration_commitments($objSCP, $regId, array('id'));
    foreach ((array) $existentes as $row) {
        $cid = (string) ($row->id ?? ($row->name_value_list->id->value ?? ''));
        if ($cid !== '') {
            $campos['id'] = $cid;
            $campos['description'] = str_replace('Creado desde', 'Completado desde', $campos['description']);
            $id = $objSCP->set_entry('stic_Payment_Commitments', $campos);
            return array('estado' => $id ? 'completado' : 'error', 'id' => $id ? (string) $id : $cid);
        }
    }

    $id = $objSCP->set_entry('stic_Payment_Commitments', $campos);
    if (!$id) {
        return array('estado' => 'error', 'id' => '');
    }
    // Las personas y la inscripción, por relación: este módulo no tiene campos
    // planos `_ida` que valgan para escribir (CAMPOS.md).
    $accounts = function_exists('getDestinationModule') && getDestinationModule() === 'Accounts';
    $objSCP->set_relationship('stic_Payment_Commitments', $id,
        $accounts ? 'stic_payment_commitments_accounts' : 'stic_payment_commitments_contacts', array($personas['pagador']));
    if ($personas['destinatario'] !== '' && !$accounts) {
        $objSCP->set_relationship('stic_Payment_Commitments', $id, 'stic_payment_commitments_contacts_1', array($personas['destinatario']));
    }
    $objSCP->set_relationship('stic_Payment_Commitments', $id, 'stic_payment_commitments_stic_registrations', array((string) $regId));
    return array('estado' => 'creado', 'id' => (string) $id);
}

/**
 * Al cancelar una inscripción, su compromiso se da de BAJA (fecha de fin hoy)
 * y se deja escrito por qué. No se borra ni se toca ningún pago: si ya se
 * cobró algo, devolverlo lo decide la delegación. Lo que sí se evita es que
 * una domiciliación de algo cancelado siga viva para la siguiente remesa.
 */
function sticpa_registration_end_commitments($objSCP, $regId)
{
    $rows = sticpa_registration_commitments($objSCP, $regId, array('id', 'description', 'end_date'));
    $n = 0;
    foreach ((array) $rows as $row) {
        $nvl = $row->name_value_list ?? null;
        $cid = (string) ($row->id ?? ($nvl->id->value ?? ''));
        if ($cid === '' || trim((string) ($nvl->end_date->value ?? '')) !== '') {
            continue;
        }
        $nota = trim((string) ($nvl->description->value ?? ''));
        $nota .= ($nota !== '' ? "\n" : '') . sprintf('Inscripción cancelada desde el área privada el %s.', date('d/m/Y'));
        if ($objSCP->set_entry('stic_Payment_Commitments', array('id' => $cid, 'end_date' => date('Y-m-d'), 'description' => $nota))) {
            $n++;
        }
    }
    return $n;
}

/* ---- 3. Cancelar y modificar (EV-2) ------------------------------------ */

/** La clave de «cancelada» en el desplegable de estado de la inscripción. */
function sticpa_registration_cancel_status()
{
    // Es la que ya usa el área para saber qué inscripciones no cuentan
    // (prefix_user_active_event_ids(), el calendario, Pasar Lista). Antes de
    // escribirla se comprueba que existe en el desplegable del CRM.
    return (string) apply_filters('sticpa_registration_cancel_status', 'cancelled');
}

/**
 * ¿Qué puede hacer la persona con su inscripción? Cambiarla y cancelarla,
 * mientras el plazo de la actividad siga abierto y la actividad no haya
 * pasado. Fuente única: la ficha lo usa para enseñar los botones y el handler
 * para aceptar o no el cambio (el botón es cortesía; esto es el guard).
 *
 * @param string      $status    Estado actual de la inscripción (clave).
 * @param object|null $eventNvl  El evento (con las fechas del plazo).
 * @param array       $regDefinition Definición de `stic_Registrations`.
 * @return array editar (bool), cancelar (bool), motivo, hasta_ts.
 */
function sticpa_registration_manage_rights($status, $eventNvl, $regDefinition)
{
    $no = function ($motivo) {
        return array('editar' => false, 'cancelar' => false, 'motivo' => $motivo, 'hasta_ts' => null);
    };
    $cancelKey = sticpa_registration_cancel_status();
    if ((string) $status === $cancelKey || (function_exists('sticpa_record_status_tone') && sticpa_record_status_tone($status) === 'danger')) {
        return $no('');
    }
    if (!$eventNvl) {
        // Sin el evento no se sabe si el plazo sigue abierto: no se ofrece.
        return $no('');
    }
    $event = function_exists('sticpa_event_view_model') ? sticpa_event_view_model($eventNvl) : null;
    if (!$event || !empty($event['is_past'])) {
        return $no('');
    }
    $plazo = $event['registro'] ?? array('abierta' => true, 'end_ts' => null);
    if (empty($plazo['abierta'])) {
        return $no(__('El plazo de inscripción ya ha terminado. Si necesitas cambiar algo o no puedes ir, habla con tu delegación.', 'sticpa'));
    }
    $options = sticpa_crm_enum_options($regDefinition, 'status');
    // UN CURSO NO SE CANCELA DESDE AQUÍ. «MIC | Curso 2026-2027» es también
    // un evento con inscripción, y a menudo con el plazo abierto (o sin fechas,
    // que cuenta como abierto): cancelarlo es darse de baja del curso entero —
    // fuera de las listas de Pasar Lista y con la cuota dada de baja—, y eso
    // se habla con la delegación. Se distingue igual que en la ficha del
    // evento: por encima de un mes no es una actividad, es un curso
    // (`sticpa_event_max_dias_duracion`).
    $maxDias = (int) apply_filters('sticpa_event_max_dias_duracion', 31);
    $esCurso = !empty($event['days']) && $event['days'] > $maxDias;
    return array(
        'editar' => true,
        // Solo si el CRM tiene esa clave: la API no valida los desplegables, y
        // un estado inventado no daría error — se quedaría guardado y roto.
        'cancelar' => isset($options[$cancelKey]) && !$esCurso,
        'motivo' => $esCurso ? __('Para darte de baja del curso, habla con tu delegación.', 'sticpa') : '',
        'hasta_ts' => $plazo['end_ts'] ?? null,
    );
}

/**
 * El bloque «¿Necesitas cambiar algo?» de la ficha de una inscripción:
 * modificar (un enlace) y cancelar (un formulario firmado, con confirmación).
 *
 * Cancelar es un POST con la firma del formulario atada a la sesión
 * (`sticpa_form_is_genuine()`): sin ella, un enlace ajeno con el id de tu
 * inscripción te la cancelaba al abrirlo. Mismo trato que el borrado de
 * documentos.
 */
function sticpa_registration_manage_html($regId, $rights)
{
    if (empty($rights['editar']) && empty($rights['cancelar'])) {
        return '';
    }
    $nota = trim((string) ($rights['motivo'] ?? ''));
    $html = "<div class='stic-reg-gestion'>";
    if (!empty($rights['editar'])) {
        $html .= "<a class='stic-rec-btn stic-rec-btn--ghost' href='?internalpage=single_stic_registrations&amp;action=edit&amp;id=" . esc_attr(rawurlencode((string) $regId)) . "'>"
            . sticpa_record_icon('edit') . "<span>" . esc_html__('Modificar mis datos', 'sticpa') . "</span></a>";
    }
    if (!empty($rights['cancelar'])) {
        $base = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
        $html .= "<form class='stic-reg-cancel' method='post' action='" . esc_url(home_url() . '/wp-admin/admin-post.php') . "'"
            . " data-confirm-title='" . esc_attr__('¿Cancelar tu inscripción?', 'sticpa') . "'"
            . " data-confirm-msg='" . esc_attr__('Tu plaza quedará libre.', 'sticpa') . "'"
            . " data-confirm-cancel='" . esc_attr__('No, mantenerla', 'sticpa') . "'"
            . " data-confirm-ok='" . esc_attr__('Sí, cancelar', 'sticpa') . "'>"
            . "<input type='hidden' name='action' value='single_stic_registrations'>"
            . "<input type='hidden' name='stic-action' value='cancel'>"
            . "<input type='hidden' name='id' value='" . esc_attr((string) $regId) . "'>"
            . "<input type='hidden' name='scp_current_url' value='" . esc_attr($base . '?internalpage=single_stic_registrations') . "'>"
            . sticpa_form_fields_input('single_stic_registrations', array())
            . "<button type='submit' class='stic-rec-btn stic-danger-button' onclick='return sticConfirmSubmit(this)'>"
            . esc_html__('Cancelar mi inscripción', 'sticpa') . "</button>"
            . "</form>";
    }
    $html .= "</div>";
    if ($nota !== '') {
        // Se puede cambiar pero no cancelar (un curso): se dice por qué.
        $html .= "<p class='stic-reg-gestion-nota'>" . esc_html($nota) . "</p>";
    } elseif (!empty($rights['hasta_ts']) && function_exists('sticpa_record_date_line')) {
        /* translators: %s = fecha de fin del plazo */
        $html .= "<p class='stic-reg-gestion-nota'>" . esc_html(sprintf(__('Hasta el %s.', 'sticpa'), sticpa_record_date_line($rights['hasta_ts'], null))) . "</p>";
    }
    return $html;
}

/**
 * El aviso de la ficha después de guardar, según el `msg` con el que vuelve el
 * handler. Antes se volvía con `msg=true` a una ficha que no pinta mensajes:
 * guardabas y no pasaba nada visible.
 */
function sticpa_registration_saved_note($msg)
{
    switch ((string) $msg) {
        case 'inscrita':
            return array('tone' => 'ok', 'icon' => 'check', 'text' => __('Listo: ya estás inscrito.', 'sticpa'));
        case 'inscrita_pago':
            return array('tone' => 'ok', 'icon' => 'check', 'text' => __('Listo: ya estás inscrito.', 'sticpa'));
        case 'true':
            return array('tone' => 'ok', 'icon' => 'check', 'text' => __('Cambios guardados.', 'sticpa'));
        case 'cancelada':
            return array('tone' => 'info', 'icon' => 'info', 'text' => __('Inscripción cancelada.', 'sticpa'));
        case 'cerrada':
            return array('tone' => 'warn', 'text' => __('El plazo de inscripción ya ha terminado, así que no se ha cambiado nada. Si lo necesitas, habla con tu delegación.', 'sticpa'));
        case 'pago_error':
            return array('tone' => 'warn', 'text' => __('Estás inscrito, pero no hemos podido dejar anotado el pago. Tu delegación lo revisará; no hace falta que te vuelvas a inscribir.', 'sticpa'));
        case 'error':
            return array('tone' => 'danger', 'text' => __('No hemos podido guardar el cambio. Inténtalo otra vez en un rato.', 'sticpa'));
    }
    return null;
}

/**
 * La tarjeta «Te inscribes a» del formulario, con el enlace a la ficha del
 * evento: el formulario solo enseña nombre y fechas, y lo que hace falta leer
 * antes de apuntarse (cartel, cuerpo de la web, documentos) está en la ficha.
 */
function sticpa_registration_event_card_html($kicker, $name, $dateLine, $desc = '', $eventId = '', $iconSvg = '')
{
    if ($iconSvg === '') {
        $iconSvg = sticpa_record_icon('calendar');
    }
    $html = '<li class="stic-event-card">'
        . '<span class="stic-event-card-kicker">' . esc_html($kicker) . '</span>'
        . '<div class="stic-event-card-title">' . esc_html($name) . '</div>'
        . ($dateLine !== '' ? '<div class="stic-event-card-meta">' . $iconSvg . '<span>' . esc_html($dateLine) . '</span></div>' : '')
        . ($desc !== '' ? '<p class="stic-event-card-desc">' . esc_html($desc) . '</p>' : '');
    if (trim((string) $eventId) !== '') {
        $html .= '<a class="stic-event-card-link" href="?internalpage=single_stic_events&amp;action=detail&amp;id=' . esc_attr(rawurlencode((string) $eventId)) . '">'
            . esc_html__('Ver la actividad', 'sticpa') . sticpa_record_icon('go') . '</a>';
    }
    return $html . '</li>';
}

/** Enseña la descripción de las necesidades especiales solo si se marcan. */
function sticpa_registration_special_needs_js()
{
    return '
<script>
document.addEventListener("DOMContentLoaded", function(event) {
    (function($) {
        if ($("#registration_date").length) { $("#registration_date").val(getCurrentDateTime()); }
        function toggleNeeds() {
            if ($("#special_needs").val() == "1") {
                $("#special_needs_description").parent().parent().show();
            } else {
                $("#special_needs_description").parent().parent().hide();
            }
        }
        toggleNeeds();
        $("#special_needs").change(toggleNeeds);
    })(jQuery);
});
</script>';
}
