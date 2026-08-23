<?php
/**
 * PASAR LISTA — consultas y escrituras contra el CRM.
 * ----------------------------------------------------------------------------
 * La lógica de curso, sesiones y porcentajes vive en inc/stic-pasar-lista.php
 * (sin CRM, con tests). Aquí está lo que habla con SinergiaCRM.
 *
 * TODOS los nombres técnicos de este archivo están VERIFICADOS contra la
 * instancia con get_module_fields. No se inventa ninguno: si hace falta uno
 * nuevo, primero se comprueba (ver CLAUDE.md).
 *
 *   ajmcm_GRUPOS                  code · name · level · cursos_c
 *     ├─ ajmcm_grupos_accounts                        → Accounts (delegación)
 *     ├─ ajmcm_grupos_stic_contacts_relationships     → stic_Contacts_Relationships
 *     └─ lis_listas_ajmcm_grupos                      → LIS_listas
 *   stic_Contacts_Relationships   relationship_type · role · start_date · end_date
 *     └─ stic_contacts_relationships_contacts         → Contacts
 *   stic_Sessions                 start_date · end_date
 *     ├─ stic_sessions_stic_events                    → stic_Events
 *     ├─ stic_attendances_stic_sessions               → stic_Attendances
 *     └─ lis_listas_stic_sessions                     → LIS_listas
 *   stic_Attendances              status · start_date · duration
 *     └─ stic_attendances_stic_registrations          → stic_Registrations
 *   LIS_listas                    estado · pasada_el · n_asistieron · n_faltaron
 *
 * CACHÉ. Dos vidas distintas, dos transients (§5 del diseño):
 *   estructura (grupos y quién está en ellos) → cambia una vez al año, TTL largo
 *   estado     (asistencias y listas)         → cambia cada sábado, TTL corto
 * La clave SIEMPRE lleva la delegación: un transient de WordPress no lo protege
 * el grupo de seguridad del CRM, así que si la clave no distingue delegación,
 * un monitor de Castellón podría leer la caché de Valencia.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claves internas del desplegable `estado` de `LIS_listas`.
 *
 * La API no devuelve las opciones de los enum (comprobado), así que no se
 * pueden leer: se declaran aquí y se pueden corregir con un filtro sin tocar
 * código si en el CRM se llaman de otra forma.
 */
function sticpa_pl_lista_estados()
{
    return apply_filters('sticpa_pl_lista_estados', array(
        'pasada' => 'pasada',
        'omitida' => 'omitida',
    ));
}

/** Valores de `relationship_type` que nos interesan de las relaciones. */
function sticpa_pl_rel_types()
{
    return apply_filters('sticpa_pl_rel_types', array(
        'participante' => array('participante_mic_com'),
        'monitor' => array('monitor'),
    ));
}

/**
 * ¿Qué papel tiene esta relación? Comparación tolerante (subcadena, sin
 * mayúsculas) por la misma razón que en sticpa_detect_role_from_relationship:
 * no queremos depender de la clave interna exacta para algo tan básico.
 */
function sticpa_pl_rel_role($raw)
{
    $v = function_exists('mb_strtolower') ? mb_strtolower((string) $raw, 'UTF-8') : strtolower((string) $raw);
    if (trim($v) === '') {
        return '';
    }
    foreach (sticpa_pl_rel_types() as $role => $needles) {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && strpos($v, strtolower($needle)) !== false) {
                return $role;
            }
        }
    }
    return '';
}

// ---------------------------------------------------------------------------
// Delegación y caché
// ---------------------------------------------------------------------------

/**
 * La delegación del usuario conectado, que es su `assigned_user_id`.
 *
 * De ahí cuelga el grupo de seguridad del CRM, así que es también el criterio
 * de "lo mío": un monitor solo ve lo de su delegación. Se guarda en sesión
 * porque no cambia mientras dure el login.
 */
function sticpa_pl_delegation($objSCP)
{
    // El login ya la guarda (inc/stic-magic-login.php): no hay que preguntarla.
    if (!empty($_SESSION['scp_user_assigned_user_id'])) {
        return $_SESSION['scp_user_assigned_user_id'];
    }
    if (!empty($_SESSION['scp_pl_delegation'])) {
        return $_SESSION['scp_pl_delegation'];
    }
    // Los flujos de login que no la guardan (los que no pasan por el enlace
    // mágico) sí obligan a preguntarla, una vez por sesión.
    $userId = isset($_SESSION['scp_user_id']) ? $_SESSION['scp_user_id'] : '';
    if (!$userId) {
        return '';
    }
    $module = function_exists('getDestinationModule') ? getDestinationModule() : 'Contacts';
    $detail = $objSCP->getRecordDetail($userId, $module, array('id', 'assigned_user_id'));
    $val = isset($detail->entry_list[0]->name_value_list->assigned_user_id->value)
        ? $detail->entry_list[0]->name_value_list->assigned_user_id->value
        : '';
    $_SESSION['scp_pl_delegation'] = $val;
    return $val;
}

/**
 * Clave de transient. Lleva delegación y curso SIEMPRE.
 *
 * Sin la delegación en la clave, dos delegaciones compartirían caché y el grupo
 * de seguridad del CRM no lo impediría: la caché vive en WordPress.
 */
function sticpa_pl_cache_key($what, $objSCP = null, $extra = '')
{
    $deleg = $objSCP ? sticpa_pl_delegation($objSCP) : (isset($_SESSION['scp_pl_delegation']) ? $_SESSION['scp_pl_delegation'] : 'nodeleg');
    $course = sticpa_pl_course_for();
    $key = 'sticpa_pl_' . $what . '_' . md5($deleg . '|' . $course['label'] . '|' . $extra);
    return $key;
}

/** TTL de la estructura: cambia una vez al año, así que se cachea de verdad. */
function sticpa_pl_ttl_structure()
{
    return (int) apply_filters('sticpa_pl_ttl_structure', 12 * HOUR_IN_SECONDS);
}

/** TTL del estado: cambia cada sábado y se invalida al guardar. */
function sticpa_pl_ttl_state()
{
    return (int) apply_filters('sticpa_pl_ttl_state', 5 * MINUTE_IN_SECONDS);
}

/**
 * Tira la caché. `$scope`:
 *   'state'  → solo asistencias y listas (lo normal tras guardar)
 *   'all'    → también la estructura (el botón de refrescar de la pantalla)
 */
function sticpa_pl_flush($objSCP = null, $scope = 'state')
{
    if (!function_exists('delete_transient')) {
        return;
    }
    delete_transient(sticpa_pl_cache_key('state', $objSCP));
    // Las rachas de ausencias se calculan sobre las asistencias, así que
    // caducan con ellas: guardar una lista puede romper o alargar una racha.
    delete_transient(sticpa_pl_cache_key('streaks', $objSCP));
    if ($scope === 'all') {
        delete_transient(sticpa_pl_cache_key('structure', $objSCP));
        delete_transient(sticpa_pl_cache_key('sessions', $objSCP));
    }
}

// ---------------------------------------------------------------------------
// Lectura: estructura
// ---------------------------------------------------------------------------

/**
 * Los grupos de la delegación, con etapa, curso escolar y código.
 *
 * Se filtra por `assigned_user_id`, que es lo que marca la delegación, y por
 * nada más: el registro del grupo no tiene ningún campo que diga de qué año
 * académico es, así que no hay por dónde filtrarlo aquí. Lo que sí tiene año es
 * la RELACIÓN de cada persona con el grupo (`start_date` / `end_date`), y de eso
 * se encarga sticpa_pl_group_people().
 *
 * Si algún día molestan los grupos que ya no tienen a nadie, el recuento que
 * deja cada noche el Guardián (`ajmcm_n_participantes_c`) permite esconderlos
 * sin una consulta por grupo. Hoy no molestan: en Castellón los 27 grupos de la
 * delegación son los del curso.
 */
function sticpa_pl_groups($objSCP)
{
    $cacheKey = sticpa_pl_cache_key('structure', $objSCP);
    $ttl = sticpa_pl_ttl_structure();
    if ($ttl > 0) {
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $deleg = sticpa_pl_delegation($objSCP);
    if (!$deleg) {
        return array();
    }

    $query = "ajmcm_grupos.assigned_user_id = '" . sticpa_pl_safe_id($deleg) . "'";

    // `ajmcm_segmento_com_c` puede no existir todavía. La API devuelve un error
    // si se pide un campo inexistente, así que se pide aparte y se cae con
    // elegancia: sin segmento, el alcance por etapa sigue funcionando.
    $fields = array('id', 'name', 'code', 'level', 'cursos_c');
    if (sticpa_pl_has_segmento()) {
        $fields[] = 'ajmcm_segmento_com_c';
    }
    $rows = $objSCP->getRecordsModule('ajmcm_GRUPOS', $query, $fields);

    $groups = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $v = $row->name_value_list;
            $id = isset($v->id->value) ? $v->id->value : '';
            if (!$id) {
                continue;
            }
            // `cursos_c` es el CURSO ESCOLAR del grupo ("1º ESO", "Adultos"),
            // que es lo que se enseña en la línea de datos. NO es el año
            // académico: aquí no se filtra por él porque no hay ningún campo del
            // grupo que lo lleve. Qué gente está este curso lo dice la vigencia
            // de sus relaciones, y eso lo resuelve sticpa_pl_group_people().
            $cursos = isset($v->cursos_c->value) ? (string) $v->cursos_c->value : '';
            $label = sticpa_pl_group_label(
                isset($v->code->value) ? $v->code->value : '',
                isset($v->name->value) ? $v->name->value : ''
            );
            $level = isset($v->level->value) ? (string) $v->level->value : '';
            $groups[$id] = array(
                'id' => $id,
                'code' => $label['code'],
                'name' => $label['name'],
                'level' => $level,
                // Etapa y segmento resueltos aquí y no en cada pantalla: son lo
                // que decide el alcance de coordinación, y resolverlos en tres
                // sitios acaba con tres reglas distintas.
                'etapa' => sticpa_pl_group_etapa($level),
                'segmento' => isset($v->ajmcm_segmento_com_c->value)
                    ? trim((string) $v->ajmcm_segmento_com_c->value) : '',
                'cursos' => $cursos,
            );
        }
    }

    if ($ttl > 0) {
        set_transient($cacheKey, $groups, $ttl);
    }
    return $groups;
}

/**
 * Participantes y monitores de un grupo, en UNA sola llamada.
 *
 * Es la consulta que hace viable el modelo de un evento por etapa: la
 * pertenencia al grupo está en `stic_Contacts_Relationships`, y pidiendo las
 * relaciones del grupo con el enlace a Contacts poblado vuelven las personas y
 * su papel de golpe. Sin bucle por persona.
 *
 * Devuelve array('participants' => [...], 'monitors' => [...]), cada persona con
 * id, nombre, iniciales y los campos de la ficha que se usan en la lista.
 */
function sticpa_pl_group_people($objSCP, $groupId)
{
    $groupId = (string) $groupId;
    if ($groupId === '') {
        return array('participants' => array(), 'monitors' => array());
    }

    $cacheKey = sticpa_pl_cache_key('people', $objSCP, $groupId);
    $ttl = sticpa_pl_ttl_structure();
    if ($ttl > 0) {
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $rels = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'ajmcm_GRUPOS',
        'module_id' => $groupId,
        'link_field_name' => 'ajmcm_grupos_stic_contacts_relationships',
        'related_fields' => array('id', 'relationship_type', 'start_date', 'end_date'),
        // El enlace a Contacts poblado: aquí está el ahorro de llamadas.
        'related_module_link_name_to_fields_array' => array(
            array(
                'name' => 'stic_contacts_relationships_contacts',
                'value' => array('id', 'first_name', 'last_name', 'name', 'birthdate', 'stic_age_c', 'phone_mobile'),
            ),
        ),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));

    $out = array('participants' => array(), 'monitors' => array());
    $now = sticpa_pl_now();

    if (is_array($rels)) {
        foreach ($rels as $rel) {
            $rv = isset($rel->name_value_list) ? $rel->name_value_list : null;
            if (!$rv) {
                continue;
            }
            $role = sticpa_pl_rel_role(isset($rv->relationship_type->value) ? $rv->relationship_type->value : '');
            if ($role === '') {
                continue;
            }
            // Vigencia: una relación terminada no sale en la lista de hoy, pero
            // sigue existiendo para el histórico (por eso no se borra).
            $end = isset($rv->end_date->value) ? trim((string) $rv->end_date->value) : '';
            if ($end !== '') {
                $endTs = strtotime($end . ' 23:59:59');
                if ($endTs && $endTs < $now) {
                    continue;
                }
            }

            $person = sticpa_pl_person_from_rel($rel);
            if ($person === null) {
                continue;
            }
            $bucket = ($role === 'monitor') ? 'monitors' : 'participants';
            $out[$bucket][$person['id']] = $person;
        }
    }

    // Alfabético por apellido, que es como se lee una lista de clase.
    foreach (array('participants', 'monitors') as $b) {
        $out[$b] = array_values($out[$b]);
        usort($out[$b], 'sticpa_pl_cmp_person');
    }

    if ($ttl > 0) {
        set_transient($cacheKey, $out, $ttl);
    }
    return $out;
}

/** Orden de personas: apellido y luego nombre. */
function sticpa_pl_cmp_person($a, $b)
{
    $x = isset($a['sort']) ? $a['sort'] : '';
    $y = isset($b['sort']) ? $b['sort'] : '';
    return strcmp($x, $y);
}

/**
 * Saca la persona del bloque de relaciones que devuelve get_relationships.
 *
 * La respuesta de la API v4.1 anida el enlace en `link_list → records →
 * link_value`, y el formato varía según haya uno o varios registros, así que se
 * recorre con cuidado en vez de asumir una forma.
 */
function sticpa_pl_person_from_rel($rel)
{
    $links = isset($rel->link_list) ? $rel->link_list : array();
    foreach ((array) $links as $link) {
        if (!isset($link->records) || !is_array($link->records)) {
            continue;
        }
        foreach ($link->records as $record) {
            $lv = isset($record->link_value) ? $record->link_value : null;
            if (!$lv || empty($lv->id->value)) {
                continue;
            }
            $first = isset($lv->first_name->value) ? trim((string) $lv->first_name->value) : '';
            $last = isset($lv->last_name->value) ? trim((string) $lv->last_name->value) : '';
            $full = trim($first . ' ' . $last);
            if ($full === '' && isset($lv->name->value)) {
                $full = trim((string) $lv->name->value);
            }
            return array(
                'id' => $lv->id->value,
                'name' => $full,
                'first' => $first,
                'last' => $last,
                'sort' => sticpa_pl_sort_key($last, $first),
                'initials' => sticpa_pl_initials($first, $last, $full),
                'age' => isset($lv->stic_age_c->value) ? (string) $lv->stic_age_c->value : '',
                'birthdate' => isset($lv->birthdate->value) ? (string) $lv->birthdate->value : '',
                'mobile' => isset($lv->phone_mobile->value) ? (string) $lv->phone_mobile->value : '',
            );
        }
    }
    return null;
}

/** Clave de ordenación sin acentos, para que Álvarez no acabe tras Zamora. */
function sticpa_pl_sort_key($last, $first)
{
    $s = trim($last . ' ' . $first);
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($ascii !== false) {
            $s = $ascii;
        }
    }
    return $s;
}

/** Iniciales para el avatar. Dos letras, y una si no hay apellido. */
function sticpa_pl_initials($first, $last, $full = '')
{
    $pick = function ($v) {
        $v = trim((string) $v);
        if ($v === '') {
            return '';
        }
        return function_exists('mb_substr')
            ? mb_strtoupper(mb_substr($v, 0, 1, 'UTF-8'), 'UTF-8')
            : strtoupper(substr($v, 0, 1));
    };
    $a = $pick($first);
    $b = $pick($last);
    if ($a === '' && $b === '' && $full !== '') {
        $parts = preg_split('/\s+/u', trim($full));
        $a = $pick(isset($parts[0]) ? $parts[0] : '');
        $b = $pick(isset($parts[1]) ? $parts[1] : '');
    }
    return $a . $b;
}

// ---------------------------------------------------------------------------
// Lectura: sesiones
// ---------------------------------------------------------------------------

/**
 * Las sesiones del evento de una etapa en el curso actual.
 *
 * Ojo con el `name` de la sesión: el CRM lo genera al crearla y NO lo refresca
 * si luego cambian las fechas, y además arrastra un desajuste de zona horaria.
 * Así que la pantalla formatea `start_date` / `end_date` y no usa `name` nunca.
 */
function sticpa_pl_event_sessions($objSCP, $eventId)
{
    $eventId = (string) $eventId;
    if ($eventId === '') {
        return array();
    }

    $cacheKey = sticpa_pl_cache_key('sessions', $objSCP, $eventId);
    $ttl = sticpa_pl_ttl_structure();
    if ($ttl > 0) {
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'stic_Events',
        'module_id' => $eventId,
        'link_field_name' => 'stic_sessions_stic_events',
        'related_fields' => array('id', 'start_date', 'end_date'),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));

    $sessions = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $v = $row->name_value_list;
            $id = isset($v->id->value) ? $v->id->value : '';
            $start = isset($v->start_date->value) ? (string) $v->start_date->value : '';
            if (!$id || $start === '') {
                continue;
            }
            $endRaw = isset($v->end_date->value) ? (string) $v->end_date->value : '';
            $sessions[] = array(
                'id' => $id,
                'start' => sticpa_pl_ts($start),
                'end' => $endRaw !== '' ? sticpa_pl_ts($endRaw) : 0,
            );
        }
    }
    usort($sessions, 'sticpa_pl_cmp_start');

    if ($ttl > 0) {
        set_transient($cacheKey, $sessions, $ttl);
    }
    return $sessions;
}

/**
 * Fecha del CRM a timestamp.
 *
 * El CRM devuelve `Y-m-d H:i:s`. Las fechas se enviaron en hora local (mandar
 * ISO con desplazamiento hace que la API lo ignore y ponga la hora actual), así
 * que se interpretan en la zona de WordPress y no en UTC.
 */
function sticpa_pl_ts($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    $ts = strtotime($value);
    return $ts ? (int) $ts : 0;
}

// ---------------------------------------------------------------------------
// Lectura: estado (asistencias y listas)
// ---------------------------------------------------------------------------

/**
 * Sólo un id del CRM, para interpolarlo en un fragmento de WHERE.
 *
 * La API v4.1 acepta un trozo de SQL en crudo, así que lo que se interpola pasa
 * por una lista blanca: todos los ids del CRM son UUID, y lo que no lo parezca
 * no llega a la consulta. Lista blanca y no escapado, porque aquí no hay ningún
 * caso legítimo con comillas.
 */
function sticpa_pl_safe_id($v)
{
    return preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $v);
}

/**
 * Mapa inscripción → contacto del evento de una etapa.
 *
 * Hace falta porque una asistencia cuelga de la INSCRIPCIÓN, no de la persona,
 * y la API sólo puebla un nivel de enlaces por llamada: desde la sesión se
 * llega a la asistencia y a su inscripción, pero no al contacto de esa
 * inscripción. Con este mapa (una llamada por evento, cacheada como estructura
 * porque las inscripciones del curso no cambian cada sábado) el cruce se hace
 * en memoria y marcar sigue costando dos consultas.
 */
function sticpa_pl_event_registrations($objSCP, $eventId)
{
    $eventId = (string) $eventId;
    if ($eventId === '') {
        return array();
    }

    $cacheKey = sticpa_pl_cache_key('regs', $objSCP, $eventId);
    $ttl = sticpa_pl_ttl_structure();
    if ($ttl > 0) {
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'stic_Events',
        'module_id' => $eventId,
        'link_field_name' => 'stic_registrations_stic_events',
        'related_fields' => array('id', 'status'),
        'related_module_link_name_to_fields_array' => array(
            array('name' => 'stic_registrations_contacts', 'value' => array('id')),
        ),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));

    $map = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $v = isset($row->name_value_list) ? $row->name_value_list : null;
            if (!$v || empty($v->id->value)) {
                continue;
            }
            $status = isset($v->status->value) ? (string) $v->status->value : '';
            if ($status === 'cancelled') {
                continue;
            }
            $contactId = sticpa_pl_link_id($row);
            if ($contactId === '') {
                continue;
            }
            $map[$v->id->value] = $contactId;
        }
    }

    if ($ttl > 0) {
        set_transient($cacheKey, $map, $ttl);
    }
    return $map;
}

/**
 * Asistencias de una sesión: array contactId => array('id','status').
 *
 * Una llamada por sesión, nunca una por participante. Las asistencias las crea
 * el CRM al crear la inscripción, así que normalmente ya existen y marcar es
 * actualizar; si falta alguna, sticpa_pl_save la crea.
 *
 * $regMap viene de sticpa_pl_event_registrations(): inscripción → contacto.
 */
function sticpa_pl_session_attendances($objSCP, $sessionId, $regMap = array())
{
    $sessionId = (string) $sessionId;
    if ($sessionId === '') {
        return array();
    }

    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'stic_Sessions',
        'module_id' => $sessionId,
        'link_field_name' => 'stic_attendances_stic_sessions',
        // `description` es donde vive el motivo de la ausencia: se trae con el
        // estado para que la hoja lo enseñe al abrirse en vez de aparecer vacía
        // sobre un motivo que ya estaba escrito.
        'related_fields' => array('id', 'status', 'description'),
        // La asistencia cuelga de la INSCRIPCIÓN: se trae su id y el contacto
        // se resuelve con $regMap, porque la API no puebla dos niveles.
        'related_module_link_name_to_fields_array' => array(
            array('name' => 'stic_attendances_stic_registrations', 'value' => array('id')),
        ),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));

    $out = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $v = isset($row->name_value_list) ? $row->name_value_list : null;
            if (!$v || empty($v->id->value)) {
                continue;
            }
            $status = isset($v->status->value) ? (string) $v->status->value : '';
            $regId = sticpa_pl_link_id($row);
            if ($regId === '' || !isset($regMap[$regId])) {
                continue;   // asistencia sin inscripción conocida: no es de nadie de este curso
            }
            $out[$regMap[$regId]] = array(
                'id' => $v->id->value,
                'status' => sticpa_pl_is_state($status) ? $status : '',
                'description' => isset($v->description->value) ? (string) $v->description->value : '',
                'registration_id' => $regId,
            );
        }
    }
    return $out;
}

/**
 * Ausencias seguidas de cada participante, hasta la sesión que se está marcando.
 *
 * El aviso de "3 ausencias seguidas" es lo que hace que la lista sirva para
 * algo más que registrar. El problema es el coste: el histórico completo de un
 * participante es una consulta POR PARTICIPANTE, y once llamadas al abrir la
 * pantalla más usada de la aplicación no se pagan con un aviso.
 *
 * Así que se da la vuelta a la consulta: en vez de preguntar por persona, se
 * preguntan las asistencias de las últimas `umbral` SESIONES celebradas —tres
 * llamadas, las mismas tanto para un grupo de 8 como de 30— y de ahí sale la
 * racha de todos a la vez.
 *
 * Mirar solo hasta el umbral es a propósito: el aviso salta al llegar a tres, y
 * saber que van cinco no cambia lo que hay que hacer. Se devuelve el umbral
 * como techo, que es lo que la fila necesita para decidir si avisa.
 *
 * Una sesión SIN marcar corta la racha, igual que en sticpa_pl_absence_streak():
 * un hueco en los datos no es una falta y no se acusa a nadie por él.
 *
 * La sesión que se está marcando se EXCLUYE: lo que se acaba de tocar en
 * pantalla no puede contar en un aviso que se pinta a la vez.
 */
function sticpa_pl_group_streaks($objSCP, $sessions, $currentSessionId, $regMap = array())
{
    $threshold = sticpa_pl_streak_threshold();
    if ($threshold < 1) {
        return array();
    }

    // Solo lo ya celebrado y sin la sesión que se está marcando.
    $elapsed = array();
    foreach (sticpa_pl_elapsed_sessions($sessions) as $s) {
        if ($s['id'] !== (string) $currentSessionId) {
            $elapsed[] = $s;
        }
    }
    // Las `umbral` últimas, de la más reciente hacia atrás.
    $look = array_reverse(array_slice($elapsed, -1 * $threshold));
    if (empty($look)) {
        return array();
    }

    // La firma va DENTRO del valor y no en la clave: así la clave es fija y
    // sticpa_pl_flush('state') la puede tirar al guardar, igual que el resto
    // del estado. Con la firma en la clave, cada grupo dejaría su propia
    // entrada y ninguna se invalidaría al guardar una lista.
    $ids = array();
    foreach ($look as $s) {
        $ids[] = $s['id'];
    }
    $sig = md5(implode(',', $ids) . '|' . implode(',', array_keys((array) $regMap)));
    $cacheKey = sticpa_pl_cache_key('streaks', $objSCP);
    $ttl = sticpa_pl_ttl_state();
    if ($ttl > 0) {
        $cached = get_transient($cacheKey);
        if (is_array($cached) && isset($cached['sig'], $cached['data']) && $cached['sig'] === $sig) {
            return $cached['data'];
        }
    }

    $states = sticpa_pl_states();
    $streaks = array();
    $closed = array();      // a quien ya se le ha cortado la racha no se le suma más

    foreach ($look as $s) {
        $att = sticpa_pl_session_attendances($objSCP, $s['id'], $regMap);
        foreach ((array) $regMap as $contactId) {
            if (isset($closed[$contactId])) {
                continue;
            }
            $key = isset($att[$contactId]['status']) ? $att[$contactId]['status'] : '';
            if (!sticpa_pl_is_state($key) || !$states[$key]['absence']) {
                // Vino, o no hay dato: en los dos casos la racha se acaba aquí.
                $closed[$contactId] = true;
                continue;
            }
            $streaks[$contactId] = (isset($streaks[$contactId]) ? $streaks[$contactId] : 0) + 1;
        }
    }

    if ($ttl > 0) {
        set_transient($cacheKey, array('sig' => $sig, 'data' => $streaks), $ttl);
    }
    return $streaks;
}

/**
 * La lista de un grupo en una sesión: null si no hay registro.
 *
 * `LIS_listas` es lo que distingue "no ha venido nadie" de "nadie ha pasado
 * lista", que en un modelo de un evento compartido por todos los grupos no se
 * puede deducir de las asistencias.
 */
function sticpa_pl_lista($objSCP, $sessionId, $groupId)
{
    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'stic_Sessions',
        'module_id' => (string) $sessionId,
        'link_field_name' => 'lis_listas_stic_sessions',
        'related_fields' => array('id', 'estado', 'pasada_el', 'n_asistieron', 'n_faltaron'),
        'related_module_link_name_to_fields_array' => array(
            array('name' => 'lis_listas_ajmcm_grupos', 'value' => array('id')),
        ),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));

    if (!is_array($rows)) {
        return null;
    }
    foreach ($rows as $row) {
        $v = isset($row->name_value_list) ? $row->name_value_list : null;
        if (!$v || empty($v->id->value)) {
            continue;
        }
        if (sticpa_pl_link_id($row) !== (string) $groupId) {
            continue;
        }
        return array(
            'id' => $v->id->value,
            'estado' => isset($v->estado->value) ? (string) $v->estado->value : '',
            'pasada_el' => isset($v->pasada_el->value) ? (string) $v->pasada_el->value : '',
            'n_asistieron' => isset($v->n_asistieron->value) ? (int) $v->n_asistieron->value : 0,
            'n_faltaron' => isset($v->n_faltaron->value) ? (int) $v->n_faltaron->value : 0,
        );
    }
    return null;
}

/** Primer id que aparece en el bloque de enlaces de un registro. */
function sticpa_pl_link_id($row)
{
    $links = isset($row->link_list) ? $row->link_list : array();
    foreach ((array) $links as $link) {
        if (!isset($link->records) || !is_array($link->records)) {
            continue;
        }
        foreach ($link->records as $record) {
            if (!empty($record->link_value->id->value)) {
                return (string) $record->link_value->id->value;
            }
        }
    }
    return '';
}

// ---------------------------------------------------------------------------
// Escritura
// ---------------------------------------------------------------------------

/**
 * Guarda la lista de un grupo en una sesión, DE GOLPE.
 *
 * $marks: array contactId => clave de estado ('' = sin marcar, no se escribe).
 *
 * Se guarda todo junto y no marca por marca a propósito: es lo que permite que
 * la pantalla funcione sin cobertura (se marca, se guarda al recuperar red) y
 * lo que evita dejar media lista escrita si algo falla a mitad.
 *
 * Devuelve array('saved','failed','lista_id','counts').
 */
function sticpa_pl_save($objSCP, $sessionId, $groupId, $marks, $omitida = false, $regMap = array(), $notes = array())
{
    $sessionId = (string) $sessionId;
    $groupId = (string) $groupId;
    $result = array('saved' => 0, 'failed' => 0, 'lista_id' => '', 'counts' => array('yes' => 0, 'no' => 0));
    if ($sessionId === '' || $groupId === '') {
        return $result;
    }

    $states = sticpa_pl_states();
    $estados = sticpa_pl_lista_estados();

    if (!$omitida) {
        $existing = sticpa_pl_session_attendances($objSCP, $sessionId, $regMap);

        foreach ((array) $marks as $contactId => $key) {
            $key = (string) $key;
            if ($key === '' || !sticpa_pl_is_state($key)) {
                continue;   // sin marcar no se escribe: un hueco no es una falta
            }
            if ($states[$key]['counts']) {
                $result['counts']['yes']++;
            } else {
                $result['counts']['no']++;
            }

            // El motivo. Solo se escribe si CAMBIA: mandarlo igual en cada
            // guardado ensucia el registro de auditoría del CRM con cambios que
            // no son cambios. Una cadena vacía sí se escribe cuando antes había
            // algo, porque borrar el motivo es una acción deliberada.
            $note = isset($notes[$contactId]) ? (string) $notes[$contactId] : '';

            if (isset($existing[$contactId]['id'])) {
                $payloadAtt = array(
                    'id' => $existing[$contactId]['id'],
                    'status' => $key,
                );
                $before = isset($existing[$contactId]['description'])
                    ? (string) $existing[$contactId]['description'] : '';
                if ($note !== $before) {
                    $payloadAtt['description'] = $note;
                }
                $ok = $objSCP->set_entry('stic_Attendances', $payloadAtt);
                if ($ok) {
                    $result['saved']++;
                } else {
                    $result['failed']++;
                }
                continue;
            }

            // No había asistencia para esta persona en esta sesión. Pasa cuando
            // se inscribe a alguien después de crear el evento: el CRM genera
            // las asistencias al crear la inscripción, no hacia atrás.
            $newAtt = array(
                'status' => $key,
                'assigned_user_id' => sticpa_pl_delegation($objSCP),
            );
            if ($note !== '') {
                $newAtt['description'] = $note;
            }
            $newId = $objSCP->set_entry('stic_Attendances', $newAtt);
            if (!$newId) {
                $result['failed']++;
                continue;
            }
            $objSCP->set_relationship('stic_Attendances', $newId, 'stic_attendances_stic_sessions', array($sessionId));
            // Sin la inscripción detrás, la asistencia queda huérfana y el CRM
            // no la cuenta en el porcentaje de la inscripción.
            $regId = array_search($contactId, (array) $regMap, true);
            if ($regId !== false) {
                $objSCP->set_relationship('stic_Attendances', $newId, 'stic_attendances_stic_registrations', array($regId));
            }
            $result['saved']++;
        }
    }

    // La lista: se crea o se actualiza, nunca se duplica.
    $lista = sticpa_pl_lista($objSCP, $sessionId, $groupId);
    $payload = array(
        'estado' => $omitida ? $estados['omitida'] : $estados['pasada'],
        'pasada_el' => date('Y-m-d H:i:s', sticpa_pl_now()),
        'n_asistieron' => $result['counts']['yes'],
        'n_faltaron' => $result['counts']['no'],
        'assigned_user_id' => sticpa_pl_delegation($objSCP),
    );
    if ($lista !== null) {
        $payload['id'] = $lista['id'];
        $listaId = $objSCP->set_entry('LIS_listas', $payload);
        $result['lista_id'] = $listaId ? $listaId : $lista['id'];
    } else {
        $listaId = $objSCP->set_entry('LIS_listas', $payload);
        if ($listaId) {
            $objSCP->set_relationship('LIS_listas', $listaId, 'lis_listas_stic_sessions', array($sessionId));
            $objSCP->set_relationship('LIS_listas', $listaId, 'lis_listas_ajmcm_grupos', array($groupId));
            $who = isset($_SESSION['scp_user_id']) ? $_SESSION['scp_user_id'] : '';
            if ($who) {
                $objSCP->set_relationship('LIS_listas', $listaId, 'lis_listas_contacts', array($who));
            }
        }
        $result['lista_id'] = $listaId ? $listaId : '';
    }

    sticpa_pl_flush($objSCP, 'state');
    return $result;
}

// ---------------------------------------------------------------------------
// Eventos por etapa
// ---------------------------------------------------------------------------

/**
 * Prefijos con los que se reconoce la etapa en el nombre del evento.
 *
 * ⚠️ PUNTO FLOJO CONOCIDO. El evento no tiene campo de etapa, así que la etapa
 * se saca del nombre ("COM | Sesiones semanales 2025-2026"). Funciona porque la
 * convención de nombres está fijada (ver el roadmap), pero un evento mal
 * nombrado desaparece de Pasar Lista sin decir por qué.
 *
 * YA NO ES EL CAMINO PRINCIPAL: la etapa se lee del campo del evento (ver
 * `sticpa_pl_event_etapa_field()`). Esto queda como red de seguridad para los
 * eventos a los que nadie haya rellenado el campo todavía, y sigue siendo
 * filtrable para una delegación con otra convención de nombres.
 */
function sticpa_pl_etapa_prefixes()
{
    return apply_filters('sticpa_pl_etapa_prefixes', array(
        'MIC' => array('mic'),
        'COM' => array('com'),
        'LC' => array('lc'),
    ));
}

/**
 * El campo de `stic_Events` que dice a qué etapas sirve el evento.
 *
 * Es de SELECCIÓN MÚLTIPLE: un mismo evento puede ser de MIC y de COM a la vez,
 * y entonces aparece en las dos etapas. Es lo normal en una delegación pequeña,
 * donde los sábados son los mismos para todos.
 */
function sticpa_pl_event_etapa_field()
{
    return apply_filters('sticpa_pl_event_etapa_field', 'ajmcm_etapa_c');
}

/**
 * Las etapas de un campo de selección múltiple, normalizadas a MIC/COM/LC.
 *
 * SuiteCRM guarda los multiselect como `^MIC^,^COM^`. La API los devuelve así
 * tal cual unas veces y como array otras, según el módulo, así que se aceptan
 * las dos formas y también la lista separada por comas a secas.
 *
 * Las claves se comparan contra el mismo mapa que los prefijos del nombre, de
 * modo que da igual si en el desplegable están como `MIC` o como `mic`.
 */
function sticpa_pl_etapas_from_multi($raw)
{
    if (is_object($raw)) {
        $raw = (array) $raw;
    }
    $parts = is_array($raw)
        ? $raw
        : explode(',', str_replace('^', '', (string) $raw));

    $map = array();
    foreach (sticpa_pl_etapa_prefixes() as $etapa => $needles) {
        $map[strtolower($etapa)] = $etapa;
        foreach ((array) $needles as $needle) {
            if ($needle !== '') {
                $map[strtolower($needle)] = $etapa;
            }
        }
    }

    $out = array();
    foreach ($parts as $part) {
        $key = strtolower(trim(str_replace('^', '', (string) $part)));
        if ($key === '' || !isset($map[$key])) {
            continue;
        }
        // Sin duplicados y en el orden en que vienen: si alguien marca MIC y COM
        // dos veces, la pantalla no tiene por qué enterarse.
        if (!in_array($map[$key], $out, true)) {
            $out[] = $map[$key];
        }
    }
    return $out;
}

/**
 * Los eventos de sesiones semanales de la delegación en el curso actual,
 * indexados por etapa: array('COM' => array('id','name'), …).
 *
 * Se filtra por `assigned_user_id` (la delegación) y por el curso en el nombre.
 * Una llamada, cacheada como estructura.
 */
function sticpa_pl_etapa_events($objSCP)
{
    $cacheKey = sticpa_pl_cache_key('events', $objSCP);
    $ttl = sticpa_pl_ttl_structure();
    if ($ttl > 0) {
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $deleg = sticpa_pl_delegation($objSCP);
    if (!$deleg) {
        return array();
    }
    $course = sticpa_pl_course_for();
    $etapaField = sticpa_pl_event_etapa_field();

    $rows = $objSCP->getRecordsModule(
        'stic_Events',
        "stic_events.assigned_user_id = '" . sticpa_pl_safe_id($deleg) . "'",
        array('id', 'name', 'start_date', 'end_date', $etapaField)
    );

    $out = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $v = $row->name_value_list;
            $id = isset($v->id->value) ? $v->id->value : '';
            $name = isset($v->name->value) ? (string) $v->name->value : '';
            if (!$id || $name === '') {
                continue;
            }
            if (strpos($name, $course['label']) === false) {
                continue;   // otro curso
            }

            // El campo manda. El nombre solo se mira si el campo está vacío,
            // que es lo que pasa con los eventos creados antes de que existiera.
            $etapas = isset($v->$etapaField->value)
                ? sticpa_pl_etapas_from_multi($v->$etapaField->value)
                : array();
            if (empty($etapas)) {
                $fromName = sticpa_pl_etapa_from_name($name);
                if ($fromName === '') {
                    continue;
                }
                $etapas = array($fromName);
            }

            foreach ($etapas as $etapa) {
                // Si hubiera dos del mismo curso y etapa, gana el primero: es un
                // problema de datos, no de la pantalla.
                if (!isset($out[$etapa])) {
                    $out[$etapa] = array('id' => $id, 'name' => $name);
                }
            }
        }
    }

    if ($ttl > 0) {
        set_transient($cacheKey, $out, $ttl);
    }
    return $out;
}

/** La etapa que anuncia el nombre de un evento, o '' si no la anuncia. */
function sticpa_pl_etapa_from_name($name)
{
    // Solo se mira lo que hay ANTES del "|": "COM | Sesiones…" es COM, pero
    // "Convivencia de familias del COM" no debe colarse como evento de etapa.
    $head = $name;
    $bar = strpos($name, '|');
    if ($bar !== false) {
        $head = substr($name, 0, $bar);
    } elseif (strpos($name, '-') !== false) {
        $head = substr($name, 0, strpos($name, '-'));
    }
    $head = function_exists('mb_strtolower') ? mb_strtolower(trim($head), 'UTF-8') : strtolower(trim($head));

    foreach (sticpa_pl_etapa_prefixes() as $etapa => $needles) {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && $head === strtolower($needle)) {
                return $etapa;
            }
        }
    }
    return '';
}

/**
 * La etapa de un grupo, a partir de su `level`.
 *
 * `level` es un enum del CRM cuyas opciones la API no expone, así que se
 * compara por subcadena: un level "com" o "COM-LC" cae en COM igual.
 */
function sticpa_pl_group_etapa($level)
{
    $v = function_exists('mb_strtolower') ? mb_strtolower((string) $level, 'UTF-8') : strtolower((string) $level);
    if (trim($v) === '') {
        return '';
    }
    foreach (sticpa_pl_etapa_prefixes() as $etapa => $needles) {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && strpos($v, strtolower($needle)) !== false) {
                return $etapa;
            }
        }
    }
    return '';
}

/**
 * Los grupos en los que el usuario conectado es MONITOR.
 *
 * Es lo que permite el atajo de la home y el "Tu grupo" del árbol. No limita
 * nada: un monitor puede pasar lista de cualquier grupo de su delegación,
 * porque a veces uno no está y le cubre otro.
 */
function sticpa_pl_my_groups($objSCP)
{
    $userId = isset($_SESSION['scp_user_id']) ? $_SESSION['scp_user_id'] : '';
    if (!$userId) {
        return array();
    }

    $cacheKey = sticpa_pl_cache_key('mygroups', $objSCP, $userId);
    $ttl = sticpa_pl_ttl_structure();
    if ($ttl > 0) {
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $rels = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'Contacts',
        'module_id' => $userId,
        'link_field_name' => 'stic_contacts_relationships_contacts',
        'related_fields' => array('id', 'relationship_type', 'end_date'),
        'related_module_link_name_to_fields_array' => array(
            array('name' => 'ajmcm_grupos_stic_contacts_relationships', 'value' => array('id')),
        ),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));

    $ids = array();
    $now = sticpa_pl_now();
    if (is_array($rels)) {
        foreach ($rels as $rel) {
            $v = isset($rel->name_value_list) ? $rel->name_value_list : null;
            if (!$v) {
                continue;
            }
            if (sticpa_pl_rel_role(isset($v->relationship_type->value) ? $v->relationship_type->value : '') !== 'monitor') {
                continue;
            }
            $end = isset($v->end_date->value) ? trim((string) $v->end_date->value) : '';
            if ($end !== '') {
                $endTs = strtotime($end . ' 23:59:59');
                if ($endTs && $endTs < $now) {
                    continue;
                }
            }
            $gid = sticpa_pl_link_id($rel);
            if ($gid !== '') {
                $ids[$gid] = true;
            }
        }
    }
    $ids = array_keys($ids);

    if ($ttl > 0) {
        set_transient($cacheKey, $ids, $ttl);
    }
    return $ids;
}

// ---------------------------------------------------------------------------
// Ficha del participante (fase 2)
// ---------------------------------------------------------------------------

/**
 * Campos de la ficha que se piden al CRM.
 *
 * Todos salen de docs/comunica/CAMPOS.md, que es la fuente de la verdad. Se
 * piden EXPLÍCITAMENTE: sin lista de campos, la API devuelve el módulo entero
 * (unos 200 campos por contacto) y la pantalla se vuelve lenta y cara.
 */
function sticpa_pl_ficha_fields()
{
    return array(
        'id', 'first_name', 'last_name', 'name',
        'birthdate', 'stic_age_c',
        'phone_mobile', 'phone_other', 'email1',
        'ajmcm_etapa_c', 'ajmcm_nivel_com_c', 'ajmcm_panuelo_c', 'ajmcm_tallas_c',
        // Permisos
        'ajmcm_soloacasa_c', 'ajmcm_menorwhatsapp_c', 'ajmcm_cesionimagenes_interne_c', 'ajmcm_datossalud_c',
        // Salud: los cinco campos que en pantalla van en UNA tarjeta
        'ajmcm_descripcion_allergies__c', 'ajmcm_descripcion_intoler_c',
        'ajmcm_descripcion_tratam_c', 'ajmcm_descripcion_enfermed_c',
        'ajmcm_descripcion_otros_c',
    );
}

/** Los valores del pañuelo, tal como están en CAMPOS.md, con su color. */
function sticpa_pl_panuelos()
{
    return array(
        'no' => array('label' => __('No', 'sticpa'), 'color' => '#9ca3af'),
        'rojo' => array('label' => __('Rojo', 'sticpa'), 'color' => '#dc2626'),
        'verde' => array('label' => __('Verde', 'sticpa'), 'color' => '#2f9e44'),
        'azul' => array('label' => __('Azul', 'sticpa'), 'color' => '#1c6fb3'),
        'amarillo' => array('label' => __('Amarillo', 'sticpa'), 'color' => '#f59e0b'),
        'cruz' => array('label' => __('Cruz', 'sticpa'), 'color' => '#6c4b9e'),
        'na' => array('label' => __('Desconocido', 'sticpa'), 'color' => '#d1d5db'),
    );
}

/** La ficha de un participante, en una llamada y solo con lo que se usa. */
function sticpa_pl_ficha($objSCP, $contactId)
{
    $contactId = sticpa_pl_safe_id($contactId);
    if ($contactId === '') {
        return null;
    }
    $detail = $objSCP->getRecordDetail($contactId, 'Contacts', sticpa_pl_ficha_fields());
    if (empty($detail->entry_list[0]->name_value_list)) {
        return null;
    }
    $v = $detail->entry_list[0]->name_value_list;

    $out = array();
    foreach (sticpa_pl_ficha_fields() as $f) {
        $out[$f] = isset($v->$f->value) ? (string) $v->$f->value : '';
    }
    $out['initials'] = sticpa_pl_initials($out['first_name'], $out['last_name'], $out['name']);
    if (trim($out['name']) === '') {
        $out['name'] = trim($out['first_name'] . ' ' . $out['last_name']);
    }
    return $out;
}

/**
 * TODAS las asistencias de un participante en un evento, en UNA llamada.
 *
 * Se piden desde la inscripción, no sesión por sesión: la inscripción tiene
 * todas sus asistencias colgando, y con el enlace a la sesión poblado vuelve
 * también de qué día es cada una. Devuelve array sessionId => clave de estado,
 * que es justo lo que come sticpa_pl_attendance() y sticpa_pl_absence_streak().
 */
function sticpa_pl_contact_marks($objSCP, $registrationId)
{
    $registrationId = sticpa_pl_safe_id($registrationId);
    if ($registrationId === '') {
        return array();
    }

    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'stic_Registrations',
        'module_id' => $registrationId,
        'link_field_name' => 'stic_attendances_stic_registrations',
        'related_fields' => array('id', 'status'),
        'related_module_link_name_to_fields_array' => array(
            array('name' => 'stic_attendances_stic_sessions', 'value' => array('id')),
        ),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));

    $marks = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $v = isset($row->name_value_list) ? $row->name_value_list : null;
            if (!$v) {
                continue;
            }
            $sid = sticpa_pl_link_id($row);
            if ($sid === '') {
                continue;
            }
            $status = isset($v->status->value) ? (string) $v->status->value : '';
            $marks[$sid] = sticpa_pl_is_state($status) ? $status : '';
        }
    }
    return $marks;
}

// ---------------------------------------------------------------------------
// Avisos de comportamiento (AVI_avisos)
// ---------------------------------------------------------------------------

/**
 * Los nombres del módulo de avisos, en UN sitio.
 *
 * Verificado contra el CRM con `get_module_fields` (el módulo ya está creado).
 * Dos cosas salieron distintas de la especificación de
 * `docs/comunica/PASAR-LISTA-CAMPOS-CRM.md` §6:
 *
 * - `f_puesto_por_id`: el campo relate `ajmcm_puesto_por_c` guarda el id en un
 *   campo aparte, y Studio lo llamó `contact_id_c` (mismo patrón que
 *   `stic_sessions_id_c` para el relate `ajmcm_sesion_c`) — no
 *   `ajmcm_puesto_por_c_id`, que era la suposición razonable antes de mirarlo.
 * - `ajmcm_notificado_el_c` (cuándo se avisó a la familia) **no se creó**. Solo
 *   existe el booleano `ajmcm_notificado_familia_c`. El código ya no lee ni
 *   escribe una fecha que no existe (ver `sticpa_pl_create_aviso()` y
 *   `sticpa_pl_avisos()`); si algún día se crea el campo, se añade aquí y en
 *   esas dos funciones, y ya.
 */
function sticpa_pl_avi_map()
{
    return apply_filters('sticpa_pl_avi_map', array(
        'module' => 'AVI_avisos',
        'link_contacts' => 'avi_avisos_contacts',
        'f_name' => 'name',
        'f_date' => 'fecha',
        'f_motivo' => 'motivo',
        'f_puesto_por' => 'ajmcm_puesto_por_c',
        'f_puesto_por_id' => 'contact_id_c',
        'f_sesion' => 'ajmcm_sesion_c',
        'f_notificado' => 'ajmcm_notificado_familia_c',
    ));
}

/**
 * Si el módulo de avisos está disponible.
 *
 * Se puede apagar por delegación con el filtro. Mientras el módulo no exista en
 * el CRM, `sticpa_pl_avisos()` vuelve vacío por sí solo (la API no encuentra el
 * enlace y no devuelve filas), así que la ficha no se rompe: simplemente no
 * enseña la sección. Poner el filtro en `false` ahorra además la consulta.
 */
function sticpa_pl_avisos_enabled()
{
    return (bool) apply_filters('sticpa_pl_avisos_enabled', true);
}

/**
 * Cuántos avisos hacen falta para salir del grupo. Filtrable por delegación.
 */
function sticpa_pl_avisos_limite()
{
    return max(1, (int) apply_filters('sticpa_pl_avisos_limite', 3));
}

/**
 * El color de cada aviso según su NÚMERO. La escala sube: el tercero es la
 * salida del grupo y se tiene que ver venir desde el primero.
 *
 * Son hex fijos y a propósito: no son colores de marca ni de estado, son una
 * escala de gravedad propia de esta sección, y tienen que ser los mismos en
 * claro y en oscuro para que "el naranja" signifique siempre lo mismo.
 * (`docs/comunica/PASAR-LISTA-CAMPOS-CRM.md` §6.)
 */
function sticpa_pl_aviso_color($num)
{
    // El naranja del 2 es #c2410c y no el #ea580c de la especificación: con
    // #ea580c el número blanco de dentro del círculo se quedaba en 3,6:1, por
    // debajo del AA que necesita un texto pequeño. #c2410c lo sube a 5,2:1 y a
    // ojo es el mismo naranja. La progresión ámbar → naranja → rojo no cambia.
    $escala = apply_filters('sticpa_pl_aviso_escala', array('#f59e0b', '#c2410c', '#dc2626'));
    $num = (int) $num;
    if ($num < 1) {
        return $escala[0];
    }
    // Por encima del último tramo se queda en el último: un cuarto aviso no
    // necesita un color nuevo, ya está en lo más grave.
    $i = min($num, count($escala)) - 1;
    return $escala[$i];
}

/**
 * El color del NÚMERO que va dentro del círculo de un aviso.
 *
 * Sobre el ámbar el blanco se queda en 2,2:1 —ilegible—, así que ahí el número
 * va en marrón oscuro (7,0:1). Sobre el naranja y el rojo, blanco (5,2:1). El relleno
 * es un hex fijo, así que el texto de encima también: los dos tienen que ser
 * iguales en claro y en oscuro.
 */
function sticpa_pl_aviso_ink($num)
{
    // Solo el primer tramo es claro; del segundo en adelante el relleno ya es
    // oscuro y aguanta el blanco.
    return ((int) $num <= 1) ? '#451a03' : '#fff';
}

/**
 * Los avisos de un participante EN EL CURSO ACTUAL, numerados.
 *
 * El número (1, 2, 3) NO se guarda: sale de ordenar por fecha y contar. Es lo
 * que hace que retirar el aviso de en medio renumere los siguientes en vez de
 * dejar un participante con un «1» y un «3» y ningún «2».
 *
 * El curso se filtra por fecha, que es también por lo que el módulo no lleva
 * campo de curso: al cambiar de curso los avisos no se borran, se quedan atrás.
 */
function sticpa_pl_avisos($objSCP, $contactId)
{
    if (!sticpa_pl_avisos_enabled()) {
        return array();
    }
    $contactId = sticpa_pl_safe_id($contactId);
    if ($contactId === '') {
        return array();
    }

    $map = sticpa_pl_avi_map();
    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'Contacts',
        'module_id' => $contactId,
        'link_field_name' => $map['link_contacts'],
        'related_fields' => array(
            'id',
            $map['f_name'],
            $map['f_date'],
            $map['f_motivo'],
            $map['f_puesto_por'],
            $map['f_notificado'],
            'date_entered',
        ),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));

    $course = sticpa_pl_course_for();
    $out = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $v = isset($row->name_value_list) ? $row->name_value_list : null;
            if (!$v || empty($v->id->value)) {
                continue;
            }
            // La fecha es obligatoria en el módulo, pero si viniera vacía se
            // cae a la de creación antes que descartar el aviso: un aviso sin
            // fecha sigue siendo un aviso, y esconderlo es lo peor que se
            // puede hacer con él.
            $raw = isset($v->{$map['f_date']}->value) ? (string) $v->{$map['f_date']}->value : '';
            if ($raw === '' && isset($v->date_entered->value)) {
                $raw = (string) $v->date_entered->value;
            }
            $ts = sticpa_pl_ts($raw);
            if (!$ts) {
                continue;
            }
            if ($ts < $course['start'] || $ts > $course['end']) {
                continue;   // de otro curso: es historia, no el recuento de hoy
            }

            $notif = isset($v->{$map['f_notificado']}->value) ? (string) $v->{$map['f_notificado']}->value : '';
            $out[] = array(
                'id' => $v->id->value,
                'ts' => $ts,
                'motivo' => isset($v->{$map['f_motivo']}->value) ? trim((string) $v->{$map['f_motivo']}->value) : '',
                'puesto_por' => isset($v->{$map['f_puesto_por']}->value) ? trim((string) $v->{$map['f_puesto_por']}->value) : '',
                'notificado' => ($notif === '1' || $notif === 'on' || $notif === 'true'),
            );
        }
    }

    // Por fecha ascendente: el 1 es el más antiguo del curso.
    usort($out, function ($a, $b) {
        if ($a['ts'] === $b['ts']) {
            return strcmp($a['id'], $b['id']);   // orden estable
        }
        return ($a['ts'] < $b['ts']) ? -1 : 1;
    });

    $n = 0;
    foreach ($out as $i => $row) {
        $n++;
        $out[$i]['num'] = $n;
        $out[$i]['color'] = sticpa_pl_aviso_color($n);
        $out[$i]['ink'] = sticpa_pl_aviso_ink($n);
    }

    return $out;
}

/**
 * Pone un aviso a un participante.
 *
 * `$sessionId` es opcional: un aviso puede venir de una sesión concreta o de
 * cualquier otro momento (una salida, un grupo de WhatsApp), y forzar una
 * sesión obligaría a inventarse una.
 */
function sticpa_pl_create_aviso($objSCP, $contactId, $motivo, $date = '', $notificado = false, $sessionId = '')
{
    if (!sticpa_pl_avisos_enabled()) {
        return false;
    }
    $contactId = sticpa_pl_safe_id($contactId);
    $motivo = trim((string) $motivo);
    if ($contactId === '' || $motivo === '') {
        return false;   // un aviso sin motivo no sirve de nada a nadie
    }

    $map = sticpa_pl_avi_map();
    $ts = strtotime((string) $date . ' 12:00:00');
    if (!$ts) {
        $ts = sticpa_pl_now();
    }
    // Un aviso no se pone en el futuro: la fecha viene de un <input type=date>
    // y un dedo en el móvil acierta el mes de al lado con facilidad.
    $now = sticpa_pl_now();
    if ($ts > $now) {
        $ts = $now;
    }

    $who = isset($_SESSION['scp_user_id']) ? (string) $_SESSION['scp_user_id'] : '';

    $payload = array(
        // El título lo pone el sistema: quien escribe teclea el motivo, no un
        // asunto. La especificación ya dice que `name` puede quedar vacío, pero
        // un registro sin título es ilegible en las listas del CRM.
        $map['f_name'] => sprintf(
            /* translators: %s: fecha del aviso */
            __('Aviso · %s', 'sticpa'),
            date('Y-m-d', $ts)
        ),
        $map['f_date'] => date('Y-m-d', $ts),
        $map['f_motivo'] => $motivo,
        // El «cuándo se avisó a la familia» no se guarda: el campo de fecha de
        // la especificación (`ajmcm_notificado_el_c`) no se creó en el módulo.
        // Solo queda el booleano, verificado contra el CRM con
        // `get_module_fields`. Si algún día se crea ese campo, se añade a
        // `sticpa_pl_avi_map()` y se escribe aquí igual que la fecha del aviso.
        $map['f_notificado'] => $notificado ? '1' : '0',
        'assigned_user_id' => sticpa_pl_delegation($objSCP),
    );
    if ($who !== '') {
        // `contact_id_c` es el campo real que fija la relación (confirmado
        // contra el CRM); `ajmcm_puesto_por_c` es solo el nombre para mostrar,
        // que SuiteCRM resuelve solo a partir del id — no hace falta escribirlo.
        $payload[$map['f_puesto_por_id']] = $who;
    }
    $sessionId = sticpa_pl_safe_id($sessionId);
    if ($sessionId !== '') {
        $payload[$map['f_sesion']] = $sessionId;
    }

    $id = $objSCP->set_entry($map['module'], $payload);
    if (!$id) {
        return false;
    }
    // La única relación de verdad del módulo: el aviso es de UNA persona.
    $objSCP->set_relationship($map['module'], $id, $map['link_contacts'], array($contactId));
    sticpa_pl_flush($objSCP, 'state');
    return true;
}

/**
 * Cambia el pañuelo de un participante.
 *
 * Es la única escritura de la ficha, y va con confirmación en pantalla: es un
 * dato que se usa para saber quién puede hacer qué en una actividad, así que
 * cambiarlo por un toque accidental tiene consecuencias.
 */
function sticpa_pl_set_panuelo($objSCP, $contactId, $value)
{
    $contactId = sticpa_pl_safe_id($contactId);
    $panuelos = sticpa_pl_panuelos();
    if ($contactId === '' || !isset($panuelos[$value])) {
        return false;
    }
    $ok = $objSCP->set_entry('Contacts', array('id' => $contactId, 'ajmcm_panuelo_c' => $value));
    return (bool) $ok;
}

/**
 * Todos los registros enlazados de una fila, de todos sus enlaces.
 *
 * sticpa_pl_link_id() devuelve el primero y vale cuando hay un solo enlace. En
 * las familias hay DOS enlaces a Contacts, así que hace falta verlos todos.
 */
function sticpa_pl_link_records($row)
{
    $out = array();
    $links = isset($row->link_list) ? $row->link_list : array();
    foreach ((array) $links as $link) {
        $name = isset($link->name) ? (string) $link->name : '';
        if (!isset($link->records) || !is_array($link->records)) {
            continue;
        }
        foreach ($link->records as $record) {
            $lv = isset($record->link_value) ? $record->link_value : null;
            if ($lv && !empty($lv->id->value)) {
                $out[] = array('link' => $name, 'value' => $lv);
            }
        }
    }
    return $out;
}

/**
 * La familia de un participante: quién es, cómo contactar y quién es la
 * referencia.
 *
 * ⚠️ `stic_Personal_Environment` enlaza DOS veces con Contacts
 * (`stic_personal_environment_contacts` y `..._contacts_1`) y los nombres no
 * dicen cuál es el menor y cuál el familiar. En vez de adivinarlo —que es
 * exactamente lo que prohíbe el CLAUDE.md— se piden los dos enlaces poblados y
 * se descarta al propio participante: quien quede es el familiar. Funciona sin
 * depender de cuál de los dos enlaces sea cuál.
 *
 * Se consulta desde los dos lados porque la relación puede estar creada en
 * cualquiera de los dos sentidos.
 */
function sticpa_pl_family($objSCP, $contactId)
{
    $contactId = sticpa_pl_safe_id($contactId);
    if ($contactId === '') {
        return array();
    }

    $people = array();
    $links = array('stic_personal_environment_contacts', 'stic_personal_environment_contacts_1');

    foreach ($links as $link) {
        $rows = $objSCP->getRelatedElementsForLoggedUser(array(
            'module_name' => 'Contacts',
            'module_id' => $contactId,
            'link_field_name' => $link,
            'related_fields' => array('id', 'relationship_type', 'reference_contact', 'authorized_signer', 'end_date'),
            'related_module_link_name_to_fields_array' => array(
                array('name' => 'stic_personal_environment_contacts', 'value' => array('id', 'first_name', 'last_name', 'name', 'phone_mobile')),
                array('name' => 'stic_personal_environment_contacts_1', 'value' => array('id', 'first_name', 'last_name', 'name', 'phone_mobile')),
            ),
            'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
        ));
        if (!is_array($rows)) {
            continue;
        }

        foreach ($rows as $row) {
            $v = isset($row->name_value_list) ? $row->name_value_list : null;
            if (!$v) {
                continue;
            }
            // Una relación familiar terminada no se enseña como contacto actual.
            $end = isset($v->end_date->value) ? trim((string) $v->end_date->value) : '';
            if ($end !== '') {
                $endTs = strtotime($end . ' 23:59:59');
                if ($endTs && $endTs < sticpa_pl_now()) {
                    continue;
                }
            }

            foreach (sticpa_pl_link_records($row) as $rec) {
                $lv = $rec['value'];
                $id = (string) $lv->id->value;
                if ($id === $contactId || isset($people[$id])) {
                    continue;   // el propio participante, o ya lo tenemos
                }
                $first = isset($lv->first_name->value) ? trim((string) $lv->first_name->value) : '';
                $last = isset($lv->last_name->value) ? trim((string) $lv->last_name->value) : '';
                $full = trim($first . ' ' . $last);
                if ($full === '' && isset($lv->name->value)) {
                    $full = trim((string) $lv->name->value);
                }
                $people[$id] = array(
                    'id' => $id,
                    'name' => $full,
                    'initials' => sticpa_pl_initials($first, $last, $full),
                    'mobile' => isset($lv->phone_mobile->value) ? (string) $lv->phone_mobile->value : '',
                    'relationship' => isset($v->relationship_type->value) ? (string) $v->relationship_type->value : '',
                    'reference' => !empty($v->reference_contact->value) && $v->reference_contact->value !== '0',
                    'signer' => !empty($v->authorized_signer->value) && $v->authorized_signer->value !== '0',
                );
            }
        }
    }

    // La familia de referencia primero: es a quien se llama.
    $people = array_values($people);
    usort($people, 'sticpa_pl_cmp_family');
    return $people;
}

/** La referencia va primero; luego quien firma; luego por nombre. */
function sticpa_pl_cmp_family($a, $b)
{
    if ($a['reference'] !== $b['reference']) {
        return $a['reference'] ? -1 : 1;
    }
    if ($a['signer'] !== $b['signer']) {
        return $a['signer'] ? -1 : 1;
    }
    return strcmp($a['name'], $b['name']);
}

/**
 * Un teléfono en formato para `tel:` y `wa.me`.
 *
 * WhatsApp quiere el número sin signos ni espacios y con prefijo de país; los
 * teléfonos del CRM están escritos como cada uno quiso, así que se limpian. Si
 * no hay prefijo se asume España, porque es donde están todas las delegaciones;
 * es un supuesto y está dicho aquí para que se vea.
 */
function sticpa_pl_phone($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return null;
    }
    $digits = preg_replace('/[^0-9+]/', '', $raw);
    if ($digits === '' || $digits === '+') {
        return null;
    }
    $wa = ltrim($digits, '+');
    if (strpos($digits, '+') !== 0 && strlen($wa) === 9) {
        $wa = '34' . $wa;
    }
    return array('display' => $raw, 'tel' => $digits, 'wa' => $wa);
}

// ---------------------------------------------------------------------------
// Coordinación (fase 3)
// ---------------------------------------------------------------------------

/**
 * ¿Coordina algo el usuario conectado?
 *
 * Sale de tener una relación `coordinacion_mic_com` vigente
 * (sticpa_pl_coord_scope). Mientras esa clave no exista en el CRM, nadie
 * coordina y las pantallas se comportan como para un monitor: se VE todo, no se
 * EDITA nada. Que por defecto no se pueda editar es lo correcto — el error
 * seguro es el que no deja tocar.
 */
function sticpa_pl_is_coordinator($objSCP)
{
    $isCoord = (sticpa_pl_coord_scope($objSCP) !== null);
    return (bool) apply_filters('sticpa_pl_is_coordinator', $isCoord, $objSCP);
}

/**
 * ¿Existe el campo de segmento en Grupos?
 *
 * ENCENDIDO: el campo está creado. Se deja el interruptor porque pedir un campo
 * que no existe hace fallar la consulta ENTERA y deja la pantalla en blanco, así
 * que si una instancia se queda sin él hay una salida que no es tocar código:
 *
 *     add_filter('sticpa_pl_has_segmento', '__return_false');
 */
function sticpa_pl_has_segmento()
{
    return (bool) apply_filters('sticpa_pl_has_segmento', true);
}

/**
 * Todas las listas de un puñado de sesiones, indexadas por sesión y grupo.
 *
 * Una llamada por SESIÓN, no por sesión y grupo: cada llamada trae las listas de
 * todos los grupos de esa sesión de golpe. Es lo que hace viable la tira del
 * resumen, que si no serían grupos × sesiones consultas.
 *
 * Aun así son tantas llamadas como sesiones, así que el resumen las limita a las
 * últimas ($limit) y lo dice en pantalla en vez de recortar en silencio.
 */
function sticpa_pl_listas_by_session($objSCP, $sessions, $limit = 12)
{
    $sessions = sticpa_pl_elapsed_sessions($sessions);
    $sessions = array_slice($sessions, -1 * max(1, (int) $limit));

    $out = array();
    foreach ($sessions as $s) {
        $rows = $objSCP->getRelatedElementsForLoggedUser(array(
            'module_name' => 'stic_Sessions',
            'module_id' => $s['id'],
            'link_field_name' => 'lis_listas_stic_sessions',
            'related_fields' => array('id', 'estado', 'n_asistieron', 'n_faltaron'),
            'related_module_link_name_to_fields_array' => array(
                array('name' => 'lis_listas_ajmcm_grupos', 'value' => array('id')),
            ),
            'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
        ));

        $bySession = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $v = isset($row->name_value_list) ? $row->name_value_list : null;
                if (!$v || empty($v->id->value)) {
                    continue;
                }
                $gid = sticpa_pl_link_id($row);
                if ($gid === '') {
                    continue;
                }
                $bySession[$gid] = array(
                    'id' => $v->id->value,
                    'estado' => isset($v->estado->value) ? (string) $v->estado->value : '',
                    'n_asistieron' => isset($v->n_asistieron->value) ? (int) $v->n_asistieron->value : 0,
                    'n_faltaron' => isset($v->n_faltaron->value) ? (int) $v->n_faltaron->value : 0,
                );
            }
        }
        $out[$s['id']] = array('session' => $s, 'listas' => $bySession);
    }
    return $out;
}

/**
 * Participantes MIC-COM de la delegación, con relación VIGENTE y SIN grupo.
 *
 * Las tres condiciones son la regla, y las tres importan:
 *   · `participante_mic_com` — un monitor sin grupo no es el mismo problema y
 *     mezclarlo en la misma lista la vuelve inútil.
 *   · relación vigente — una acabada el curso pasado no es un dato que falte,
 *     es historia.
 *   · sin grupo — el enlace al grupo vuelve vacío.
 *
 * Una sola llamada: se piden las relaciones de la delegación con el enlace al
 * grupo poblado, y las que vuelven sin grupo son las que faltan.
 *
 * Devuelve array de array('rel_id','name','initials'): el `rel_id` es lo que hay
 * que tocar para asignar el grupo, NO el contacto — el grupo de alguien es un
 * dato con vigencia y vive en la relación.
 */
function sticpa_pl_participants_without_group($objSCP)
{
    $deleg = sticpa_pl_delegation($objSCP);
    if (!$deleg) {
        return array();
    }

    $rows = $objSCP->getRecordsModule(
        'stic_Contacts_Relationships',
        "stic_contacts_relationships.assigned_user_id = '" . sticpa_pl_safe_id($deleg) . "'",
        array('id', 'relationship_type', 'end_date'),
        array('grupo' => 'ajmcm_grupos_stic_contacts_relationships', 'persona' => 'stic_contacts_relationships_contacts')
    );

    $out = array();
    if (!is_array($rows)) {
        return $out;
    }
    $now = sticpa_pl_now();

    foreach ($rows as $row) {
        $v = isset($row->name_value_list) ? $row->name_value_list : null;
        if (!$v || empty($v->id->value)) {
            continue;
        }
        if (sticpa_pl_rel_role(isset($v->relationship_type->value) ? $v->relationship_type->value : '') !== 'participante') {
            continue;
        }
        $end = isset($v->end_date->value) ? trim((string) $v->end_date->value) : '';
        if ($end !== '') {
            $endTs = strtotime($end . ' 23:59:59');
            if ($endTs && $endTs < $now) {
                continue;   // relación de un curso pasado: no falta nada
            }
        }
        // getRecordsModule mete el enlace como un campo más con el nombre que se
        // le pidió; si viene vacío, esta persona no tiene grupo.
        $grupo = isset($v->grupo->value) ? trim((string) $v->grupo->value) : '';
        if ($grupo !== '') {
            continue;
        }
        $name = isset($v->persona->value) ? trim((string) $v->persona->value) : '';
        $out[] = array(
            'rel_id' => $v->id->value,
            'name' => ($name !== '') ? $name : __('(sin nombre)', 'sticpa'),
            'initials' => sticpa_pl_initials('', '', $name),
        );
    }
    return $out;
}

/**
 * Asigna un grupo a una relación persona-grupo existente.
 *
 * Se toca la RELACIÓN, no el contacto: el grupo de una persona es un dato con
 * vigencia y vive en `stic_Contacts_Relationships`. Solo coordinación llega
 * aquí; la pantalla ni pinta el control para un monitor, y esto lo vuelve a
 * comprobar porque una pantalla que no pinta un botón no impide un POST.
 */
function sticpa_pl_assign_group($objSCP, $relId, $groupId)
{
    if (!sticpa_pl_is_coordinator($objSCP)) {
        return false;
    }
    $relId = sticpa_pl_safe_id($relId);
    $groupId = sticpa_pl_safe_id($groupId);
    if ($relId === '' || $groupId === '') {
        return false;
    }
    // El grupo tiene que ser de mi delegación: si no, esto sería una vía para
    // mover personas a grupos de otra.
    $groups = sticpa_pl_groups($objSCP);
    if (!isset($groups[$groupId])) {
        return false;
    }

    $ok = $objSCP->set_relationship(
        'stic_Contacts_Relationships',
        $relId,
        'ajmcm_grupos_stic_contacts_relationships',
        array($groupId)
    );
    sticpa_pl_flush($objSCP, 'all');
    return (bool) $ok;
}

// ===========================================================================
// COORDINACIÓN Y MONITORES
// ---------------------------------------------------------------------------
// Diseño: docs/comunica/PASAR-LISTA-COORDINACION.md
// ===========================================================================

/** Clave interna de la relación de coordinación. Filtrable por si cambia. */
function sticpa_pl_coord_rel_type()
{
    return (string) apply_filters('sticpa_pl_coord_rel_type', 'coordinacion_mic_com');
}

/** Valores de `LIS_listas.ajmcm_tipo_c`. La API no expone los enum. */
function sticpa_pl_lista_tipos()
{
    return apply_filters('sticpa_pl_lista_tipos', array(
        'participantes' => 'participantes',
        'monitores' => 'monitores',
    ));
}

/**
 * El ALCANCE de coordinación del usuario conectado.
 *
 * Devuelve null si no coordina, o array('etapa' => 'COM'|'', 'segmento' => '').
 * Un array con las dos vacías significa que coordina TODA la delegación: quien
 * no tiene alcance marcado es justo quien mira el conjunto.
 *
 * Sale de la MISMA llamada que ya hace sticpa_pl_my_groups() en la práctica
 * (las relaciones del contacto), pero se cachea aparte porque se pregunta desde
 * casi todas las pantallas y así no se repite.
 */
function sticpa_pl_coord_scope($objSCP)
{
    $userId = isset($_SESSION['scp_user_id']) ? $_SESSION['scp_user_id'] : '';
    if (!$userId) {
        return null;
    }

    $cacheKey = sticpa_pl_cache_key('coord', $objSCP, $userId);
    $ttl = sticpa_pl_ttl_structure();
    if ($ttl > 0) {
        $cached = get_transient($cacheKey);
        // Se guarda envuelto porque `null` (no coordina) es un resultado válido
        // y hay que distinguirlo de "no hay caché".
        if (is_array($cached) && array_key_exists('scope', $cached)) {
            return $cached['scope'];
        }
    }

    $rels = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'Contacts',
        'module_id' => $userId,
        'link_field_name' => 'stic_contacts_relationships_contacts',
        'related_fields' => array('id', 'relationship_type', 'end_date', 'ajmcm_etapa_relacion_c'),
        'related_module_link_name_to_fields_array' => array(
            array('name' => 'ajmcm_grupos_stic_contacts_relationships', 'value' => array('id')),
        ),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));

    $scope = null;
    $needle = strtolower(sticpa_pl_coord_rel_type());
    $now = sticpa_pl_now();

    if (is_array($rels)) {
        foreach ($rels as $rel) {
            $v = isset($rel->name_value_list) ? $rel->name_value_list : null;
            if (!$v) {
                continue;
            }
            $type = isset($v->relationship_type->value) ? strtolower((string) $v->relationship_type->value) : '';
            // Comparación tolerante, como en el resto del proyecto: no queremos
            // que un guion o una mayúscula deje a alguien sin coordinar.
            if ($type === '' || strpos($type, 'coordinaci') === false) {
                continue;
            }
            unset($needle);
            $end = isset($v->end_date->value) ? trim((string) $v->end_date->value) : '';
            if ($end !== '') {
                $endTs = strtotime($end . ' 23:59:59');
                if ($endTs && $endTs < $now) {
                    continue;   // coordinó otro curso
                }
            }

            $etapa = isset($v->ajmcm_etapa_relacion_c->value) ? trim((string) $v->ajmcm_etapa_relacion_c->value) : '';
            $etapa = sticpa_pl_group_etapa($etapa);

            // El segmento del alcance sale del grupo al que apunte la relación
            // de coordinación, si apunta a alguno: "coordino el COM II" se dice
            // colgando la relación de un grupo COM II.
            $segmento = '';
            $gid = sticpa_pl_link_id($rel);
            if ($gid !== '') {
                $groups = sticpa_pl_groups($objSCP);
                if (isset($groups[$gid]['segmento'])) {
                    $segmento = $groups[$gid]['segmento'];
                }
            }

            // Varias relaciones de coordinación: gana la MÁS AMPLIA. Si alguien
            // coordina el COM II y además la delegación entera, ve la delegación.
            $candidate = array('etapa' => $etapa, 'segmento' => $segmento);
            if ($scope === null) {
                $scope = $candidate;
            } elseif ($candidate['etapa'] === '' && $candidate['segmento'] === '') {
                $scope = $candidate;
            } elseif ($scope['segmento'] !== '' && $candidate['segmento'] === '') {
                $scope = $candidate;
            }
        }
    }

    if ($ttl > 0) {
        set_transient($cacheKey, array('scope' => $scope), $ttl);
    }
    return $scope;
}

/** Los grupos que entran en el alcance de coordinación. */
function sticpa_pl_scoped_groups($objSCP, $scope)
{
    $groups = sticpa_pl_groups($objSCP);
    if ($scope === null) {
        return $groups;
    }
    $out = array();
    foreach ($groups as $id => $g) {
        if (sticpa_pl_scope_matches($scope, $g)) {
            $out[$id] = $g;
        }
    }
    return $out;
}

/**
 * Los monitores de un conjunto de grupos, sin repetir.
 *
 * Una llamada POR GRUPO, que es la misma que ya usa la pantalla de marcado y
 * está cacheada como estructura: si el monitor acaba de abrir sus grupos, esto
 * no pide nada. Un monitor de dos grupos sale una vez, con los dos grupos
 * anotados, porque en la lista de monitores es una sola persona.
 */
function sticpa_pl_monitors_of($objSCP, $groups)
{
    $out = array();
    foreach ($groups as $gid => $g) {
        $people = sticpa_pl_group_people($objSCP, $gid);
        foreach ($people['monitors'] as $m) {
            if (!isset($out[$m['id']])) {
                $out[$m['id']] = $m;
                $out[$m['id']]['groups'] = array();
            }
            $out[$m['id']]['groups'][] = $g['code'];
        }
    }
    $out = array_values($out);
    usort($out, 'sticpa_pl_cmp_person');
    return $out;
}

/**
 * La ficha de un monitor: lo esencial, no el módulo entero.
 *
 * Los nombres salen de pages/single_stic_comunica_monitor.php, que es el
 * formulario donde el propio monitor los rellena, así que están verificados por
 * uso. Se piden explícitamente: sin lista, la API devuelve los ~200 campos.
 */
function sticpa_pl_monitor_fields()
{
    return array(
        'id', 'first_name', 'last_name', 'name',
        'birthdate', 'stic_age_c', 'phone_mobile', 'email1',
        // Trayectoria
        'ajmcm_monitor_desde_c', 'ajmcm_monitor_de_c',
        // Certificado de delitos sexuales: lo primero de la ficha
        'ajmcm_aut_del_sex_c', 'ajmcm_cert_del_sex_c',
        // Titulaciones y sus archivos
        'ajmcm_premonitores1_c', 'ajmcm_premonitores2_c', 'ajmcm_premonitores_year_c',
        'ajmcm_mat_c', 'ajmcm_mat_year_c', 'ajmcm_mat_file_c',
        'ajmcm_dat_c', 'ajmcm_dat_year_c', 'ajmcm_dat_file_c',
        'ajmcm_fa_c', 'ajmcm_fa_year_c',
        'ajmcm_alimentos_c', 'ajmcm_cert_files_c',
        'ajmcm_formacion_academica_c',
    );
}

/** La ficha de un monitor, en una llamada. */
function sticpa_pl_monitor_ficha($objSCP, $contactId)
{
    $contactId = sticpa_pl_safe_id($contactId);
    if ($contactId === '') {
        return null;
    }
    $detail = $objSCP->getRecordDetail($contactId, 'Contacts', sticpa_pl_monitor_fields());
    if (empty($detail->entry_list[0]->name_value_list)) {
        return null;
    }
    $v = $detail->entry_list[0]->name_value_list;

    $out = array();
    foreach (sticpa_pl_monitor_fields() as $f) {
        $out[$f] = isset($v->$f->value) ? (string) $v->$f->value : '';
    }
    if (trim($out['name']) === '') {
        $out['name'] = trim($out['first_name'] . ' ' . $out['last_name']);
    }
    $out['initials'] = sticpa_pl_initials($out['first_name'], $out['last_name'], $out['name']);
    return $out;
}

// ---------------------------------------------------------------------------
// Reuniones de programación
// ---------------------------------------------------------------------------

/** El nombre del evento de reuniones del curso. Sin delegación delante. */
function sticpa_pl_reuniones_event_name()
{
    $course = sticpa_pl_course_for();
    return sprintf(
        /* translators: %s: curso escolar, p. ej. 2025-2026 */
        __('Monitores | Reuniones de programación %s', 'sticpa'),
        $course['label']
    );
}

/**
 * El evento de reuniones de la delegación, creándolo si no existe.
 *
 * Se crea desde aquí a propósito: son 3-4 reuniones al año y crear el evento a
 * mano cada septiembre es una cosa más que recordar. `$create = false` para solo
 * mirar, que es lo que hacen las pantallas que solo listan.
 */
function sticpa_pl_reuniones_event($objSCP, $create = false)
{
    $deleg = sticpa_pl_delegation($objSCP);
    if (!$deleg) {
        return null;
    }
    $name = sticpa_pl_reuniones_event_name();
    $cacheKey = sticpa_pl_cache_key('evreu', $objSCP);
    $ttl = sticpa_pl_ttl_structure();

    if ($ttl > 0) {
        $cached = get_transient($cacheKey);
        if (is_array($cached) && !empty($cached['id'])) {
            return $cached;
        }
    }

    $rows = $objSCP->getRecordsModule(
        'stic_Events',
        "stic_events.assigned_user_id = '" . sticpa_pl_safe_id($deleg) . "'",
        array('id', 'name')
    );
    $found = null;
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $v = $row->name_value_list;
            if (!empty($v->id->value) && isset($v->name->value) && (string) $v->name->value === $name) {
                $found = array('id' => $v->id->value, 'name' => $name);
                break;
            }
        }
    }

    if ($found === null && $create) {
        $id = $objSCP->set_entry('stic_Events', array(
            'name' => $name,
            'assigned_user_id' => $deleg,
        ));
        if ($id) {
            $found = array('id' => $id, 'name' => $name);
        }
    }

    if ($found !== null && $ttl > 0) {
        set_transient($cacheKey, $found, $ttl);
    }
    return $found;
}

/**
 * Crea una reunión: una sesión del evento de reuniones.
 *
 * Nombre, fecha y duración, que es lo que se puede teclear de pie en cinco
 * segundos. La fecha va en hora LOCAL con formato `Y-m-d H:i:s`: mandarla en ISO
 * con desplazamiento hace que la API la ignore y ponga la hora actual (está
 * comprobado, y es el tipo de fallo que no avisa).
 */
function sticpa_pl_create_reunion($objSCP, $name, $date, $time, $hours)
{
    if (!sticpa_pl_is_coordinator($objSCP)) {
        return null;
    }
    $name = trim((string) $name);
    $date = trim((string) $date);
    if ($name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }
    $time = preg_match('/^\d{2}:\d{2}$/', (string) $time) ? $time : '19:00';
    $hours = (float) $hours;
    if ($hours <= 0 || $hours > 12) {
        $hours = 1.5;
    }

    $event = sticpa_pl_reuniones_event($objSCP, true);
    if ($event === null) {
        return null;
    }

    $startTs = strtotime($date . ' ' . $time);
    if (!$startTs) {
        return null;
    }
    $endTs = $startTs + (int) round($hours * HOUR_IN_SECONDS);

    $id = $objSCP->set_entry('stic_Sessions', array(
        'name' => $name,
        'start_date' => date('Y-m-d H:i:s', $startTs),
        'end_date' => date('Y-m-d H:i:s', $endTs),
        'assigned_user_id' => sticpa_pl_delegation($objSCP),
    ));
    if (!$id) {
        return null;
    }
    $objSCP->set_relationship('stic_Sessions', $id, 'stic_sessions_stic_events', array($event['id']));

    sticpa_pl_flush($objSCP, 'all');
    return array('id' => $id, 'name' => $name, 'start' => $startTs, 'end' => $endTs);
}

/**
 * Guarda la lista de MONITORES de una sesión.
 *
 * Diferencia clave con la de participantes: aquí se escribe `yes` EXPLÍCITO
 * para todos los que no vengan marcados como falta. Se asume que los monitores
 * vienen siempre, así que el verde es un dato afirmado por coordinación, no un
 * hueco — y si se dejara vacío, el porcentaje del monitor saldría a cero
 * habiendo venido a todo.
 */
function sticpa_pl_save_monitors($objSCP, $sessionId, $monitors, $marks, $regMap = array())
{
    $sessionId = (string) $sessionId;
    $result = array('saved' => 0, 'failed' => 0, 'counts' => array('yes' => 0, 'no' => 0));
    if ($sessionId === '') {
        return $result;
    }

    $states = sticpa_pl_states();
    $existing = sticpa_pl_session_attendances($objSCP, $sessionId, $regMap);

    foreach ($monitors as $m) {
        $key = isset($marks[$m['id']]) && sticpa_pl_is_state($marks[$m['id']])
            ? $marks[$m['id']]
            : 'yes';                    // el defecto es verde, y se escribe

        if ($states[$key]['counts']) {
            $result['counts']['yes']++;
        } else {
            $result['counts']['no']++;
        }

        if (isset($existing[$m['id']]['id'])) {
            $ok = $objSCP->set_entry('stic_Attendances', array(
                'id' => $existing[$m['id']]['id'],
                'status' => $key,
            ));
            if ($ok) {
                $result['saved']++;
            } else {
                $result['failed']++;
            }
            continue;
        }

        // Sin asistencia previa: se crea y se ata a la sesión y, si se conoce, a
        // su inscripción. Es lo normal aquí, porque los monitores no siempre
        // están inscritos al evento desde el principio.
        $newId = $objSCP->set_entry('stic_Attendances', array(
            'status' => $key,
            'assigned_user_id' => sticpa_pl_delegation($objSCP),
        ));
        if (!$newId) {
            $result['failed']++;
            continue;
        }
        $objSCP->set_relationship('stic_Attendances', $newId, 'stic_attendances_stic_sessions', array($sessionId));
        $regId = array_search($m['id'], (array) $regMap, true);
        if ($regId !== false) {
            $objSCP->set_relationship('stic_Attendances', $newId, 'stic_attendances_stic_registrations', array($regId));
        }
        $result['saved']++;
    }

    sticpa_pl_flush($objSCP, 'state');
    return $result;
}

// ===========================================================================
// SEGUIMIENTOS DE MONITORES — stic_FollowUps
// ---------------------------------------------------------------------------
// Diseño y por qué este módulo: docs/comunica/PASAR-LISTA-SEGUIMIENTOS.md
// ===========================================================================

/**
 * ENCENDIDO. El acceso del usuario de la API a `stic_FollowUps` está dado.
 *
 * OJO CON UNA COSA: los nombres de campo de `sticpa_pl_seg_map()` son la
 * convención documentada del módulo y no los pude comprobar contra la instancia
 * (cuando lo intenté, la API respondía "does not have access to this module").
 * Si al abrir la ficha de un monitor los seguimientos salen vacíos o el guardado
 * falla, el problema es un nombre, y se corrige SIN TOCAR CÓDIGO con el filtro
 * `sticpa_pl_seg_map`. Y si hay que apagarlo mientras se averigua:
 *
 *     add_filter('sticpa_pl_seguimientos_enabled', '__return_false');
 */
function sticpa_pl_seguimientos_enabled()
{
    return (bool) apply_filters('sticpa_pl_seguimientos_enabled', true);
}

/**
 * TODOS los nombres técnicos del módulo, en un solo sitio.
 *
 * Están juntos a propósito: es lo único que no he podido verificar, y así
 * corregirlo es cambiar un filtro en vez de buscar por seis archivos.
 */
function sticpa_pl_seg_map()
{
    return apply_filters('sticpa_pl_seg_map', array(
        'module' => 'stic_FollowUps',
        'link_contacts' => 'stic_followups_contacts',
        'f_name' => 'name',
        'f_text' => 'description',
        'f_type' => 'type',
        'f_date' => 'date_start',
    ));
}

/**
 * Las claves internas de nuestros tres tipos dentro de la lista de Seguimientos.
 *
 * Con prefijo `mcm_` para no chocar con los tipos que la entidad ya use en
 * Seguimientos para otras cosas: es un módulo compartido, no nuestro.
 */
function sticpa_pl_seg_type_keys()
{
    return apply_filters('sticpa_pl_seg_type_keys', array(
        'incidencia' => 'mcm_incidencia',
        'valoracion' => 'mcm_valoracion',
        'acompanamiento' => 'mcm_acompanamiento',
    ));
}

/** De la clave del CRM a la nuestra. Lo que no reconozcamos no se enseña. */
function sticpa_pl_seg_type_from_crm($raw)
{
    $raw = trim((string) $raw);
    foreach (sticpa_pl_seg_type_keys() as $ours => $theirs) {
        if ($raw === $theirs) {
            return $ours;
        }
    }
    return '';
}

/**
 * ¿Acompaña el usuario conectado?
 *
 * Mismo sitio y misma mecánica que coordinación: una relación
 * `acompanamiento_mic_com` vigente. Coordinar y acompañar NO son jerárquicos:
 * quien hace las dos cosas ve la unión, y quien solo coordina no ve
 * acompañamiento aunque coordine la delegación entera.
 */
function sticpa_pl_is_acompanante($objSCP)
{
    $userId = isset($_SESSION['scp_user_id']) ? $_SESSION['scp_user_id'] : '';
    if (!$userId) {
        return false;
    }

    $cacheKey = sticpa_pl_cache_key('acomp', $objSCP, $userId);
    $ttl = sticpa_pl_ttl_structure();
    if ($ttl > 0) {
        $cached = get_transient($cacheKey);
        if (is_array($cached) && array_key_exists('is', $cached)) {
            return (bool) apply_filters('sticpa_pl_is_acompanante', $cached['is'], $objSCP);
        }
    }

    $rels = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'Contacts',
        'module_id' => $userId,
        'link_field_name' => 'stic_contacts_relationships_contacts',
        'related_fields' => array('id', 'relationship_type', 'end_date'),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));

    $is = false;
    $now = sticpa_pl_now();
    if (is_array($rels)) {
        foreach ($rels as $rel) {
            $v = isset($rel->name_value_list) ? $rel->name_value_list : null;
            if (!$v) {
                continue;
            }
            $type = isset($v->relationship_type->value)
                ? (function_exists('mb_strtolower')
                    ? mb_strtolower((string) $v->relationship_type->value, 'UTF-8')
                    : strtolower((string) $v->relationship_type->value))
                : '';
            // Tolerante con la ñ y con los acentos, que en una clave interna
            // pueden estar de las dos formas.
            if ($type === '' || (strpos($type, 'acompan') === false && strpos($type, 'acompañ') === false)) {
                continue;
            }
            $end = isset($v->end_date->value) ? trim((string) $v->end_date->value) : '';
            if ($end !== '') {
                $endTs = strtotime($end . ' 23:59:59');
                if ($endTs && $endTs < $now) {
                    continue;
                }
            }
            $is = true;
            break;
        }
    }

    if ($ttl > 0) {
        set_transient($cacheKey, array('is' => $is), $ttl);
    }
    return (bool) apply_filters('sticpa_pl_is_acompanante', $is, $objSCP);
}

/**
 * Los seguimientos de un monitor que ESTE usuario puede leer.
 *
 * Dos cierres: la consulta ya no tiene sentido si no hay ningún tipo permitido
 * (se devuelve vacío sin llamar al CRM), y lo que vuelve pasa otra vez por
 * sticpa_pl_seg_filter(). Redundante a propósito — el coste de un fallo aquí no
 * es un dato de más.
 */
function sticpa_pl_seguimientos($objSCP, $monitorId, $roles)
{
    if (!sticpa_pl_seguimientos_enabled()) {
        return array();
    }
    $monitorId = sticpa_pl_safe_id($monitorId);
    if ($monitorId === '' || empty($roles)) {
        return array();
    }
    $allowed = sticpa_pl_seg_readable($roles);
    if (empty($allowed)) {
        return array();      // ni se pregunta
    }

    $map = sticpa_pl_seg_map();
    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'Contacts',
        'module_id' => $monitorId,
        'link_field_name' => $map['link_contacts'],
        'related_fields' => array('id', $map['f_name'], $map['f_text'], $map['f_type'], $map['f_date'], 'assigned_user_name', 'date_entered'),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));

    $out = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $v = isset($row->name_value_list) ? $row->name_value_list : null;
            if (!$v || empty($v->id->value)) {
                continue;
            }
            $rawType = isset($v->{$map['f_type']}->value) ? (string) $v->{$map['f_type']}->value : '';
            $tipo = sticpa_pl_seg_type_from_crm($rawType);
            if ($tipo === '') {
                continue;    // un tipo que no conocemos NO se enseña
            }
            $date = isset($v->{$map['f_date']}->value) ? (string) $v->{$map['f_date']}->value : '';
            if ($date === '' && isset($v->date_entered->value)) {
                $date = (string) $v->date_entered->value;
            }
            $out[] = array(
                'id' => $v->id->value,
                'tipo' => $tipo,
                'titulo' => isset($v->{$map['f_name']}->value) ? (string) $v->{$map['f_name']}->value : '',
                'texto' => isset($v->{$map['f_text']}->value) ? (string) $v->{$map['f_text']}->value : '',
                'ts' => sticpa_pl_ts($date),
                'autor' => isset($v->assigned_user_name->value) ? (string) $v->assigned_user_name->value : '',
            );
        }
    }

    // Segundo cierre, sobre lo que ha vuelto.
    $viewer = isset($_SESSION['scp_user_id']) ? (string) $_SESSION['scp_user_id'] : '';
    $out = sticpa_pl_seg_filter($out, $roles, $viewer, $monitorId);

    // De más reciente a más antiguo: lo de la semana pasada es lo que se busca.
    usort($out, 'sticpa_pl_cmp_seg');
    return $out;
}

/** Orden de seguimientos: el más reciente primero. */
function sticpa_pl_cmp_seg($a, $b)
{
    $x = isset($a['ts']) ? (int) $a['ts'] : 0;
    $y = isset($b['ts']) ? (int) $b['ts'] : 0;
    if ($x === $y) {
        return 0;
    }
    return ($x > $y) ? -1 : 1;
}

/**
 * Crea un seguimiento.
 *
 * Comprueba el permiso de ESCRITURA del tipo concreto, no solo que el usuario
 * tenga algún papel: coordinación no puede escribir acompañamiento aunque pueda
 * escribir incidencias, y una pantalla que no pinta una opción no impide un POST.
 */
function sticpa_pl_create_seguimiento($objSCP, $monitorId, $tipo, $texto, $date, $roles)
{
    if (!sticpa_pl_seguimientos_enabled()) {
        return false;
    }
    $monitorId = sticpa_pl_safe_id($monitorId);
    $tipo = (string) $tipo;
    $texto = trim((string) $texto);

    if ($monitorId === '' || $texto === '') {
        return false;
    }
    if (!in_array($tipo, sticpa_pl_seg_writable($roles), true)) {
        return false;
    }
    // Sobre uno mismo tampoco se escribe: si no se puede leer, escribir un
    // seguimiento propio solo serviría para dejarlo invisible.
    $viewer = isset($_SESSION['scp_user_id']) ? (string) $_SESSION['scp_user_id'] : '';
    if ($viewer !== '' && $viewer === $monitorId) {
        return false;
    }

    $keys = sticpa_pl_seg_type_keys();
    $map = sticpa_pl_seg_map();
    $tipos = sticpa_pl_seg_tipos();

    $ts = strtotime((string) $date . ' 12:00:00');
    if (!$ts) {
        $ts = sticpa_pl_now();
    }

    $payload = array(
        // El título lo pone el sistema: quien escribe teclea el texto, no un
        // asunto. Un campo más es un campo que se deja vacío.
        $map['f_name'] => $tipos[$tipo]['label'] . ' · ' . date('Y-m-d', $ts),
        $map['f_type'] => $keys[$tipo],
        $map['f_text'] => $texto,
        $map['f_date'] => date('Y-m-d H:i:s', $ts),
        'assigned_user_id' => sticpa_pl_delegation($objSCP),
    );

    $id = $objSCP->set_entry($map['module'], $payload);
    if (!$id) {
        return false;
    }
    $objSCP->set_relationship($map['module'], $id, $map['link_contacts'], array($monitorId));
    sticpa_pl_flush($objSCP, 'state');
    return true;
}
