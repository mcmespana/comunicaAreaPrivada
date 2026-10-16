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
 * Los cursos escolares de `ajmcm_curso_escolar_c_list`, en su orden y con el
 * nombre que se lee debajo de cada persona y en los subtítulos de la lista
 * («4 EP · 19»). Las CLAVES son el dominio completo leído en Studio
 * (docs/comunica/CAMPOS.md, «Relaciones con personas»); los nombres son de
 * esta pantalla, cortos como se dicen en la casa («4 EP», «1 ESO»): caben en
 * un chip a 375 px y se leen igual debajo del nombre (el CRM dice «4º» a
 * secas para primaria, y suelto no se entiende). `na` y el vacío son «sin
 * curso».
 */
function sticpa_pl_ev_cursos()
{
    return array(
        '3_primaria' => __('3 EP', 'sticpa'),
        '4_primaria' => __('4 EP', 'sticpa'),
        '5_primaria' => __('5 EP', 'sticpa'),
        '6_primaria' => __('6 EP', 'sticpa'),
        '1_eso' => __('1 ESO', 'sticpa'),
        '2_eso' => __('2 ESO', 'sticpa'),
        '3_eso' => __('3 ESO', 'sticpa'),
        '4_eso' => __('4 ESO', 'sticpa'),
        '1_bachillerato' => __('1 Bach', 'sticpa'),
        '2_bachillerato' => __('2 Bach', 'sticpa'),
        'fp_gm' => __('FP GM', 'sticpa'),
        'fp_gs' => __('FP GS', 'sticpa'),
        'universitario' => __('Universitario', 'sticpa'),
        'otros' => __('Otros', 'sticpa'),
    );
}

/**
 * Un texto de curso que entiende `sticpa_pl_curso_rank()` para una clave
 * (`4_primaria` → «4 Primaria»). Lo usan el ORDEN y el COLOR de la vista por
 * curso de Grupos y fichas, que salen del mismo rank (MIS-GRUPOS.md §5): la
 * etiqueta corta («4 EP») no dice «primaria» y caería al final.
 */
function sticpa_pl_curso_key_texto($key)
{
    $key = sticpa_pl_ev_curso_key($key);
    if ($key === '' || $key === 'otros') {
        return '';
    }
    if (preg_match('/^(\d)_(primaria|eso|bachillerato)$/', $key, $m)) {
        return $m[1] . ' ' . ucfirst($m[2]);
    }
    $fijos = array('fp_gm' => 'FP 1', 'fp_gs' => 'FP 2', 'universitario' => 'Universitario');
    return isset($fijos[$key]) ? $fijos[$key] : '';
}

/**
 * Lo contrario: la clave de `ajmcm_curso_escolar_c_list` que corresponde al
 * texto libre del grupo (`cursos_c`: «3 ESO», «1º BACHILLER», «4º Primaria»).
 * '' si no se reconoce. Sirve para que en la vista por curso el de la relación
 * y el del grupo caigan bajo la MISMA cabecera, y no en dos «3 ESO».
 */
function sticpa_pl_curso_key_de_texto($txt)
{
    $txt = trim((string) $txt);
    if ($txt === '') {
        return '';
    }
    if (sticpa_pl_ev_curso_key($txt) !== '') {
        return $txt;   // ya es una clave
    }
    $rank = sticpa_pl_curso_rank($txt);
    $n = $rank % 50;
    $key = '';
    if ($rank >= 100 && $rank < 200) {
        $key = $n . '_primaria';
    } elseif ($rank >= 200 && $rank < 300) {
        $key = $n . '_eso';
    } elseif ($rank >= 300 && $rank < 350) {
        $key = $n . '_bachillerato';
    } elseif ($rank >= 350 && $rank < 400) {
        $low = function_exists('mb_strtolower') ? mb_strtolower($txt, 'UTF-8') : strtolower($txt);
        $key = (strpos($low, 'gs') !== false || strpos($low, 'superior') !== false) ? 'fp_gs' : 'fp_gm';
    } elseif ($rank >= 400 && $rank < 500) {
        $key = 'universitario';
    }
    return sticpa_pl_ev_curso_key($key);
}

/** La clave de curso tal cual se usa aquí: '' si no hay o es «no aplica». */
function sticpa_pl_ev_curso_key($raw)
{
    $raw = trim((string) $raw);
    $cursos = sticpa_pl_ev_cursos();
    return isset($cursos[$raw]) ? $raw : '';
}

/**
 * La etapa con la que se agrupa a una persona en la lista. Por este orden:
 *
 *   1. DEDUCIDA DEL CURSO, con la equivalencia de la casa: primaria es MIC;
 *      ESO, Bachillerato y FP son COM (las sesiones semanales del MIC están
 *      marcadas de 4.º a 6.º de primaria, y las del COM de 1.º de ESO en
 *      adelante). El curso de ESTE año es el dato vivo.
 *   2. La del GRUPO de la persona (`sticpa_pl_groups()`, resuelta de `level`).
 *   3. La de su ficha (`Contacts.ajmcm_etapa_c`).
 *   4. '' («Sin etapa»): mejor que adivinar.
 *
 * Por qué la ficha va la última: se queda vieja. En la convivencia del MIC de
 * Castellón (11/10/2026) 20 de 47 chavales de 5.º y 6.º de primaria tenían
 * COM en la ficha, y salían en la sección del COM. Universitario y «otros» no
 * deducen nada del curso: pasan al grupo y a la ficha.
 */
function sticpa_pl_ev_etapa($cursoKey, $grupoEtapa = '', $fichaEtapa = '')
{
    $c = (string) $cursoKey;
    if (substr($c, -9) === '_primaria') {
        return 'MIC';
    }
    if (substr($c, -4) === '_eso' || substr($c, -13) === '_bachillerato' || $c === 'fp_gm' || $c === 'fp_gs') {
        return 'COM';
    }
    foreach (array($grupoEtapa, $fichaEtapa) as $e) {
        $e = strtoupper(trim((string) $e));
        if (in_array($e, array('MIC', 'COM', 'LC'), true)) {
            return $e;
        }
    }
    return '';
}

/**
 * La relación de PARTICIPANTE vigente que manda para cada persona: la de
 * `start` más reciente entre las que cumplen `$acepta`.
 *
 * PURA. Por qué hace falta: «vigente» es «sin fecha de fin o con fin
 * futuro», y en la delegación hay una tanda vieja de relaciones del curso
 * pasado que nadie cerró (148 en Castellón el 11/10/2026: empiezan antes de
 * agosto y no tienen `end_date`). Una persona puede tener DOS vigentes —la
 * vieja con 6.º de primaria y la nueva con 1.º de ESO— y quedarse con la
 * primera que sale es quedarse con la que diga el orden del CRM. La de inicio
 * más reciente es la de este curso. A igual inicio, la primera (las dos son
 * la misma relación duplicada, pasa).
 *
 * Solo `role` 'participante' (`participante_mic_com` y `grupo`): las de
 * monitor llevan el curso y el grupo de lo que la persona LLEVA.
 *
 * @param array         $rels   filas de sticpa_pl_all_relationships()
 * @param callable|null $acepta fila => bool; solo cuentan las que la cumplen
 *                              (con curso, con grupo de la delegación…)
 * @return array contactId => fila de relación
 */
function sticpa_pl_ev_rel_reciente($rels, $acepta = null)
{
    $out = array();
    foreach ((array) $rels as $rel) {
        if (!isset($rel['role']) || $rel['role'] !== 'participante') {
            continue;
        }
        if (isset($rel['vigente']) && !$rel['vigente']) {
            continue;
        }
        $cid = isset($rel['person']['id']) ? (string) $rel['person']['id'] : '';
        if ($cid === '' || ($acepta !== null && !call_user_func($acepta, $rel))) {
            continue;
        }
        $start = isset($rel['start']) ? (int) $rel['start'] : 0;
        if (!isset($out[$cid]) || $start > (int) $out[$cid]['start']) {
            $rel['start'] = $start;
            $out[$cid] = $rel;
        }
    }
    return $out;
}

/**
 * El CURSO escolar de cada persona, de su relación de participante vigente
 * más reciente QUE LO TENGA (`ajmcm_curso_escolar_c` de la relación).
 *
 * PURA. Existe porque el curso de la inscripción llega vacío (leído por MCP
 * en las 90 inscripciones de las convivencias de Castellón, 11/10/2026), y el
 * de la relación sí está: 83 de 90. Sale del mapa de relaciones de la
 * delegación, ya cacheado: cero llamadas.
 *
 * @return array contactId => clave de curso ('5_primaria')
 */
function sticpa_pl_ev_cursos_de($rels)
{
    $out = array();
    $elegidas = sticpa_pl_ev_rel_reciente($rels, function ($rel) {
        return isset($rel['curso_escolar']) && sticpa_pl_ev_curso_key($rel['curso_escolar']) !== '';
    });
    foreach ($elegidas as $cid => $rel) {
        $out[$cid] = sticpa_pl_ev_curso_key($rel['curso_escolar']);
    }
    return $out;
}

/**
 * El curso de UNA persona a partir de SUS relaciones en crudo (las de
 * sticpa_pl_mis_rels(): filas con `name_value_list`). Es para quien viene de
 * OTRA delegación a un evento local: sus relaciones no están en el mapa de la
 * delegación que organiza. Misma regla que sticpa_pl_ev_cursos_de(): solo de
 * participante, vigentes, con curso, y la de inicio más reciente.
 *
 * PURA. @return string clave de curso o ''.
 */
function sticpa_pl_ev_curso_de_rels($rows, $nowTs = null)
{
    $hoy = date('Y-m-d', ($nowTs === null) ? sticpa_pl_now() : (int) $nowTs);
    $mejor = '';
    $mejorInicio = null;
    foreach ((array) $rows as $row) {
        $v = isset($row->name_value_list) ? $row->name_value_list : null;
        if (!$v) {
            continue;
        }
        $val = function ($k) use ($v) {
            return isset($v->$k->value) ? trim((string) $v->$k->value) : '';
        };
        if (sticpa_pl_rel_role($val('relationship_type')) !== 'participante' || $val('active') === '0') {
            continue;
        }
        $fin = $val('end_date');
        if ($fin !== '' && $fin < $hoy) {
            continue;
        }
        $curso = sticpa_pl_ev_curso_key($val('ajmcm_curso_escolar_c'));
        if ($curso === '') {
            continue;
        }
        $inicio = $val('start_date');
        if ($mejorInicio === null || $inicio > $mejorInicio) {
            $mejor = $curso;
            $mejorInicio = $inicio;
        }
    }
    return $mejor;
}

/**
 * ¿Es del EQUIPO (monitor/a o acompañamiento) según sus propias relaciones?
 * Sí si tiene una relación vigente de monitor o de acompañamiento, AUNQUE
 * tenga también una de participante: esas MANDAN por encima (decidido por el
 * propietario el 16/10/2026, cuando a los organizadores de las convivencias
 * de Castellón se les crearon relaciones de participante sin querer).
 *
 * PURA.
 */
function sticpa_pl_ev_es_monitor_de_rels($rows, $nowTs = null)
{
    $hoy = date('Y-m-d', ($nowTs === null) ? sticpa_pl_now() : (int) $nowTs);
    foreach ((array) $rows as $row) {
        $v = isset($row->name_value_list) ? $row->name_value_list : null;
        if (!$v) {
            continue;
        }
        $fin = isset($v->end_date->value) ? trim((string) $v->end_date->value) : '';
        $activa = isset($v->active->value) ? trim((string) $v->active->value) : '';
        if (($fin !== '' && $fin < $hoy) || $activa === '0') {
            continue;
        }
        $tipo = isset($v->relationship_type->value) ? strtolower((string) $v->relationship_type->value) : '';
        if (sticpa_pl_rel_role($tipo) === 'monitor' || strpos($tipo, 'acompanamiento') !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Los `participation_type` de una inscripción que NO son chavales: van en el
 * pliegue de «Monitores» al final. `organizer` (monitores) visto en datos el
 * 16/10/2026; `equipo` lo añadió el propietario ese mismo día al desplegable
 * («por si me interesa meter ese tipo de gente»). `attendant`, el resto.
 */
function sticpa_pl_ev_papeles_equipo()
{
    return (array) apply_filters('sticpa_pl_ev_papeles_equipo', array('organizer', 'equipo'));
}

/** «MCM Burriana» → «Burriana». Sin nombre, «Otra delegación». */
function sticpa_pl_ev_deleg_nombre($name)
{
    $n = trim(preg_replace('/^MCM\s+/i', '', trim((string) $name)));
    return ($n !== '') ? $n : __('Otra delegación', 'sticpa');
}

/**
 * La lista del evento con todo lo que hace falta, para las dos pantallas que
 * la pintan (marcar y las pestañas de grupos de actividad).
 *
 * EL CURSO SALE SIEMPRE DE LAS RELACIONES DE CADA PERSONA (16/10/2026): las
 * suyas, por el enlace del contacto (sticpa_pl_mis_rels(), la misma consulta
 * que se hace al entrar al área), y no del mapa de la delegación. En
 * producción el mapa dejó sin curso a gente que sí lo tenía en su relación
 * vigente (Ana Ayora: 4.º ESO desde el 25/09, comprobado por MCP), y un curso
 * que falta en la lista del autobús es un chaval en la sección equivocada.
 * Una consulta por persona, en UNA tanda (la página ya las pide junto con los
 * nombres) y cacheada 24 h. El mapa queda de respaldo si su consulta falla.
 * Quien viene de otra delegación sale igual: sus relaciones son suyas. Y de
 * ellas sale también quién es MONITOR, que va aparte, al final.
 */
function sticpa_pl_ev_lista_completa($objSCP, $inscripciones, $personas)
{
    $miDeleg = (string) sticpa_pl_delegation($objSCP);
    $relaciones = sticpa_pl_all_relationships($objSCP);
    $cursos = sticpa_pl_ev_cursos_de($relaciones);   // respaldo
    $monitores = array();
    $ids = array_keys((array) $personas);
    if (!empty($ids)) {
        sticpa_pl_prime($objSCP, function () use ($objSCP, $ids) {
            foreach ($ids as $cid) {
                sticpa_pl_mis_rels($objSCP, $cid);
            }
        });
        foreach ($ids as $cid) {
            $rels = sticpa_pl_mis_rels($objSCP, $cid);
            $c = sticpa_pl_ev_curso_de_rels($rels);
            if ($c !== '') {
                $cursos[(string) $cid] = $c;
            }
            if (sticpa_pl_ev_es_monitor_de_rels($rels)) {
                $monitores[(string) $cid] = true;
            }
        }
    }
    return sticpa_pl_ev_lista(
        $inscripciones,
        $personas,
        sticpa_pl_ev_grupos_de($relaciones, sticpa_pl_groups($objSCP)),
        $cursos,
        $miDeleg,
        $monitores
    );
}

/**
 * El GRUPO de la delegación de cada persona, de su relación de participante
 * vigente más reciente con un grupo de la delegación: para el enlace a su
 * ficha (que es por grupo), su código debajo del nombre y su etapa.
 *
 * PURA. Misma elección que el curso, y por lo mismo: antes se quedaba con la
 * primera relación que salía, y con la tanda vieja sin cerrar podía ser el
 * grupo del curso pasado.
 *
 * @param array $rels   filas de sticpa_pl_all_relationships()
 * @param array $groups sticpa_pl_groups()
 * @return array contactId => array('id', 'code', 'etapa')
 */
function sticpa_pl_ev_grupos_de($rels, $groups)
{
    $groups = (array) $groups;
    $out = array();
    $elegidas = sticpa_pl_ev_rel_reciente($rels, function ($rel) use ($groups) {
        $gid = isset($rel['group_id']) ? (string) $rel['group_id'] : '';
        return $gid !== '' && isset($groups[$gid]);
    });
    foreach ($elegidas as $cid => $rel) {
        $g = $groups[(string) $rel['group_id']];
        $out[$cid] = array(
            'id' => (string) $rel['group_id'],
            'code' => isset($g['code']) ? (string) $g['code'] : '',
            'etapa' => isset($g['etapa']) ? (string) $g['etapa'] : '',
        );
    }
    return $out;
}

/**
 * La clave con la que se ordena por nombre: apellidos y luego nombre, en
 * minúsculas y sin acentos. «Álvarez» va con la A y no detrás de la Z.
 *
 * Sin `iconv` a propósito (es lo que usa `sticpa_pl_sort_key()`): su
 * transliteración depende del locale del servidor y con el «C» de un PHP-FPM
 * convierte «Á» en «?», que se ordena delante de todo. Una tabla fija no
 * depende de nada. La ñ va detrás de la n, como en el diccionario.
 */
function sticpa_pl_ev_clave_nombre($last, $first, $full = '')
{
    $s = trim(trim((string) $last) . ' ' . trim((string) $first));
    if ($s === '') {
        $s = trim((string) $full);
    }
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    $s = strtr($s, array(
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ç' => 'c', 'ñ' => 'n~', 'l·l' => 'll', '·' => '',
    ));
    return preg_replace('/\s+/', ' ', $s);
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
        // `participation_type`: `organizer` (monitores, coordinación…) o
        // `attendant` (los chavales). Visto en datos el 16/10/2026 (CAMPOS.md).
        'related_fields' => array('id', 'status', 'ajmcm_curso_escolar_c', 'stic_registrations_contactscontacts_ida', 'participation_type'),
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
                'papel' => isset($v->participation_type->value) ? strtolower(trim((string) $v->participation_type->value)) : '',
            );
        }
    }

    if ($sinContacto) {
        // El mapa de siempre (solo las no canceladas) con su respaldo.
        $map = sticpa_pl_event_registrations($objSCP, $eventId);
        $faltan = array();
        foreach ($out as $i => $r) {
            if ($r['contact_id'] === '' && isset($map[$r['reg_id']])) {
                $out[$i]['contact_id'] = sticpa_pl_safe_id($map[$r['reg_id']]);
            }
            if ($out[$i]['contact_id'] === '') {
                $faltan[] = $r['reg_id'];
            }
        }
        // Y lo que el mapa no sabe —está cacheado 24 h, y quien se apuntó
        // después no está; o el CRM no lo dijo—, preguntado a cada inscripción
        // (16/10/2026: en la convivencia de Castellón faltaban los tres de
        // Burriana, apuntados del 8 al 11, y los monitores). En una tanda.
        if (!empty($faltan)) {
            sticpa_pl_prime($objSCP, function () use ($objSCP, $faltan) {
                foreach ($faltan as $regId) {
                    sticpa_pl_ev_contacto_de_inscripcion($objSCP, $regId);
                }
            });
            foreach ($out as $i => $r) {
                if ($r['contact_id'] === '') {
                    $out[$i]['contact_id'] = sticpa_pl_ev_contacto_de_inscripcion($objSCP, $r['reg_id']);
                }
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
 * La persona de UNA inscripción, preguntada a la propia inscripción
 * (`stic_Registrations` → `stic_registrations_contacts`). Es la consulta
 * primaria de un enlace, la que esta instancia sí contesta (PASAR-LISTA-ESTADO
 * §3.1: lo que no devuelve son los enlaces ANIDADOS). Una inscripción no cambia
 * de persona: se cachea como estructura (24 h), y solo lo que se encuentra.
 *
 * @return string id del contacto, o ''.
 */
function sticpa_pl_ev_contacto_de_inscripcion($objSCP, $regId)
{
    $regId = sticpa_pl_safe_id($regId);
    if ($regId === '') {
        return '';
    }
    $cacheKey = sticpa_pl_cache_key('evregc', $objSCP, $regId);
    $ttl = sticpa_pl_ttl_structure();
    if ($ttl > 0) {
        $cached = get_transient($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
    }
    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'stic_Registrations',
        'module_id' => $regId,
        'link_field_name' => 'stic_registrations_contacts',
        'related_fields' => array('id'),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));
    if (sticpa_pl_collecting() || !is_array($rows)) {
        return '';
    }
    foreach ($rows as $row) {
        $id = isset($row->name_value_list->id->value) ? sticpa_pl_safe_id($row->name_value_list->id->value) : '';
        if ($id !== '') {
            sticpa_pl_cache_put($cacheKey, $id, $ttl);
            return $id;
        }
    }
    return '';
}

/**
 * Nombre y etapa de las personas inscritas, en UNA consulta por id.
 *
 * @return array contactId => array('first', 'last', 'full', 'etapa', 'deleg_id', 'deleg_name')
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
        array('id', 'name', 'first_name', 'last_name', 'ajmcm_etapa_c', 'assigned_user_id', 'assigned_user_name')
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
            // La delegación de la persona (su `assigned_user_id`, como todo en
            // el CRM): a un evento local también va gente de otra.
            'deleg_id' => isset($v->assigned_user_id->value) ? (string) $v->assigned_user_id->value : '',
            'deleg_name' => isset($v->assigned_user_name->value) ? (string) $v->assigned_user_name->value : '',
        );
    }
    return $out;
}

/**
 * LA LISTA DEL EVENTO, armada. PURA: inscripciones + personas → lo que se
 * pinta y lo que se puede escribir.
 *
 * Devuelve:
 *   'activos'  — personas que se marcan, por CURSO (en el orden de
 *                sticpa_pl_ev_cursos(), «sin curso» al final) y, dentro, por
 *                apellidos y nombre. La pantalla las parte por etapa sin
 *                deshacer ese orden.
 *   'fuera'    — las de inscripción cancelada o parecida, para el final, por
 *                apellidos.
 *   'regMap'   — inscripción => contacto, SOLO de las activas: es lo que el
 *                guardado puede tocar y lo que traduce las asistencias leídas.
 *   'cursos'   — curso => cuántos activos (en el orden de los cursos; '' es
 *                «sin curso»), para los chips del filtro.
 *   'etapas'   — etapa => cuántos activos (MIC, COM, LC, '').
 *
 * EL CURSO: el de la inscripción si viene (es el de ESE evento); si no, el de
 * la relación de participante de la persona (`$cursosRel`, de
 * sticpa_pl_ev_cursos_de()). Hoy la inscripción no lo trae nunca (CAMPOS.md),
 * pero el día que lo traiga, manda. LA ETAPA, de sticpa_pl_ev_etapa(): el
 * curso, el grupo y, al final, la ficha.
 *
 * Una persona con dos inscripciones (pasa: una cancelada y otra nueva) sale
 * UNA vez, con la activa. Con dos activas, la primera; las dos apuntan al
 * mismo contacto y la asistencia es una por persona y sesión.
 *
 * @param array $grupos    contactId => array('id', 'code', 'etapa') del grupo
 *                         de la delegación de cada persona (sticpa_pl_ev_grupos_de()).
 * @param array $cursosRel contactId => clave de curso (sticpa_pl_ev_cursos_de()).
 * @param string $miDeleg  la delegación del evento: quien es de OTRA lleva en
 *                         'deleg' el nombre de la suya («Burriana») y la
 *                         pantalla lo pinta aparte, al final. '' = de casa.
 * @param array  $monitores contactId => true: relación vigente de monitor o
 *                         de acompañamiento. Con eso, o con `organizer`/`equipo`
 *                         en la inscripción, van en «Monitores», plegados al
 *                         final, y no cuentan en los chips de curso.
 */
function sticpa_pl_ev_lista($inscripciones, $personas, $grupos = array(), $cursosRel = array(), $miDeleg = '', $monitores = array())
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
        $curso = sticpa_pl_ev_curso_key(isset($r['curso']) ? $r['curso'] : '');
        if ($curso === '' && isset($cursosRel[$cid])) {
            $curso = sticpa_pl_ev_curso_key($cursosRel[$cid]);
        }
        $g = isset($grupos[$cid]) ? (array) $grupos[$cid] : array();
        $persona = sticpa_pl_person_array($cid, $p['first'], $p['last'], $p['full'] !== '' ? $p['full'] : __('(sin nombre)', 'sticpa'));
        $persona['orden'] = sticpa_pl_ev_clave_nombre($p['last'], $p['first'], $persona['full']);
        $persona['reg_id'] = (string) $r['reg_id'];
        $persona['status'] = $status;
        $persona['status_label'] = $esFuera ? $fuera[$status] : '';
        $persona['curso'] = $curso;
        $persona['curso_label'] = isset($cursos[$curso]) ? $cursos[$curso] : '';
        $persona['etapa'] = sticpa_pl_ev_etapa($curso, isset($g['etapa']) ? $g['etapa'] : '', $p['etapa']);
        $persona['grupo_id'] = isset($g['id']) ? (string) $g['id'] : '';
        $persona['grupo_code'] = isset($g['code']) ? (string) $g['code'] : '';
        // MONITOR/A (o equipo): lo dice su inscripción (`participation_type`
        // = `organizer` o `equipo`) O una relación vigente de monitor o de
        // acompañamiento (`$monitores`), que manda por encima de una de
        // participante creada de más (pasó el 16/10/2026 con los organizadores).
        $papel = isset($r['papel']) ? (string) $r['papel'] : '';
        $persona['papel'] = $papel;
        $persona['monitor'] = in_array($papel, sticpa_pl_ev_papeles_equipo(), true) || isset($monitores[$cid]);
        if ($persona['monitor']) {
            $persona['curso'] = '';
            $persona['curso_label'] = '';
        }
        $persona['deleg'] = '';
        $dId = isset($p['deleg_id']) ? (string) $p['deleg_id'] : '';
        if ($dId !== '' && (string) $miDeleg !== '' && $dId !== (string) $miDeleg) {
            $persona['deleg'] = sticpa_pl_ev_deleg_nombre(isset($p['deleg_name']) ? $p['deleg_name'] : '');
        }

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

    // El orden de los cursos, con «sin curso» detrás de todos.
    $posCurso = array_flip(array_keys($cursos));
    $posCurso[''] = count($posCurso);
    $porNombre = function ($a, $b) {
        $cmp = strcmp($a['orden'], $b['orden']);
        return ($cmp !== 0) ? $cmp : strcmp($a['id'], $b['id']);   // estable
    };
    $porCurso = function ($a, $b) use ($posCurso, $porNombre) {
        $pa = $posCurso[$a['curso']];
        $pb = $posCurso[$b['curso']];
        return ($pa !== $pb) ? $pa - $pb : $porNombre($a, $b);
    };
    $activos = array_values($activos);
    $apagados = array_values($apagados);
    usort($activos, $porCurso);
    usort($apagados, $porNombre);

    $nCursos = array();
    foreach (array_keys($cursos) as $k) {
        $nCursos[$k] = 0;
    }
    $nCursos[''] = 0;
    $nEtapas = array('MIC' => 0, 'COM' => 0, 'LC' => 0, '' => 0);
    foreach ($activos as $p) {
        if (!empty($p['monitor'])) {
            continue;   // los monitores no son de ningún curso ni etapa
        }
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
 * «CREAR LA SESIÓN DE HOY» en un evento de sesiones semanales (o de reuniones)
 * que todavía no tiene ninguna (16/10/2026, lo pidió coordinación).
 *
 * El curso empieza con el evento creado y sin sesiones —las del sábado las
 * carga la oficina—, y mientras tanto la lista de monitores y la de un grupo
 * decían «Este evento aún no tiene sesiones» y no había nada que hacer. Ahora
 * se puede anotar una asistencia rápida: la sesión de hoy, de una hora desde
 * la hora en punto, con el MISMO creador que la lista de un evento
 * (`sticpa_pl_ev_sesion_del_dia()`), que relee antes de crear para no
 * duplicarla si dos personas lo pulsan a la vez o se reenvía el formulario.
 *
 * Solo atiende su propio POST, con su nonce. Devuelve la sesión creada (o la
 * que ya había hoy), o null si no tocaba o no se ha podido.
 */
function sticpa_pl_crear_sesion_hoy_post($objSCP, $event, $nonceAction)
{
    if (empty($_POST['pl_crear_sesion_hoy']) || empty($event['id'])) {
        return null;
    }
    if (!isset($_POST['pl_nonce']) || !wp_verify_nonce($_POST['pl_nonce'], $nonceAction)) {
        return null;
    }
    $now = sticpa_pl_now();
    $start = $now - ($now % HOUR_IN_SECONDS);
    $hoy = array(
        'id' => (string) $event['id'],
        'name' => isset($event['name']) ? (string) $event['name'] : '',
        'start' => $start,
        'end' => $start + HOUR_IN_SECONDS,
    );
    return sticpa_pl_ev_sesion_del_dia($objSCP, $hoy, date('Y-m-d', $start));
}

/** El botón que lo pide, con su formulario. */
function sticpa_pl_crear_sesion_hoy_html($nonceAction)
{
    return '<form method="post" class="pl-crear-sesion">'
        . wp_nonce_field($nonceAction, 'pl_nonce', true, false)
        . '<button type="submit" name="pl_crear_sesion_hoy" value="1" class="pl-save">'
        . esc_html__('Crear la sesión de hoy', 'sticpa') . '</button>'
        . '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
        . esc_html__('Para anotar una asistencia rápida: se crea una sesión de hoy en este evento. Las de los sábados las carga la oficina.', 'sticpa')
        . '</span></p>'
        . '</form>';
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
 * Los chips del filtro por curso: «Todos 47 · 4 EP 19 · 5 EP 16 · 1 ESO 14…».
 *
 * Botones de verdad (`aria-pressed`), con el número de cada curso, en una fila
 * que se desliza en horizontal en el móvil y que se queda PEGADA ARRIBA al
 * bajar por la lista (css/pasar-lista.css, 042): en la puerta del autobús es
 * «a ver los de primero, pam; los de segundo, pam».
 * Solo si hay más de un curso: con uno, filtrar no filtra nada.
 */
function sticpa_pl_ev_chips_html($nCursos, $total)
{
    if (count($nCursos) < 2) {
        return '';
    }
    $html = '<div class="pl-evchips" data-pl-ev-chips role="group" aria-label="' . esc_attr__('Filtrar por curso', 'sticpa') . '">';
    $html .= '<button type="button" class="pl-evchip" data-pl-ev-curso="" aria-pressed="true">'
        . esc_html__('Todos', 'sticpa') . '<span class="pl-evchip-n">' . (int) $total . '</span></button>';
    foreach ($nCursos as $k => $n) {
        $html .= '<button type="button" class="pl-evchip" data-pl-ev-curso="' . esc_attr($k === '' ? '-' : $k) . '" aria-pressed="false">'
            . esc_html(sticpa_pl_ev_curso_titulo($k)) . '<span class="pl-evchip-n">' . (int) $n . '</span></button>';
    }
    $html .= '</div>';
    return $html;
}

/** El nombre de un curso para su subtítulo: «4 EP» o «Sin curso». */
function sticpa_pl_ev_curso_titulo($k)
{
    $cursos = sticpa_pl_ev_cursos();
    return isset($cursos[$k]) ? $cursos[$k] : __('Sin curso', 'sticpa');
}

/** El nombre de una etapa para el título de su sección. */
function sticpa_pl_ev_etapa_titulo($etapa)
{
    return ($etapa === '') ? __('Sin etapa', 'sticpa') : $etapa;
}

/**
 * Las personas que van, por ETAPA (MIC, COM, LC, «Sin etapa») y, dentro, por
 * CURSO, cada curso con su subtítulo y su número («4 EP · 19»).
 * Dentro de cada curso, por apellidos: es el orden en que llegan de
 * sticpa_pl_ev_lista(), que aquí solo se parte.
 *
 * Los títulos son encabezados (nivel 2 la etapa, 3 el curso) con `role` y no
 * con `<h2>`/`<h3>`: el tema de WordPress pinta los hN a su manera, como pinta
 * los `<button>` (la trampa de PASAR-LISTA-ESTADO.md §3). Cada etapa va en su
 * caja (`data-pl-ev-etapa`) y cada curso en su lista (`data-pl-ev-list`): el
 * JS del filtro esconde y recuenta las dos.
 *
 * @param callable $fila persona => HTML de su fila (lleva el estado leído).
 */
function sticpa_pl_ev_secciones_html($activos, $fila)
{
    // Primero los de la delegación del evento; detrás, los de cada una de las
    // otras (por nombre), cada una partida también por etapa: «COM · Burriana»;
    // y al final, los monitores.
    $vacia = array('MIC' => array(), 'COM' => array(), 'LC' => array(), '' => array());
    $porDeleg = array('' => $vacia);
    $monitores = array();
    foreach ((array) $activos as $p) {
        if (!empty($p['monitor'])) {
            $monitores[] = $p;
            continue;
        }
        $d = isset($p['deleg']) ? (string) $p['deleg'] : '';
        if (!isset($porDeleg[$d])) {
            $porDeleg[$d] = $vacia;
        }
        $porDeleg[$d][isset($vacia[$p['etapa']]) ? $p['etapa'] : ''][] = $p;
    }
    $casa = $porDeleg[''];
    unset($porDeleg['']);
    ksort($porDeleg, SORT_STRING | SORT_FLAG_CASE);
    $bloques = array();
    foreach (array('' => $casa) + $porDeleg as $deleg => $etapas) {
        foreach ($etapas as $etapa => $gente) {
            if (!empty($gente)) {
                $titulo = ($deleg === '') ? sticpa_pl_ev_etapa_titulo($etapa)
                    : (($etapa === '') ? $deleg : $etapa . ' · ' . $deleg);
                $bloques[] = array($titulo, $gente, $deleg !== '');
            }
        }
    }
    // Y los monitores, todos juntos al final (vengan de donde vengan), por
    // apellidos: no son de ningún curso.
    if (!empty($monitores)) {
        usort($monitores, function ($a, $b) {
            return strcmp($a['orden'], $b['orden']);
        });
        $hayEquipo = false;
        foreach ($monitores as $m) {
            if (isset($m['papel']) && $m['papel'] === 'equipo') {
                $hayEquipo = true;
            }
        }
        $bloques[] = array($hayEquipo ? __('Monitores y equipo', 'sticpa') : __('Monitores', 'sticpa'), $monitores, false, true);
    }
    $html = '';
    foreach ($bloques as $b) {
        list($titulo, $gente, $deFuera) = $b;
        $sinCursos = !empty($b[3]);
        if ($sinCursos) {
            // LOS MONITORES: plegados (`<details>`, nativo, sin JS). Se marcan
            // igual, pero no llenan la lista de los chavales; «Están todos»
            // no los toca mientras estén plegados (no se ven).
            $html .= '<details class="pl-fold pl-evmon" data-pl-ev-etapa>'
                . '<summary class="pl-fold-sum pl-evmon-sum">'
                . '<span class="pl-evmon-tit">' . esc_html($titulo) . '</span>'
                . '<span class="pl-etapa-count" data-pl-ev-n>' . count($gente) . '</span></summary>'
                . '<div class="pl-list" data-pl-ev-list>';
            foreach ($gente as $p) {
                $html .= call_user_func($fila, $p);
            }
            $html .= '</div></details>';
            continue;
        }
        $html .= '<div class="pl-evetapa' . ($deFuera ? ' pl-evetapa--fuera' : '') . '" data-pl-ev-etapa>';
        $html .= '<div class="pl-etapa-title pl-evsec" role="heading" aria-level="2"><span>' . esc_html($titulo) . '</span>'
            . '<span class="pl-etapa-count" data-pl-ev-n>' . count($gente) . '</span></div>';
        $porCurso = array();
        foreach ($gente as $p) {
            $porCurso[$p['curso']][] = $p;   // ya vienen en el orden de los cursos
        }
        foreach ($porCurso as $curso => $deEse) {
            $html .= '<div class="pl-evcurso" role="heading" aria-level="3" data-pl-ev-sec>'
                . '<span>' . esc_html(sticpa_pl_ev_curso_titulo($curso)) . '</span>'
                . '<span class="pl-evcurso-n" aria-hidden="true">·</span>'
                . '<span class="pl-evcurso-n" data-pl-ev-n>' . count($deEse) . '</span></div>';
            $html .= '<div class="pl-list" data-pl-ev-list>';
            foreach ($deEse as $p) {
                $html .= call_user_func($fila, $p);
            }
            $html .= '</div>';
        }
        $html .= '</div>';
    }
    return $html;
}

/**
 * Una fila de la lista que se marca: la de los sábados, con dos datos más
 * para el filtro (curso y texto de búsqueda) y, debajo del nombre, en
 * pequeño, el curso entero y el código del grupo («4 EP · M4.1»),
 * que es lo que se pregunta en la puerta del autobús.
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
    if (!empty($p['monitor'])) {
        array_unshift($sub, (isset($p['papel']) && $p['papel'] === 'equipo') ? __('Equipo', 'sticpa') : __('Monitor/a', 'sticpa'));
    }
    $row = sticpa_pl_row_html($p, $state, 0, $ficha, implode(' · ', $sub), $motive);
    // De OTRA delegación: una pastilla de color con el nombre de la suya, que
    // se vea de lejos («Burriana»). Monitor/a: el avatar en el azul de la
    // marca. Los dos, para distinguir de un vistazo sin leer la línea gris.
    $deleg = isset($p['deleg']) ? (string) $p['deleg'] : '';
    if ($deleg !== '') {
        $row = preg_replace(
            '/(<span class="pl-name">.*?<\/span>)/s',
            '$1<span class="pl-marcas"><span class="pl-marca pl-marca--deleg">' . esc_html($deleg) . '</span></span>',
            $row,
            1
        );
    }
    if (!empty($p['monitor'])) {
        $row = preg_replace('/class="pl-avatar"/', 'class="pl-avatar pl-avatar--mon"', $row, 1);
    }
    $hay = $p['full'] . ' ' . $p['name'] . ' ' . $p['curso_label'] . ' ' . $p['grupo_code'] . ' ' . $deleg;
    // En la PRIMERA etiqueta, sea cual sea: con ficha, la fila llega envuelta
    // en `.pl-rowwrap` (el botón y la flecha), y es ese envoltorio lo que el
    // filtro tiene que esconder entero.
    return preg_replace(
        '/^<([a-z]+)/',
        '<$1 data-pl-ev-curso="' . esc_attr(!empty($p['monitor']) ? 'monitor' : ($p['curso'] === '' ? '-' : $p['curso'])) . '"'
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
    $html = '<div class="pl-etapa-title pl-evsec" role="heading" aria-level="2" data-pl-ev-sec>'
        . '<span>' . esc_html__('No van', 'sticpa') . '</span>'
        . '<span class="pl-etapa-count" data-pl-ev-n>' . count($fuera) . '</span></div>';
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
