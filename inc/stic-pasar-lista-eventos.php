<?php
/**
 * ============================================================================
 *  PASAR LISTA EN UN EVENTO — «¿estamos todos en el autobús?»
 * ============================================================================
 *
 * Lo de los sábados (`single_stic_pasar_lista_marcar`) es la lista de un GRUPO
 * en una sesión semanal. Esto es la de un EVENTO —una convivencia, una salida,
 * un campamento—: la gente que está inscrita, en el momento de subir al
 * autobús o de volver. Pedido por el propietario el 10/10/2026.
 *
 * Lo que se decidió, y por qué:
 *
 *   · QUÉ EVENTOS. Los de TU delegación (`assigned_user_id`), del curso escolar
 *     en marcha, menos los que no son salidas: el evento de las sesiones
 *     semanales de cada etapa (eso es Pasar Lista de grupos) y el de reuniones
 *     de programación. Nada interdelegacional (CLAUDE.md): un evento nacional
 *     tiene inscritos de todas las delegaciones y sus asistencias son de quien
 *     lo organiza; queda como decisión del propietario
 *     (docs/comunica/PASAR-LISTA-EVENTOS.md).
 *
 *   · EL DE HOY, PRIMERO. Si un evento empieza o acaba hoy —el autobús de ida o
 *     el de vuelta— es al que se viene: va arriba y destacado, y en la portada
 *     de Pasar Lista sale una tarjeta para entrar de un toque. Luego los que
 *     están en curso, los próximos y, al final, los pasados del curso.
 *
 *   · QUIÉN SALE. Las inscripciones del evento. Las confirmadas (y las que no
 *     tienen estado) se marcan; las canceladas o rechazadas se enseñan al
 *     final, apagadas y sin marcar: «¿y Lucía?» — «canceló».
 *
 *   · SIN MARCAR POR DEFECTO. Una inscripción confirmada NO es «está en el
 *     autobús»: eso lo afirma el monitor tocando. Se escribe solo lo marcado,
 *     con los cuatro estados de siempre (`yes`, `partial`, `no_justified`,
 *     `no_unjustified`).
 *
 *   · DÓNDE SE ESCRIBE. Una asistencia en la SESIÓN del evento de ese día, con
 *     el motor de siempre (`sticpa_pl_write_attendances()`): actualiza la que
 *     hay y crea la que falta, atada a su inscripción, en tandas. Si el evento
 *     no tiene NINGUNA sesión en el CRM, al guardar se crea la de ese día
 *     (asignada a la delegación y atada al evento), porque sin sesión no hay
 *     asistencia posible y el autobús no espera.
 *
 *   · SIN COBERTURA. La pantalla usa el mismo marcado y el mismo JS que la de
 *     los sábados: borrador en el móvil, cola que se envía sola al volver la
 *     cobertura, reintento con nonce fresco y «Guardada ✓» solo cuando el
 *     servidor ha releído el CRM y lo ha encontrado.
 *
 * Diseño y pruebas: docs/comunica/PASAR-LISTA-EVENTOS.md
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Los cursos escolares de `ajmcm_curso_escolar_c_list`, en su orden y con la
 * etiqueta que se enseña. Las CLAVES son el dominio completo leído en Studio
 * (docs/comunica/CAMPOS.md, «Relaciones con personas»); las etiquetas son de
 * esta pantalla («3º Primaria» y no el «3º» a secas del CRM, que en un chip
 * suelto no se entiende). `na` y el vacío son «sin curso».
 */
function sticpa_pl_ev_cursos()
{
    return array(
        '3_primaria' => __('3º Primaria', 'sticpa'),
        '4_primaria' => __('4º Primaria', 'sticpa'),
        '5_primaria' => __('5º Primaria', 'sticpa'),
        '6_primaria' => __('6º Primaria', 'sticpa'),
        '1_eso' => __('1º ESO', 'sticpa'),
        '2_eso' => __('2º ESO', 'sticpa'),
        '3_eso' => __('3º ESO', 'sticpa'),
        '4_eso' => __('4º ESO', 'sticpa'),
        '1_bachillerato' => __('1º Bach', 'sticpa'),
        '2_bachillerato' => __('2º Bach', 'sticpa'),
        'fp_gm' => __('FP GM', 'sticpa'),
        'fp_gs' => __('FP GS', 'sticpa'),
        'universitario' => __('Universitario', 'sticpa'),
        'otros' => __('Otros', 'sticpa'),
    );
}

/** La clave de curso tal cual se usa aquí: '' si no hay o es «no aplica». */
function sticpa_pl_ev_curso_key($raw)
{
    $raw = trim((string) $raw);
    $cursos = sticpa_pl_ev_cursos();
    return isset($cursos[$raw]) ? $raw : '';
}

/**
 * La etapa con la que se agrupa a una persona en la lista.
 *
 * Manda la de su ficha (`Contacts.ajmcm_etapa_c`: MIC, COM o LC). Si está
 * vacía —pasa—, se deduce del curso de la inscripción con la equivalencia de
 * la casa: primaria es MIC; ESO, Bachillerato y FP son COM (las sesiones
 * semanales del MIC están marcadas de 4.º a 6.º de primaria, y las del COM de
 * 1.º de ESO en adelante). Universitario y «otros» no se deducen: sin dato,
 * «Sin etapa», mejor que adivinar.
 */
function sticpa_pl_ev_etapa($contactEtapa, $cursoKey)
{
    $e = strtoupper(trim((string) $contactEtapa));
    if (in_array($e, array('MIC', 'COM', 'LC'), true)) {
        return $e;
    }
    $c = (string) $cursoKey;
    if (substr($c, -9) === '_primaria') {
        return 'MIC';
    }
    if (substr($c, -4) === '_eso' || substr($c, -13) === '_bachillerato' || $c === 'fp_gm' || $c === 'fp_gs') {
        return 'COM';
    }
    return '';
}

/**
 * Estados de inscripción que NO van en la lista que se marca: se enseñan al
 * final, apagados. `cancelled` es el que escribe el área al cancelar
 * (CAMPOS.md, Inscripciones); los otros son los de serie de SinergiaCRM que
 * también quieren decir «no va». Lo demás —`confirmed`, vacío o cualquier
 * otro— se marca: ante la duda, que salga en la lista del autobús.
 */
function sticpa_pl_ev_estados_fuera()
{
    return (array) apply_filters('sticpa_pl_ev_estados_fuera', array(
        'cancelled' => __('Cancelada', 'sticpa'),
        'rejected' => __('Rechazada', 'sticpa'),
        'uninvited' => __('Sin invitación', 'sticpa'),
        'not_invited' => __('Sin invitación', 'sticpa'),
        'didnt_take_part' => __('No participa', 'sticpa'),
    ));
}

/** Cuántos días puede durar, como mucho, un evento para pasar lista en él. */
function sticpa_pl_ev_max_dias()
{
    return (int) apply_filters('sticpa_pl_ev_max_dias', 50);
}

/**
 * Los eventos que se ofrecen, ya clasificados y en el orden de la pantalla.
 *
 * PURA: recibe las filas de sticpa_pl_events_raw() y los ids que hay que dejar
 * fuera, y no habla con el CRM. Cada evento sale con:
 *   start, end  — timestamps (sin fecha de fin, la de inicio).
 *   cuando      — 'hoy' (empieza o acaba hoy, o está en curso y pasa por hoy),
 *                 'proximo' o 'pasado'.
 *   dia         — 'start' | 'end' | 'during' | '' : qué es hoy para el evento.
 *                 Ida y vuelta son lo que importa: van primero dentro de «hoy».
 *
 * Solo los del CURSO en marcha (los que se solapan con él) y con fecha de
 * inicio: sin fecha no hay forma de saber de qué día es la lista.
 */
function sticpa_pl_ev_clasificar($rows, $excluir = array(), $nowTs = null)
{
    $nowTs = ($nowTs === null) ? sticpa_pl_now() : (int) $nowTs;
    $course = sticpa_pl_course_for($nowTs);
    $today = date('Y-m-d', $nowTs);
    $excluir = array_flip(array_map('strval', (array) $excluir));

    $out = array();
    foreach ((array) $rows as $ev) {
        $id = isset($ev['id']) ? (string) $ev['id'] : '';
        if ($id === '' || isset($excluir[$id])) {
            continue;
        }
        $start = sticpa_pl_ts(isset($ev['start_date']) ? $ev['start_date'] : '');
        if ($start <= 0) {
            continue;
        }
        $end = sticpa_pl_ts(isset($ev['end_date']) ? $ev['end_date'] : '');
        if ($end < $start) {
            $end = $start;
        }
        if ($end < $course['start'] || $start > $course['end']) {
            continue;   // de otro curso
        }
        // MÁS DE 50 DÍAS no es una salida: es un evento de todo el curso (las
        // sesiones semanales de una etapa, una actividad anual), y eso se pasa
        // por Pasar Lista de grupos, que está hecho de otra manera. Decidido
        // por el propietario el 10/10/2026.
        if (($end - $start) > sticpa_pl_ev_max_dias() * DAY_IN_SECONDS) {
            continue;
        }

        $startDay = date('Y-m-d', $start);
        $endDay = date('Y-m-d', $end);
        $dia = '';
        if ($startDay === $today) {
            $dia = 'start';
        } elseif ($endDay === $today) {
            $dia = 'end';
        } elseif ($startDay < $today && $endDay > $today) {
            $dia = 'during';
        }

        if ($dia !== '') {
            $cuando = 'hoy';
        } elseif ($startDay > $today) {
            $cuando = 'proximo';
        } else {
            $cuando = 'pasado';
        }

        $out[] = array(
            'id' => $id,
            'name' => trim(isset($ev['name']) ? (string) $ev['name'] : ''),
            'start' => $start,
            'end' => $end,
            'cuando' => $cuando,
            'dia' => $dia,
        );
    }

    $orden = array('hoy' => 0, 'proximo' => 1, 'pasado' => 2);
    $ordenDia = array('start' => 0, 'end' => 1, 'during' => 2, '' => 3);
    usort($out, function ($a, $b) use ($orden, $ordenDia) {
        if ($orden[$a['cuando']] !== $orden[$b['cuando']]) {
            return $orden[$a['cuando']] - $orden[$b['cuando']];
        }
        if ($a['cuando'] === 'hoy' && $ordenDia[$a['dia']] !== $ordenDia[$b['dia']]) {
            return $ordenDia[$a['dia']] - $ordenDia[$b['dia']];
        }
        // Los próximos, del más cercano al más lejano; los pasados, del más
        // reciente al más antiguo: lo probable, arriba.
        $cmp = ($a['cuando'] === 'pasado') ? ($b['start'] - $a['start']) : ($a['start'] - $b['start']);
        return ($cmp !== 0) ? $cmp : strcmp($a['name'], $b['name']);
    });
    return $out;
}

/**
 * Los eventos de la delegación para pasar lista, clasificados.
 *
 * Sale del cargador común de eventos (`sticpa_pl_events_raw()`, cacheado como
 * estructura y que la portada de Pasar Lista ya ha pedido en su primera
 * tanda): en la práctica, cero llamadas.
 */
function sticpa_pl_ev_eventos($objSCP, $nowTs = null)
{
    $excluir = array();
    foreach (sticpa_pl_etapa_events($objSCP) as $ev) {
        $excluir[] = $ev['id'];
    }
    $reu = sticpa_pl_reuniones_event($objSCP);
    if (is_array($reu) && !empty($reu['id'])) {
        $excluir[] = $reu['id'];
    }
    return sticpa_pl_ev_clasificar(sticpa_pl_events_raw($objSCP), $excluir, $nowTs);
}

/** Un evento de los que se ofrecen, por id; null si no es de los tuyos. */
function sticpa_pl_ev_evento($objSCP, $eventId, $nowTs = null)
{
    $eventId = sticpa_pl_safe_id($eventId);
    if ($eventId === '') {
        return null;
    }
    foreach (sticpa_pl_ev_eventos($objSCP, $nowTs) as $ev) {
        if ($ev['id'] === $eventId) {
            return $ev;
        }
    }
    return null;
}

/**
 * Las inscripciones de un evento, TODAS (también las canceladas), con lo que
 * hace falta para la lista: estado y curso.
 *
 * Una consulta. Cacheada con el TTL de ESTADO (minutos, no horas): el día de
 * la salida la gente se sigue apuntando y desapuntando, y el guardado la tira.
 * El contacto llega por el campo plano y, si no, por el enlace anidado (la
 * trampa de PASAR-LISTA-ESTADO.md §3.1). Si aun así alguna fila llega sin
 * contacto, se rellena con el mapa de inscripciones de siempre, que tiene su
 * propio respaldo.
 *
 * @return array de array('reg_id', 'contact_id', 'status', 'curso')
 */
function sticpa_pl_ev_inscripciones($objSCP, $eventId)
{
    $eventId = sticpa_pl_safe_id($eventId);
    if ($eventId === '') {
        return array();
    }
    $cacheKey = sticpa_pl_cache_key('evins', $objSCP, $eventId);
    $ttl = sticpa_pl_ttl_state();
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
        'related_fields' => array('id', 'status', 'ajmcm_curso_escolar_c', 'stic_registrations_contactscontacts_ida'),
        'related_module_link_name_to_fields_array' => array(
            array('name' => 'stic_registrations_contacts', 'value' => array('id')),
        ),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));
    if (sticpa_pl_collecting()) {
        return array();
    }

    $out = array();
    $sinContacto = false;
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $v = isset($row->name_value_list) ? $row->name_value_list : null;
            if (!$v || empty($v->id->value)) {
                continue;
            }
            $contactId = sticpa_pl_nvl_first($v, array('stic_registrations_contactscontacts_ida'));
            if ($contactId === '') {
                $contactId = sticpa_pl_link_id($row);
            }
            if ($contactId === '') {
                $sinContacto = true;
            }
            $out[] = array(
                'reg_id' => (string) $v->id->value,
                'contact_id' => sticpa_pl_safe_id($contactId),
                'status' => isset($v->status->value) ? (string) $v->status->value : '',
                'curso' => sticpa_pl_ev_curso_key(isset($v->ajmcm_curso_escolar_c->value) ? $v->ajmcm_curso_escolar_c->value : ''),
            );
        }
    }

    if ($sinContacto) {
        // El mapa de siempre (solo las no canceladas) con su respaldo.
        $map = sticpa_pl_event_registrations($objSCP, $eventId);
        foreach ($out as $i => $r) {
            if ($r['contact_id'] === '' && isset($map[$r['reg_id']])) {
                $out[$i]['contact_id'] = sticpa_pl_safe_id($map[$r['reg_id']]);
            }
        }
    }

    // Un fallo del CRM no se cachea como «no hay nadie inscrito».
    if (!empty($out) || sticpa_pl_crm_error($objSCP) === '') {
        sticpa_pl_cache_put($cacheKey, $out, $ttl);
    }
    return $out;
}

/**
 * Nombre y etapa de las personas inscritas, en UNA consulta por id.
 *
 * @return array contactId => array('first', 'last', 'full', 'etapa')
 */
function sticpa_pl_ev_personas($objSCP, $ids)
{
    $ids = array_values(array_unique(array_filter(array_map('sticpa_pl_safe_id', (array) $ids))));
    if (empty($ids)) {
        return array();
    }
    $lista = array();
    foreach ($ids as $id) {
        $lista[] = "'" . $id . "'";
    }
    $rows = $objSCP->getRecordsModule(
        'Contacts',
        'contacts.id IN (' . implode(',', $lista) . ')',
        array('id', 'name', 'first_name', 'last_name', 'ajmcm_etapa_c')
    );
    $out = array();
    if (!is_array($rows)) {
        return $out;
    }
    foreach ($rows as $row) {
        $v = isset($row->name_value_list) ? $row->name_value_list : null;
        if (!$v || empty($v->id->value)) {
            continue;
        }
        $first = isset($v->first_name->value) ? trim((string) $v->first_name->value) : '';
        $last = isset($v->last_name->value) ? trim((string) $v->last_name->value) : '';
        $full = trim($first . ' ' . $last);
        if ($full === '' && isset($v->name->value)) {
            $full = trim((string) $v->name->value);
        }
        $out[(string) $v->id->value] = array(
            'first' => $first,
            'last' => $last,
            'full' => $full,
            'etapa' => isset($v->ajmcm_etapa_c->value) ? (string) $v->ajmcm_etapa_c->value : '',
        );
    }
    return $out;
}

/**
 * LA LISTA DEL EVENTO, armada. PURA: inscripciones + personas → lo que se
 * pinta y lo que se puede escribir.
 *
 * Devuelve:
 *   'activos'  — personas que se marcan, por nombre (apellidos primero).
 *   'fuera'    — las de inscripción cancelada o parecida, para el final.
 *   'regMap'   — inscripción => contacto, SOLO de las activas: es lo que el
 *                guardado puede tocar y lo que traduce las asistencias leídas.
 *   'cursos'   — curso => cuántos activos (en el orden de los cursos; '' es
 *                «sin curso»), para los chips del filtro.
 *   'etapas'   — etapa => cuántos activos (MIC, COM, LC, '').
 *
 * Una persona con dos inscripciones (pasa: una cancelada y otra nueva) sale
 * UNA vez, con la activa. Con dos activas, la primera; las dos apuntan al
 * mismo contacto y la asistencia es una por persona y sesión.
 *
 * @param array $grupos contactId => array('id', 'code') del grupo de la
 *                      delegación de cada persona, si lo tiene (para la ficha).
 */
function sticpa_pl_ev_lista($inscripciones, $personas, $grupos = array())
{
    $fuera = sticpa_pl_ev_estados_fuera();
    $cursos = sticpa_pl_ev_cursos();

    $activos = array();
    $apagados = array();
    $regMap = array();
    foreach ((array) $inscripciones as $r) {
        $cid = isset($r['contact_id']) ? (string) $r['contact_id'] : '';
        if ($cid === '') {
            continue;   // sin persona no hay a quién marcar
        }
        $status = isset($r['status']) ? (string) $r['status'] : '';
        $esFuera = isset($fuera[$status]);
        $p = isset($personas[$cid]) ? $personas[$cid] : array('first' => '', 'last' => '', 'full' => '', 'etapa' => '');
        $curso = isset($r['curso']) ? (string) $r['curso'] : '';
        $persona = sticpa_pl_person_array($cid, $p['first'], $p['last'], $p['full'] !== '' ? $p['full'] : __('(sin nombre)', 'sticpa'));
        $persona['reg_id'] = (string) $r['reg_id'];
        $persona['status'] = $status;
        $persona['status_label'] = $esFuera ? $fuera[$status] : '';
        $persona['curso'] = $curso;
        $persona['curso_label'] = isset($cursos[$curso]) ? $cursos[$curso] : '';
        $persona['etapa'] = sticpa_pl_ev_etapa($p['etapa'], $curso);
        $persona['grupo_id'] = isset($grupos[$cid]['id']) ? (string) $grupos[$cid]['id'] : '';
        $persona['grupo_code'] = isset($grupos[$cid]['code']) ? (string) $grupos[$cid]['code'] : '';

        if (!$esFuera) {
            if (isset($activos[$cid])) {
                continue;
            }
            $activos[$cid] = $persona;
            $regMap[$persona['reg_id']] = $cid;
            unset($apagados[$cid]);   // la nueva activa gana a la cancelada
        } elseif (!isset($activos[$cid]) && !isset($apagados[$cid])) {
            $apagados[$cid] = $persona;
        }
    }

    $porNombre = function ($a, $b) {
        return strcmp($a['sort'], $b['sort']);
    };
    $activos = array_values($activos);
    $apagados = array_values($apagados);
    usort($activos, $porNombre);
    usort($apagados, $porNombre);

    $nCursos = array();
    foreach (array_keys($cursos) as $k) {
        $nCursos[$k] = 0;
    }
    $nCursos[''] = 0;
    $nEtapas = array('MIC' => 0, 'COM' => 0, 'LC' => 0, '' => 0);
    foreach ($activos as $p) {
        $nCursos[$p['curso']]++;
        $nEtapas[$p['etapa']]++;
    }

    return array(
        'activos' => $activos,
        'fuera' => $apagados,
        'regMap' => $regMap,
        'cursos' => array_filter($nCursos),
        'etapas' => array_filter($nEtapas),
    );
}

/**
 * El grupo de la delegación de cada persona (participante, vigente), para el
 * enlace a su ficha, que es por grupo. Sale del mapa de relaciones, que ya
 * está cacheado: cero llamadas propias.
 *
 * @return array contactId => array('id', 'code')
 */
function sticpa_pl_ev_grupos_de($objSCP)
{
    $groups = sticpa_pl_groups($objSCP);
    $out = array();
    foreach (sticpa_pl_all_relationships($objSCP) as $rel) {
        if (!isset($rel['role']) || $rel['role'] !== 'participante') {
            continue;
        }
        $cid = isset($rel['person']['id']) ? (string) $rel['person']['id'] : '';
        $gid = isset($rel['group_id']) ? (string) $rel['group_id'] : '';
        if ($cid === '' || $gid === '' || isset($out[$cid]) || !isset($groups[$gid])) {
            continue;
        }
        $out[$cid] = array('id' => $gid, 'code' => (string) $groups[$gid]['code']);
    }
    return $out;
}

/**
 * Qué sesión se marca: la de hoy si la hay; si no, la regla de los sábados
 * (la última que ya pasó, o la próxima). Es la misma
 * `sticpa_pl_pick_session()`, que ya resuelve lo que importa aquí: en un
 * evento de tres días, el autobús de ida cae en la sesión del primero y el de
 * vuelta en la del último.
 */
function sticpa_pl_ev_pick($sessions, $nowTs = null)
{
    return sticpa_pl_pick_session($sessions, $nowTs);
}

/** «2026-10-10» si es una fecha válida; '' si no. */
function sticpa_pl_ev_dia($raw)
{
    $raw = (string) $raw;
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        return '';
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $raw : '';
}

/**
 * La sesión de un día para un evento SIN sesiones: la busca y, si no está, la
 * crea.
 *
 * Antes de crear se releen las sesiones SIN caché: si la lista se guardó dos
 * veces —la cola del móvil reenviando, dos monitores a la vez—, la segunda
 * encuentra la de la primera en vez de duplicarla.
 *
 * Hora: la del evento si ese día empieza (o acaba) el evento; si no, las 9:00.
 * Dura una hora (o lo que quede hasta el fin del evento ese día): es la lista
 * de un momento, no la jornada entera.
 *
 * @return array|null la sesión, con la forma de sticpa_pl_event_sessions().
 */
function sticpa_pl_ev_sesion_del_dia($objSCP, $event, $dia)
{
    $dia = sticpa_pl_ev_dia($dia);
    if ($dia === '' || empty($event['id'])) {
        return null;
    }
    if (function_exists('delete_transient')) {
        delete_transient(sticpa_pl_cache_key('sessions', $objSCP, $event['id']));
    }
    foreach (sticpa_pl_event_sessions($objSCP, $event['id']) as $s) {
        if (date('Y-m-d', (int) $s['start']) === $dia) {
            return $s;
        }
    }

    $start = strtotime($dia . ' 09:00:00');
    if (date('Y-m-d', (int) $event['start']) === $dia) {
        $start = (int) $event['start'];
    } elseif (date('Y-m-d', (int) $event['end']) === $dia) {
        $start = (int) $event['end'] - HOUR_IN_SECONDS;
    }
    $end = $start + HOUR_IN_SECONDS;
    if ((int) $event['end'] > $start && date('Y-m-d', (int) $event['end']) === $dia) {
        $end = min($end, (int) $event['end']);
    }
    if ($end <= $start) {
        $end = $start + HOUR_IN_SECONDS;
    }

    $name = sprintf(
        /* translators: 1: nombre del evento, 2: fecha corta (10/10) */
        __('%1$s · lista del %2$s', 'sticpa'),
        $event['name'],
        date_i18n('j/n', $start)
    );
    $id = $objSCP->set_entry('stic_Sessions', array(
        'name' => $name,
        'start_date' => date('Y-m-d H:i:s', $start),
        'end_date' => date('Y-m-d H:i:s', $end),
        'assigned_user_id' => sticpa_pl_delegation($objSCP),
        // El enlace también en el propio registro (§3.1-bis del parte de
        // estado), y el set_relationship de siempre detrás.
        'stic_sessions_stic_eventsstic_events_ida' => sticpa_pl_safe_id($event['id']),
    ));
    if (!$id) {
        return null;
    }
    $objSCP->set_relationship('stic_Sessions', $id, 'stic_sessions_stic_events', array($event['id']));
    if (function_exists('delete_transient')) {
        delete_transient(sticpa_pl_cache_key('sessions', $objSCP, $event['id']));
    }
    return array('id' => (string) $id, 'name' => $name, 'start' => $start, 'end' => $end);
}

/**
 * GUARDA LA LISTA DEL EVENTO: las asistencias de lo marcado, en la sesión.
 *
 * No escribe `LIS_listas`: esas son las listas de los GRUPOS de los sábados y
 * su recuento alimenta el Resumen de coordinación; meter ahí la del autobús
 * descuadraría los números. La constancia de la lista son las asistencias.
 *
 * @param array $marks contactId => estado (solo personas activas del evento)
 * @param array $notes contactId => motivo
 * @return array('saved', 'failed', 'errors', 'counts' => array('yes', 'no'))
 */
function sticpa_pl_ev_guardar($objSCP, $eventId, $session, $marks, &$regMap, $notes = array())
{
    $result = array('saved' => 0, 'failed' => 0, 'errors' => array(), 'counts' => array('yes' => 0, 'no' => 0), 'lista_id' => '');
    if (empty($session['id'])) {
        $result['failed']++;
        $result['errors'][] = array('paso' => 'sesion', 'id' => '', 'error' => sticpa_pl_crm_error($objSCP));
        return $result;
    }
    $states = sticpa_pl_states();
    foreach ($marks as $key) {
        if ($states[$key]['counts']) {
            $result['counts']['yes']++;
        } else {
            $result['counts']['no']++;
        }
    }

    $existing = sticpa_pl_session_attendances($objSCP, $session['id'], $regMap);
    $escritas = sticpa_pl_write_attendances(
        $objSCP,
        $session['id'],
        $marks,
        $existing,
        $regMap,
        $eventId,
        isset($session['start']) ? (int) $session['start'] : 0,
        (array) $notes
    );
    foreach ($marks as $cid => $key) {
        $res = isset($escritas[$cid]) ? $escritas[$cid] : null;
        if (is_array($res) && $res['ok']) {
            $result['saved']++;
            continue;
        }
        $result['failed']++;
        if (is_array($res) && !empty($res['error'])) {
            $result['errors'][] = $res['error'];
        }
    }

    sticpa_pl_flush($objSCP, 'state');
    return $result;
}

// ===========================================================================
// PINTADO
// ===========================================================================

/** La URL de la lista de un evento (y, si se da, de una sesión suya). */
function sticpa_pl_ev_url($eventId = '', $sessionId = '')
{
    $url = '?internalpage=single_stic_pasar_lista_evento';
    if ((string) $eventId !== '') {
        $url .= '&evento=' . rawurlencode((string) $eventId);
    }
    if ((string) $sessionId !== '') {
        $url .= '&sesion=' . rawurlencode((string) $sessionId);
    }
    return $url;
}

/** «10 – 12 oct», «10 oct» o «28 sep – 2 oct»: las fechas de un evento, cortas. */
function sticpa_pl_ev_fechas($ev)
{
    $start = (int) $ev['start'];
    $end = (int) $ev['end'];
    if ($end <= 0 || date('Y-m-d', $start) === date('Y-m-d', $end)) {
        return date_i18n('j M', $start);
    }
    if (date('Y-m', $start) === date('Y-m', $end)) {
        return date_i18n('j', $start) . ' – ' . date_i18n('j M', $end);
    }
    return date_i18n('j M', $start) . ' – ' . date_i18n('j M', $end);
}

/** «Hoy empieza», «Hoy termina», «En marcha»: qué es hoy para el evento. */
function sticpa_pl_ev_hoy_texto($ev)
{
    switch (isset($ev['dia']) ? $ev['dia'] : '') {
        case 'start':
            // Un evento de un solo día empieza y acaba hoy: «Hoy» a secas.
            return (date('Y-m-d', (int) $ev['start']) === date('Y-m-d', (int) $ev['end']))
                ? __('Hoy', 'sticpa')
                : __('Hoy empieza', 'sticpa');
        case 'end':
            return __('Hoy termina', 'sticpa');
        case 'during':
            return __('En marcha', 'sticpa');
    }
    return '';
}

/**
 * LA TARJETA DEL EVENTO DE HOY: entera es el enlace a su lista.
 *
 * Superficie neutra con un filete de marca, NO otro bloque de degradado: en la
 * portada de Pasar Lista convive con la tarjeta del sábado, y dos degradados
 * seguidos es lo que design.md §3 no quiere. La cápsula de fecha sí es la de
 * siempre (§11 del sistema de diseño).
 */
function sticpa_pl_ev_hoy_card_html($ev)
{
    $html = '<a class="pl-evhoy" href="' . esc_url(sticpa_pl_ev_url($ev['id'])) . '">';
    $html .= sticpa_pl_date_capsule($ev['start'], 'pl-evhoy-date');
    $html .= '<span class="pl-evhoy-body">';
    $html .= '<span class="pl-evhoy-when">' . esc_html(sticpa_pl_ev_hoy_texto($ev)) . '</span>';
    $html .= '<span class="pl-evhoy-name">' . esc_html($ev['name']) . '</span>';
    $html .= '<span class="pl-evhoy-cta">' . esc_html__('Pasar lista', 'sticpa') . sticpa_pl_icon('next') . '</span>';
    $html .= '</span>';
    $html .= '</a>';
    return $html;
}

/**
 * Los chips del filtro por curso: «Todos 34 · 1º ESO 12 · 2º ESO 9…».
 *
 * Botones de verdad (`aria-pressed`), con el número de cada curso, en una fila
 * que se desliza en horizontal en el móvil. Solo si hay más de un curso: con
 * uno, filtrar no filtra nada.
 */
function sticpa_pl_ev_chips_html($nCursos, $total)
{
    if (count($nCursos) < 2) {
        return '';
    }
    $cursos = sticpa_pl_ev_cursos();
    $html = '<div class="pl-evchips" role="group" aria-label="' . esc_attr__('Filtrar por curso', 'sticpa') . '">';
    $html .= '<button type="button" class="pl-evchip" data-pl-ev-curso="" aria-pressed="true">'
        . esc_html__('Todos', 'sticpa') . '<span class="pl-evchip-n">' . (int) $total . '</span></button>';
    foreach ($nCursos as $k => $n) {
        $label = isset($cursos[$k]) ? $cursos[$k] : __('Sin curso', 'sticpa');
        $html .= '<button type="button" class="pl-evchip" data-pl-ev-curso="' . esc_attr($k === '' ? '-' : $k) . '" aria-pressed="false">'
            . esc_html($label) . '<span class="pl-evchip-n">' . (int) $n . '</span></button>';
    }
    $html .= '</div>';
    return $html;
}

/** El nombre de una etapa para el título de su sección. */
function sticpa_pl_ev_etapa_titulo($etapa)
{
    return ($etapa === '') ? __('Sin etapa', 'sticpa') : $etapa;
}

/**
 * Una fila de la lista que se marca: la de los sábados, con dos datos más
 * para el filtro (curso y texto de búsqueda) y, debajo del nombre, el curso y
 * el grupo, que es lo que se pregunta en la puerta del autobús.
 */
function sticpa_pl_ev_row_html($p, $state, $motive)
{
    $sub = array();
    if ($p['curso_label'] !== '') {
        $sub[] = $p['curso_label'];
    }
    if ($p['grupo_code'] !== '') {
        $sub[] = $p['grupo_code'];
    }
    // La ficha (teléfonos de casa, salud) es por grupo: solo si tiene uno de
    // la delegación. Desde la hoja de estados está a un toque.
    $ficha = ($p['grupo_id'] !== '')
        ? '?internalpage=single_stic_pasar_lista_ficha&participante=' . rawurlencode($p['id'])
            . '&grupo=' . rawurlencode($p['grupo_id'])
        : '';
    $row = sticpa_pl_row_html($p, $state, 0, $ficha, implode(' · ', $sub), $motive);
    $hay = $p['full'] . ' ' . $p['name'] . ' ' . $p['curso_label'] . ' ' . $p['grupo_code'];
    // En la PRIMERA etiqueta, sea cual sea: con ficha, la fila llega envuelta
    // en `.pl-rowwrap` (el botón y la flecha), y es ese envoltorio lo que el
    // filtro tiene que esconder entero.
    return preg_replace(
        '/^<([a-z]+)/',
        '<$1 data-pl-ev-curso="' . esc_attr($p['curso'] === '' ? '-' : $p['curso']) . '"'
            . ' data-pl-ev-hay="' . esc_attr($hay) . '"',
        $row,
        1
    );
}

/**
 * Las inscripciones canceladas (o parecidas), al final y apagadas. No son
 * botones: no se marcan. Están para contestar «¿y fulanito?» sin salir.
 */
function sticpa_pl_ev_fuera_html($fuera)
{
    if (empty($fuera)) {
        return '';
    }
    $html = '<div class="pl-etapa-title pl-evsec" data-pl-ev-sec>'
        . '<span>' . esc_html__('No van', 'sticpa') . '</span>'
        . '<span class="pl-etapa-count">' . count($fuera) . '</span></div>';
    $html .= '<div class="pl-list pl-evfuera" data-pl-ev-list>';
    foreach ($fuera as $p) {
        $sub = array();
        if ($p['curso_label'] !== '') {
            $sub[] = $p['curso_label'];
        }
        $html .= '<div class="pl-evout" data-pl-ev-curso="' . esc_attr($p['curso'] === '' ? '-' : $p['curso']) . '"'
            . ' data-pl-ev-hay="' . esc_attr($p['full'] . ' ' . $p['curso_label']) . '">';
        $html .= sticpa_pl_avatar_html($p);
        $html .= '<span class="pl-evout-body"><span class="pl-evout-name">' . esc_html($p['name']) . '</span>';
        if (!empty($sub)) {
            $html .= '<span class="pl-evout-sub">' . esc_html(implode(' · ', $sub)) . '</span>';
        }
        $html .= '</span>';
        $html .= '<span class="stic-rec-chip stic-rec-chip--past">' . esc_html($p['status_label']) . '</span>';
        $html .= '</div>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * El desplegable de sesiones del evento, si tiene más de una (un campamento
 * de cinco días son cinco listas). Mismo componente que el de los sábados:
 * la pastilla con un <select> nativo encima que navega al elegir.
 */
function sticpa_pl_ev_sesiones_html($eventId, $sessions, $currentId)
{
    if (count($sessions) < 2) {
        return '';
    }
    $out = '<span class="pl-session-pick pl-session-pick--select">';
    $out .= '<select data-pl-session-jump aria-label="' . esc_attr__('Elegir el día', 'sticpa') . '">';
    $current = '';
    foreach ($sessions as $s) {
        $label = date_i18n('D j M · H:i', (int) $s['start']);
        $sel = ((string) $s['id'] === (string) $currentId);
        if ($sel) {
            $current = date_i18n('D j', (int) $s['start']);
        }
        $out .= '<option value="' . esc_url(sticpa_pl_ev_url($eventId, $s['id'])) . '"' . ($sel ? ' selected' : '') . '>'
            . esc_html($label) . '</option>';
    }
    $out .= '</select>';
    $out .= '<span class="pl-session-pick-text">' . esc_html($current) . '</span>';
    $out .= sticpa_pl_icon('down');
    $out .= '</span>';
    return $out;
}
