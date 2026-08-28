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

/**
 * Valores de `relationship_type` que nos interesan de las relaciones.
 *
 * `grupo` es el papel de los +18 en su grupo de referencia (COM y en adelante):
 * no son "participante_mic_com" pero sí cuentan como participantes del grupo a
 * todos los efectos de la lista y del recuento.
 */
function sticpa_pl_rel_types()
{
    return apply_filters('sticpa_pl_rel_types', array(
        'participante' => array('participante_mic_com', 'grupo'),
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
/**
 * Fija (o consulta) la delegación a mano, sin sesión.
 *
 * La usa el calentador de caché: lo llama el Guardián Nocturno desde GitHub
 * Actions, donde no hay ni login ni `$_SESSION`, y tiene que poder decir "ahora
 * trabaja como si fueras de Castellón". Con `null` solo consulta; con `''` se
 * quita. Un estático y no una global para que no se pueda tocar desde fuera por
 * accidente.
 */
function sticpa_pl_delegation_forced($set = null)
{
    static $forced = '';
    if ($set !== null) {
        $forced = (string) $set;
    }
    return $forced;
}

function sticpa_pl_delegation($objSCP)
{
    // Puesta a mano (calentador de caché): manda sobre todo lo demás. Va
    // primero para que ni siquiera se mire la sesión, que en ese contexto es de
    // otro o no existe.
    $forced = sticpa_pl_delegation_forced();
    if ($forced !== '') {
        return $forced;
    }
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
    // La GENERACIÓN va dentro de la clave. Ver sticpa_pl_flush(): es lo que
    // permite invalidar de golpe las cachés cuya clave lleva un id dentro
    // (las personas de un grupo, las inscripciones de un evento…), que no se
    // pueden borrar por nombre porque no se sabe cuáles hay.
    $gen = sticpa_pl_cache_gen(sticpa_pl_cache_family($what), $deleg);
    $key = 'sticpa_pl_' . $what . '_' . md5($gen . '|' . $deleg . '|' . $course['label'] . '|' . $extra);
    return $key;
}

/**
 * A qué familia pertenece cada caché: 'state' o 'struct'.
 *
 * El estado cambia cada sábado y se invalida al guardar una lista. La
 * estructura (grupos, personas de un grupo, quién coordina) cambia cuando
 * alguien toca el CRM, y de eso solo se entera el botón de refrescar.
 */
function sticpa_pl_cache_family($what)
{
    // Lo que se invalida al guardar una lista. El resto es estructura, que es
    // el defecto seguro: colarse en 'state' haría que un dato de estructura se
    // borrase cada cinco minutos y se volviera a pedir al CRM.
    //
    // 'listas' y 'attrange' son los cargadores de colección: LAS LISTAS y LAS
    // ASISTENCIAS de toda la delegación. Son estado puro —cambian justo cuando
    // alguien guarda—, pero al llamarse así caían en 'struct' por el defecto, y
    // `sticpa_pl_flush($objSCP, 'state')` de después de guardar no las tiraba.
    // Se salvaba por el TTL de cinco minutos, o sea que la lista que acababas
    // de pasar tardaba hasta cinco minutos en aparecer.
    $state = array('state', 'streaks', 'listas', 'attrange');
    return in_array((string) $what, $state, true) ? 'state' : 'struct';
}

/**
 * El número de generación de una familia de cachés.
 *
 * Va en `wp_options` y no en un transient a propósito: si se perdiera, el
 * contador volvería a 1 y las claves viejas —que siguen ahí hasta que caduquen—
 * volverían a acertar. Es decir, resucitaría datos ya invalidados.
 */
function sticpa_pl_cache_gen($family, $deleg)
{
    if (!function_exists('get_option')) {
        return 1;
    }
    $gen = (int) get_option(sticpa_pl_cache_gen_option($family, $deleg), 1);
    return ($gen > 0) ? $gen : 1;
}

/** El nombre de la opción donde vive el contador. */
function sticpa_pl_cache_gen_option($family, $deleg)
{
    return 'sticpa_pl_gen_' . preg_replace('/[^a-z]/', '', (string) $family) . '_' . md5((string) $deleg);
}

/** TTL de la estructura: cambia una vez al año, así que se cachea de verdad. */
function sticpa_pl_ttl_structure()
{
    // 24 h y no 12: lo que hay dentro (grupos, personas, eventos, sesiones,
    // inscripciones) cambia a ritmo de curso, y con 12 h la caché caducaba a
    // media tarde del sábado — justo cuando se usa. El calentado nocturno la
    // deja hecha cada madrugada, y el botón de refrescar sigue estando para
    // quien acaba de tocar el CRM y quiere verlo ya.
    return (int) apply_filters('sticpa_pl_ttl_structure', 24 * HOUR_IN_SECONDS);
}

/** TTL del estado: cambia cada sábado y se invalida al guardar. */
function sticpa_pl_ttl_state()
{
    return (int) apply_filters('sticpa_pl_ttl_state', 5 * MINUTE_IN_SECONDS);
}

/**
 * ¿Estamos recolectando peticiones para lanzarlas en paralelo?
 *
 * Mientras se recolecta, los cargadores se ejecutan SIN CRM (ver
 * `SugarRestApiCall::collect()`): salen vacíos a propósito, así que ni se
 * cachea lo que devuelven ni se disparan sus respaldos.
 */
function sticpa_pl_collecting()
{
    return class_exists('SugarRestApiCall') && SugarRestApiCall::isCollecting();
}

/**
 * UNA TANDA: ejecuta los cargadores en paralelo en vez de en fila.
 *
 * Se le pasa una función que llama a los cargadores que hacen falta. Se corre
 * dos veces: la primera en modo recolecta (no toca el CRM, solo apunta qué
 * consultas harían falta — las que ya están en caché no apuntan nada), y con
 * esa lista se lanza UNA tanda paralela. Después, quien llame ejecuta los
 * cargadores de verdad y cada uno encuentra su respuesta ya traída.
 *
 * Por qué así y no con una lista de consultas escrita a mano: los `fields` de
 * cada consulta viven en su cargador y ahí se quedan. Una segunda copia se
 * desincronizaría — y una consulta que pide campos distintos no acierta en el
 * memo, así que se pagaría DOS veces sin que nadie se enterara.
 *
 * Si el hosting no tiene `curl_multi`, o la recolecta no encuentra al menos dos
 * consultas, esto no hace nada y todo sigue funcionando en serie.
 *
 * @return int cuántas respuestas se han dejado listas.
 */
function sticpa_pl_prime($objSCP, callable $cargadores)
{
    // Se le pregunta AL CLIENTE, no a la clase: quien no sepa recolectar sigue
    // funcionando en serie sin enterarse.
    if (!is_object($objSCP)
        || !method_exists($objSCP, 'collectRequests')
        || !method_exists($objSCP, 'callMany')) {
        return 0;
    }
    if (class_exists('SugarRestApiCall') && !SugarRestApiCall::supportsMulti()) {
        return 0;
    }
    if (!apply_filters('sticpa_pl_paralelo', true)) {
        return 0;
    }
    $peticiones = $objSCP->collectRequests($cargadores);
    if (count($peticiones) < 2) {
        return 0;   // una sola consulta no gana nada por ir «en paralelo»
    }
    return (int) $objSCP->callMany($peticiones);
}

/**
 * TTL de un resultado VACÍO. Corto, y por una razón concreta.
 *
 * Una colección vacía puede significar dos cosas muy distintas: «no hay nada»
 * (un grupo sin monitores) o «el CRM no ha contestado» (un tiempo de espera
 * agotado, un 400 por un filtro que rechaza). Se guardaban las dos igual, con
 * el TTL de la estructura: DOCE HORAS. Así, un solo hipo del CRM un sábado a
 * las cinco dejaba el grupo «sin participantes con relación vigente» hasta la
 * madrugada, con el monitor pulsando refrescar sin entender nada — y el mapa de
 * inscripciones vacío, que es lo que impide escribir cualquier asistencia.
 *
 * Con esto, un vacío se reintenta en un par de minutos y un vacío de verdad
 * sigue cacheado (solo se comprueba más a menudo, que es barato).
 */
function sticpa_pl_ttl_empty()
{
    return (int) apply_filters('sticpa_pl_ttl_empty', 2 * MINUTE_IN_SECONDS);
}

/**
 * Guarda en caché con la regla de arriba: lo vacío caduca enseguida.
 *
 * Se usa en los cargadores de COLECCIÓN. Los que guardan un valor envuelto
 * (`array('sig' => …)`, `array('is' => …)`) nunca están vacíos y conservan su
 * TTL completo, que es lo que queremos.
 */
function sticpa_pl_cache_put($key, $value, $ttl)
{
    $ttl = (int) $ttl;
    if ($ttl <= 0) {
        return;
    }
    // Durante la recolecta los cargadores corren sin CRM y salen vacíos:
    // guardar eso dejaría la caché envenenada con vacíos que no son datos.
    if (sticpa_pl_collecting()) {
        return;
    }
    if (is_array($value) && empty($value)) {
        $vacio = sticpa_pl_ttl_empty();
        $ttl = ($vacio > 0 && $vacio < $ttl) ? $vacio : $ttl;
    }
    set_transient($key, $value, $ttl);
}

/**
 * Tira la caché. `$scope`:
 *   'state'  → solo asistencias y listas (lo normal tras guardar)
 *   'all'    → también la estructura (el botón de refrescar de la pantalla)
 */
function sticpa_pl_flush($objSCP = null, $scope = 'state')
{
    if (!function_exists('update_option')) {
        return;
    }
    $deleg = $objSCP ? sticpa_pl_delegation($objSCP) : (isset($_SESSION['scp_pl_delegation']) ? $_SESSION['scp_pl_delegation'] : 'nodeleg');

    // Se SUBE LA GENERACIÓN en vez de borrar transients por nombre.
    //
    // Antes se borraban cuatro claves fijas ('state', 'streaks', 'structure',
    // 'sessions') de las DOCE que se usan, así que el botón de refrescar dejaba
    // intactas las personas de cada grupo, quién coordina, los grupos, las
    // inscripciones… Y esas no se pueden borrar por nombre ni queriendo: su
    // clave lleva dentro el id del grupo o del evento, y no hay forma de saber
    // qué ids hay cacheados. Subiendo un contador que va DENTRO de la clave,
    // todas dejan de acertar a la vez y caducan solas.
    // Y las respuestas que la tanda paralela trajo ANTES de esta escritura: son
    // una foto vieja, y quien las consumiera después las guardaría como frescas
    // con las 24 h por delante.
    if (class_exists('SugarRestApiCall')) {
        SugarRestApiCall::forgetMemo();
    }

    $families = ($scope === 'all') ? array('state', 'struct') : array('state');
    foreach ($families as $family) {
        $option = sticpa_pl_cache_gen_option($family, $deleg);
        $gen = (int) get_option($option, 1);
        update_option($option, ($gen > 0 ? $gen : 1) + 1);
    }
}

/**
 * El botón de refrescar de cualquier pantalla: `?refrescar=1`.
 *
 * Va aquí y no repetido en cada página porque ya pasó: el botón estaba pintado
 * en las cuatro pantallas y el `flush` solo en dos, así que en el árbol de
 * grupos —justo donde más se toca, porque es donde se ve que falta alguien— el
 * botón no hacía NADA. Un botón que no hace nada es peor que no tenerlo: se
 * pulsa, no cambia, y se concluye que el dato del CRM está mal.
 *
 * Tira las DOS familias ('all'), que es lo que hace falta cuando alguien acaba
 * de tocar el CRM y quiere verlo ya: los grupos y las personas son 'struct', y
 * con un flush de 'state' se seguirían viendo los de antes hasta 12 horas.
 *
 * @return bool true si se ha refrescado (para que la pantalla lo pueda decir).
 */
function sticpa_pl_maybe_refresh($objSCP)
{
    if (empty($_REQUEST['refrescar'])) {
        return false;
    }
    sticpa_pl_flush($objSCP, 'all');
    return true;
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
    // Los cuatro campos del recuento nocturno entran GRATIS en esta consulta:
    // es justo por lo que se eligió guardarlos en el grupo en vez de en un
    // módulo aparte (PASAR-LISTA-RECUENTOS.md). Los rellena el Guardián.
    $fields = array(
        'id', 'name', 'code', 'level', 'cursos_c',
        'ajmcm_n_participantes_c', 'ajmcm_n_monitores_c',
        'ajmcm_monitores_c', 'ajmcm_recuento_al_c',
    );
    if (sticpa_pl_has_segmento()) {
        $fields[] = 'ajmcm_segmento_com_c';
    }
    // La casilla de «entra en Pasar Lista». Va en la MISMA consulta: no cuesta
    // una llamada, solo una columna más.
    $campoActivo = sticpa_pl_has_grupo_activo() ? sticpa_pl_grupo_activo_field() : '';
    if ($campoActivo !== '') {
        $fields[] = $campoActivo;
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
                'activo' => ($campoActivo !== '' && isset($v->$campoActivo))
                    ? sticpa_pl_bool_crm($v->$campoActivo->value) : false,
                // Recuento nocturno. Se guarda tal cual y quien lo pinte decide
                // si se puede fiar (sticpa_pl_recuento_fresco): un numero viejo
                // al lado del nombre de un grupo de menores es peor que un hueco.
                'n_participantes' => isset($v->ajmcm_n_participantes_c->value)
                    && trim((string) $v->ajmcm_n_participantes_c->value) !== ''
                    ? (int) $v->ajmcm_n_participantes_c->value : -1,
                'n_monitores' => isset($v->ajmcm_n_monitores_c->value)
                    && trim((string) $v->ajmcm_n_monitores_c->value) !== ''
                    ? (int) $v->ajmcm_n_monitores_c->value : -1,
                'monitores' => isset($v->ajmcm_monitores_c->value)
                    ? trim((string) $v->ajmcm_monitores_c->value) : '',
                'recuento_al' => isset($v->ajmcm_recuento_al_c->value)
                    ? trim((string) $v->ajmcm_recuento_al_c->value) : '',
            );
        }
    }

    // Por código y en orden natural, conservando el id como clave: el árbol y
    // la portada recorren esto tal cual, así que ordenar aquí ordena en todas
    // las pantallas a la vez.
    uasort($groups, 'sticpa_pl_cmp_group');

    // EL FILTRO DE «ESTE GRUPO ENTRA EN PASAR LISTA».
    //
    // En el CRM hay ~150 grupos y la mayoría son históricos: salían todos en el
    // árbol, en el buscador y en el alcance de coordinación. La casilla del
    // grupo decide, pero con una regla de seguridad importante:
    //
    //   **si NADIE ha marcado ninguna casilla todavía, no se esconde nada.**
    //
    // Sin esa regla, el día que se despliegue esto —con el campo recién creado
    // y vacío— Pasar Lista se quedaría sin un solo grupo y parecería que se ha
    // roto todo. Así, el filtro se enciende SOLO cuando alguien empieza a
    // marcar, que es justo cuando se quiere.
    //
    // Se guarda también cuántos quedan fuera: la pantalla lo dice, para que
    // nadie busque un grupo que está ahí pero sin marcar.
    $marcados = array();
    foreach ($groups as $id => $g) {
        if (!empty($g['activo'])) {
            $marcados[$id] = $g;
        }
    }
    if (!empty($marcados)) {
        $ocultos = count($groups) - count($marcados);
        foreach ($marcados as $id => $g) {
            $marcados[$id]['ocultos'] = $ocultos;
        }
        $groups = $marcados;
    }

    sticpa_pl_cache_put($cacheKey, $groups, $ttl);
    return $groups;
}

/**
 * Cuántos grupos de la delegación quedan fuera de Pasar Lista por la casilla.
 *
 * Cero significa dos cosas distintas y las dos se pintan igual de bien: o no
 * hay ninguno sin marcar, o todavía no se ha marcado ninguno y el filtro no
 * está actuando.
 */
function sticpa_pl_grupos_ocultos($objSCP)
{
    foreach (sticpa_pl_groups($objSCP) as $g) {
        return isset($g['ocultos']) ? (int) $g['ocultos'] : 0;
    }
    return 0;
}

/**
 * TODAS las relaciones vigentes de la delegación, en UNA sola llamada.
 *
 * POR QUÉ EXISTE. Antes cada grupo pedía sus personas con `get_relationships` y
 * el enlace a Contacts poblado. En esta instancia ese enlace **no vuelve**: la
 * llamada responde 200, las relaciones llegan, y el enlace pedido en
 * `related_module_link_name_to_fields_array` no aparece por ningún lado. El
 * resultado era una lista vacía en un grupo que sí tiene gente.
 *
 * El camino que SÍ funciona aquí es `get_entry_list` con
 * `link_name_to_fields_array` — el mismo que usa `list_stic_job_offers.php`
 * desde siempre en producción. Así que se pide por ahí.
 *
 * Y se pide UNA VEZ para toda la delegación en vez de una por grupo: son las
 * mismas relaciones, y el árbol de Castellón tiene 28 grupos. Una llamada
 * cacheada 12 h contra veintiocho por pantalla.
 *
 * La vigencia se filtra EN SQL: sin eso vendrían todas las relaciones que ha
 * tenido la delegación en su vida.
 *
 * Devuelve array de filas: rel_id, role, group_id, group_name, contact_id,
 * name, first, last, y los campos de la ficha que usa la lista de marcado.
 */
function sticpa_pl_all_relationships($objSCP)
{
    $deleg = sticpa_pl_delegation($objSCP);
    if (!$deleg) {
        return array();
    }

    $cacheKey = sticpa_pl_cache_key('rels', $objSCP);
    $ttl = sticpa_pl_ttl_structure();
    if ($ttl > 0) {
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
    }

    // Se piden LAS DOS COSAS a la vez, y luego se usa la que haya venido:
    //
    //  a) los campos planos del propio registro (`..._ida` para el id y
    //     `..._name` para el nombre). Son los que la API V8 devuelve poblados.
    //  b) los enlaces anidados con `link_name_to_fields_array`.
    //
    // Se piden juntos a proposito y no uno detras del otro: es la MISMA
    // llamada, no cuesta nada, y asi la pantalla funciona sin depender de cual
    // de las dos formas soporte la instancia. Ya se perdio una tarde
    // averiguando que `get_relationships` no devuelve (b).
    $fields = array(
        'id', 'name', 'relationship_type', 'start_date', 'end_date',
        'stic_contacts_relationships_contactscontacts_ida',
        'stic_contacts_relationships_contacts_name',
        'ajmcm_grupos_stic_contacts_relationshipsajmcm_grupos_ida',
        'ajmcm_grupos_stic_contacts_relationships_name',
    );

    // La vigencia se filtra en PHP y NO en SQL. En SQL era mas barato, pero si
    // la consulta no le gusta al CRM la respuesta vuelve VACIA y la pantalla
    // dice "no hay nadie" sin distinguirlo de que de verdad no haya nadie. Un
    // filtro que puede fallar en silencio no vale para esto.
    $rows = $objSCP->getRecordsModule(
        'stic_Contacts_Relationships',
        "stic_contacts_relationships.assigned_user_id = '" . sticpa_pl_safe_id($deleg) . "'",
        $fields,
        array(
            'grupo' => array(
                'relationshipName' => 'ajmcm_grupos_stic_contacts_relationships',
                'fields' => array('id', 'name'),
            ),
            'persona' => array(
                'relationshipName' => 'stic_contacts_relationships_contacts',
                'fields' => array('id', 'name', 'first_name', 'last_name', 'birthdate', 'stic_age_c', 'phone_mobile'),
            ),
        )
    );

    $out = array();
    $now = sticpa_pl_now();

    if (is_array($rows)) {
        foreach ($rows as $row) {
            $v = isset($row->name_value_list) ? $row->name_value_list : null;
            if (!$v || empty($v->id->value)) {
                continue;
            }
            $role = sticpa_pl_rel_role(isset($v->relationship_type->value) ? $v->relationship_type->value : '');
            if ($role === '') {
                continue;
            }
            // Vigencia. Una relacion terminada es historia, no un dato de hoy.
            $endRaw = isset($v->end_date->value) ? trim((string) $v->end_date->value) : '';
            if ($endRaw !== '') {
                $endTs = strtotime($endRaw . ' 23:59:59');
                if ($endTs && $endTs < $now) {
                    continue;
                }
            }

            $out[] = array(
                'rel_id' => (string) $v->id->value,
                'role' => $role,
                'group_id' => sticpa_pl_nvl_first($v, array(
                    'grupo_id',
                    'ajmcm_grupos_stic_contacts_relationshipsajmcm_grupos_ida',
                )),
                'group_name' => sticpa_pl_nvl_first($v, array(
                    'grupo',
                    'ajmcm_grupos_stic_contacts_relationships_name',
                )),
                'person' => sticpa_pl_person_from_rel_row($v),
            );
        }
    }

    sticpa_pl_cache_put($cacheKey, $out, $ttl);
    return $out;
}

/**
 * El primer valor no vacio de una lista de campos de `name_value_list`.
 *
 * Existe porque el mismo dato puede llegar por dos nombres distintos segun la
 * forma que soporte la instancia (el enlace aplanado o el campo plano del
 * registro), y probar en orden es mas claro que un `??` de tres pisos.
 */
function sticpa_pl_nvl_first($v, $keys)
{
    foreach ((array) $keys as $k) {
        if (isset($v->$k->value)) {
            $val = trim((string) $v->$k->value);
            if ($val !== '') {
                return $val;
            }
        }
    }
    return '';
}

/**
 * La persona de una fila de relacion, de donde se pueda sacar.
 *
 * Por orden de preferencia: el enlace anidado (trae edad y movil), el campo
 * plano `..._name`, y como ultimo recurso el NOMBRE DE LA PROPIA RELACION, que
 * en este CRM es «Solete Vilarroya Messguer - Participante MIC-COM». Es feo
 * partirlo por el guion, pero un nombre es infinitamente mejor que una fila en
 * blanco: sin el, el monitor no puede pasar lista.
 */
function sticpa_pl_person_from_rel_row($v)
{
    $lv = isset($v->persona_link) ? $v->persona_link : null;

    $id = sticpa_pl_nvl_first($v, array(
        'persona_id',
        'stic_contacts_relationships_contactscontacts_ida',
    ));

    $first = ($lv && isset($lv->first_name->value)) ? trim((string) $lv->first_name->value) : '';
    $last = ($lv && isset($lv->last_name->value)) ? trim((string) $lv->last_name->value) : '';

    $full = sticpa_pl_nvl_first($v, array(
        'persona',
        'stic_contacts_relationships_contacts_name',
    ));
    if ($full === '') {
        $full = trim($first . ' ' . $last);
    }
    if ($full === '') {
        // El nombre de la relacion: «Persona - Papel». Se parte por el ultimo
        // guion rodeado de espacios para no romper un apellido con guion.
        $relName = isset($v->name->value) ? trim((string) $v->name->value) : '';
        $cut = strrpos($relName, ' - ');
        $full = ($cut !== false) ? trim(substr($relName, 0, $cut)) : $relName;
    }

    return array(
        'id' => $id,
        // El nombre de la lista es corto (nombre + primer apellido). El
        // completo se conserva en `full` para la ficha, donde sí cabe y sí
        // importa.
        'name' => sticpa_pl_short_name($first, $last, $full),
        'full' => $full,
        'first' => $first,
        'last' => $last,
        'sort' => ($last !== '' || $first !== '')
            ? sticpa_pl_sort_key($last, $first)
            : sticpa_pl_sort_key($full, ''),
        'initials' => sticpa_pl_initials($first, $last, $full),
        'age' => ($lv && isset($lv->stic_age_c->value)) ? (string) $lv->stic_age_c->value : '',
        'birthdate' => ($lv && isset($lv->birthdate->value)) ? (string) $lv->birthdate->value : '',
        'mobile' => ($lv && isset($lv->phone_mobile->value)) ? (string) $lv->phone_mobile->value : '',
    );
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
/**
 * Las personas de un grupo SIN respaldo, para quien recorre muchos grupos.
 *
 * Existe para que el bucle no pueda equivocarse: `sticpa_pl_group_people()` cae
 * al respaldo de UNA llamada cuando el grupo sale vacío —y eso está bien cuando
 * se pinta un grupo—, pero multiplicado por ~150 grupos es lo que hacía eterna
 * la pantalla de monitores.
 */
function sticpa_pl_group_people_bulk($objSCP, $groupId)
{
    $sin = function () { return false; };
    add_filter('sticpa_pl_respaldo_por_grupo', $sin, 99);
    $out = sticpa_pl_group_people($objSCP, $groupId);
    remove_filter('sticpa_pl_respaldo_por_grupo', $sin, 99);
    return $out;
}

function sticpa_pl_group_people($objSCP, $groupId)
{
    $groupId = (string) $groupId;
    if ($groupId === '') {
        return array('participants' => array(), 'monitors' => array());
    }

    $out = array('participants' => array(), 'monitors' => array());

    // Camino normal: del mapa comun de la delegacion, sin una llamada propia.
    foreach (sticpa_pl_all_relationships($objSCP) as $rel) {
        if ($rel['group_id'] !== $groupId || $rel['person']['id'] === '') {
            continue;
        }
        $bucket = ($rel['role'] === 'monitor') ? 'monitors' : 'participants';
        // Indexado por id: dos relaciones vigentes con el mismo grupo salen una.
        $out[$bucket][$rel['person']['id']] = $rel['person'];
    }

    // RESPALDO, y OJO CON LA CONDICIÓN.
    //
    // Antes se disparaba cuando ESTE grupo salía vacío, y eso era un 1+N de los
    // caros: la pantalla de monitores recorre TODOS los grupos del alcance y en
    // el CRM hay ~150, la mayoría históricos y vacíos. Una llamada por cada uno.
    // Es exactamente por lo que la lista de monitores tardaba una eternidad.
    //
    // Un grupo vacío en un mapa que SÍ trae gente no es un fallo: es un grupo
    // vacío, y preguntar otra vez devuelve lo mismo. El respaldo solo tiene
    // sentido cuando el mapa entero viene vacío, que es la señal de que la
    // consulta de colección ha fallado.
    /* EL RESPALDO, Y LA LECCIÓN QUE COSTÓ UN GRUPO VACÍO.
     *
     * Primer intento: el respaldo saltaba cuando ESTE grupo salía vacío. Eso
     * era un 1+N carísimo en la pantalla de monitores, que recorre TODOS los
     * grupos del alcance (~150 en el CRM, casi todos históricos y vacíos).
     *
     * Segundo intento: se limitó a «solo si el mapa entero no sirve». Mató el
     * 1+N... y también mató el respaldo justo donde hacía falta, porque el mapa
     * de la delegación puede traer gente de OTROS grupos y no la de este (una
     * respuesta a medias del CRM, por ejemplo). Resultado: C1 sin participantes
     * un sábado, que es exactamente lo que no se puede permitir.
     *
     * Lo que estaba mal era el sitio, no el respaldo. Aquí se pregunta por UN
     * grupo —el que se está pintando—, así que cuesta UNA llamada y solo
     * cuando ese grupo sale vacío: la pantalla de marcar, la ficha y el atajo
     * de la portada piden un grupo cada una. El bucle sobre muchos grupos vive
     * en sticpa_pl_monitors_of(), y ES AHÍ donde el respaldo no puede correr.
     *
     * `sticpa_pl_group_people_bulk()` es la puerta para quien recorra grupos:
     * no cae al respaldo nunca.
     */
    if (empty($out['participants']) && empty($out['monitors'])
        && !sticpa_pl_collecting()
        && apply_filters('sticpa_pl_respaldo_por_grupo', true, $groupId)) {
        $out = sticpa_pl_group_people_direct($objSCP, $groupId);
    }

    // Alfabético por apellido, que es como se lee una lista de clase.
    foreach (array('participants', 'monitors') as $b) {
        $out[$b] = array_values($out[$b]);
        usort($out[$b], 'sticpa_pl_cmp_person');
    }

    return $out;
}

/**
 * Las personas de un grupo preguntando POR EL GRUPO, una persona por llamada.
 *
 * Es el respaldo de sticpa_pl_group_people(). Usa solo lo que esta instancia ha
 * demostrado que soporta: `get_relationships` devolviendo los REGISTROS (sin
 * enlaces anidados), que es lo que hace todo el plugin desde siempre.
 *
 * El tope existe para que un grupo con los datos mal (cientos de relaciones sin
 * cerrar) no convierta una pantalla en cien llamadas. Si se llega al tope se
 * dice en pantalla: una lista recortada en silencio es peor que una lista corta
 * que avisa de que lo esta.
 */
function sticpa_pl_group_people_direct($objSCP, $groupId)
{
    $out = array('participants' => array(), 'monitors' => array(), 'truncated' => false);

    $rels = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'ajmcm_GRUPOS',
        'module_id' => sticpa_pl_safe_id($groupId),
        'link_field_name' => 'ajmcm_grupos_stic_contacts_relationships',
        'related_fields' => array('id', 'name', 'relationship_type', 'start_date', 'end_date'),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));
    if (!is_array($rels)) {
        return $out;
    }

    $max = (int) apply_filters('sticpa_pl_max_people_per_group', 40);
    $now = sticpa_pl_now();
    $seen = 0;

    foreach ($rels as $rel) {
        $v = isset($rel->name_value_list) ? $rel->name_value_list : null;
        if (!$v || empty($v->id->value)) {
            continue;
        }
        $role = sticpa_pl_rel_role(isset($v->relationship_type->value) ? $v->relationship_type->value : '');
        if ($role === '') {
            continue;
        }
        $endRaw = isset($v->end_date->value) ? trim((string) $v->end_date->value) : '';
        if ($endRaw !== '') {
            $endTs = strtotime($endRaw . ' 23:59:59');
            if ($endTs && $endTs < $now) {
                continue;
            }
        }
        if ($seen >= $max) {
            $out['truncated'] = true;
            break;
        }
        $seen++;

        // El contacto de esta relacion. Una llamada, y aqui SI hace falta:
        // es la unica forma de tener el id con el que se guarda la asistencia.
        $person = sticpa_pl_contact_of_relationship($objSCP, (string) $v->id->value, $v);
        if ($person === null || $person['id'] === '') {
            continue;
        }
        $bucket = ($role === 'monitor') ? 'monitors' : 'participants';
        $out[$bucket][$person['id']] = $person;
    }

    return $out;
}

/**
 * El contacto de una relación, con sus datos de lista.
 *
 * $relRow es la fila de la relación, que se pasa para poder caer en su `name`
 * («Solete Vilarroya Messguer - Participante MIC-COM») si el contacto viniera
 * sin nombre: un nombre imperfecto es mejor que una fila en blanco.
 */
function sticpa_pl_contact_of_relationship($objSCP, $relId, $relRow = null)
{
    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'stic_Contacts_Relationships',
        'module_id' => sticpa_pl_safe_id($relId),
        'link_field_name' => 'stic_contacts_relationships_contacts',
        'related_fields' => array('id', 'name', 'first_name', 'last_name', 'birthdate', 'stic_age_c', 'phone_mobile'),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));
    if (!is_array($rows)) {
        return null;
    }

    foreach ($rows as $row) {
        $c = isset($row->name_value_list) ? $row->name_value_list : null;
        if (!$c || empty($c->id->value)) {
            continue;
        }
        $first = isset($c->first_name->value) ? trim((string) $c->first_name->value) : '';
        $last = isset($c->last_name->value) ? trim((string) $c->last_name->value) : '';
        $full = trim($first . ' ' . $last);
        if ($full === '' && isset($c->name->value)) {
            $full = trim((string) $c->name->value);
        }
        if ($full === '' && $relRow !== null && isset($relRow->name->value)) {
            $relName = trim((string) $relRow->name->value);
            $cut = strrpos($relName, ' - ');
            $full = ($cut !== false) ? trim(substr($relName, 0, $cut)) : $relName;
        }

        return array(
            'id' => (string) $c->id->value,
            'name' => sticpa_pl_short_name($first, $last, $full),
            'full' => $full,
            'first' => $first,
            'last' => $last,
            'sort' => ($last !== '' || $first !== '')
                ? sticpa_pl_sort_key($last, $first)
                : sticpa_pl_sort_key($full, ''),
            'initials' => sticpa_pl_initials($first, $last, $full),
            'age' => isset($c->stic_age_c->value) ? (string) $c->stic_age_c->value : '',
            'birthdate' => isset($c->birthdate->value) ? (string) $c->birthdate->value : '',
            'mobile' => isset($c->phone_mobile->value) ? (string) $c->phone_mobile->value : '',
        );
    }
    return null;
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
                'name' => sticpa_pl_short_name($first, $last, $full),
                'full' => $full,
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
        // `name` hace falta para las REUNIONES: son «Programación del 2.º
        // trimestre», no «sábado 21». En las sesiones semanales el nombre no
        // aporta (es la fecha lo que se lee) y por eso no se pedía; pero no
        // pedirlo dejaba la pantalla de reuniones sin lo único que identifica
        // a cada una. Viene en la misma consulta: no cuesta nada.
        'related_fields' => array('id', 'name', 'start_date', 'end_date'),
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
                'name' => isset($v->name->value) ? trim((string) $v->name->value) : '',
                'start' => sticpa_pl_ts($start),
                'end' => $endRaw !== '' ? sticpa_pl_ts($endRaw) : 0,
            );
        }
    }
    usort($sessions, 'sticpa_pl_cmp_start');

    sticpa_pl_cache_put($cacheKey, $sessions, $ttl);
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

    // EL CAMPO PLANO, ademas del enlace anidado. Sin el id del contacto de cada
    // inscripcion NO SE PUEDE ESCRIBIR NINGUNA ASISTENCIA: la asistencia cuelga
    // de la inscripcion, no de la persona. Cuando este mapa venia vacio, el
    // guardado escribia una lista de «0 y 0» y ni una sola asistencia — la
    // pantalla decia "lista guardada" y en el CRM no habia nada.
    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'stic_Events',
        'module_id' => $eventId,
        'link_field_name' => 'stic_registrations_stic_events',
        'related_fields' => array('id', 'status', 'stic_registrations_contactscontacts_ida'),
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
            // Primero el enlace anidado; si no vino, el campo plano.
            $contactId = sticpa_pl_link_id($row);
            if ($contactId === '') {
                $contactId = sticpa_pl_nvl_first($v, array('stic_registrations_contactscontacts_ida'));
            }
            if ($contactId === '') {
                continue;
            }
            $map[$v->id->value] = $contactId;
        }
    }

    // RESPALDO. Si no ha salido ni un contacto, se piden las inscripciones de la
    // delegacion por get_entry_list con el enlace anidado —la via probada— y se
    // filtran por evento en PHP. Una llamada, cacheada.
    if (empty($map) && !sticpa_pl_collecting()) {
        $map = sticpa_pl_event_registrations_direct($objSCP, $eventId);
    }

    sticpa_pl_cache_put($cacheKey, $map, $ttl);
    return $map;
}

/**
 * Las inscripciones de un evento por `get_entry_list`, con el enlace poblado.
 *
 * Es el respaldo de sticpa_pl_event_registrations(). Se pide por delegacion y
 * se filtra por evento en PHP porque el id del evento no esta en ninguna
 * columna consultable de la inscripcion (`ajmcm_eventid_c` existe pero esta
 * vacio), y una consulta que no se puede filtrar bien es mejor filtrarla aqui
 * que arriesgarse a que el CRM devuelva vacio sin decir por que.
 */
function sticpa_pl_event_registrations_direct($objSCP, $eventId)
{
    $deleg = sticpa_pl_delegation($objSCP);
    if (!$deleg) {
        return array();
    }

    $rows = $objSCP->getRecordsModule(
        'stic_Registrations',
        "stic_registrations.assigned_user_id = '" . sticpa_pl_safe_id($deleg) . "'",
        array('id', 'status', 'stic_registrations_contactscontacts_ida', 'stic_registrations_stic_eventsstic_events_ida'),
        array(
            'persona' => array(
                'relationshipName' => 'stic_registrations_contacts',
                'fields' => array('id', 'name'),
            ),
            'evento' => array(
                'relationshipName' => 'stic_registrations_stic_events',
                'fields' => array('id', 'name'),
            ),
        )
    );

    $map = array();
    if (!is_array($rows)) {
        return $map;
    }
    foreach ($rows as $row) {
        $v = isset($row->name_value_list) ? $row->name_value_list : null;
        if (!$v || empty($v->id->value)) {
            continue;
        }
        if (isset($v->status->value) && (string) $v->status->value === 'cancelled') {
            continue;
        }
        $ev = sticpa_pl_nvl_first($v, array(
            'evento_id',
            'stic_registrations_stic_eventsstic_events_ida',
        ));
        // Sin saber de que evento es, NO se mete en el mapa: colgarle una
        // asistencia al evento equivocado es peor que no tenerla.
        if ($ev === '' || $ev !== (string) $eventId) {
            continue;
        }
        $contactId = sticpa_pl_nvl_first($v, array(
            'persona_id',
            'stic_registrations_contactscontacts_ida',
        ));
        if ($contactId === '') {
            continue;
        }
        $map[(string) $v->id->value] = $contactId;
    }
    return $map;
}

/**
 * Las asistencias de una sesión por `get_entry_list`, con el enlace poblado.
 *
 * Respaldo de sticpa_pl_session_attendances(). Se pide por delegación y se
 * filtra por sesión en PHP, por lo mismo que en las inscripciones: no hay
 * columna consultable con el id de la sesión, y es mejor filtrar aquí que
 * arriesgarse a una consulta que el CRM devuelva vacía sin decir por qué.
 */
function sticpa_pl_session_attendances_direct($objSCP, $sessionId, $regMap)
{
    $deleg = sticpa_pl_delegation($objSCP);
    if (!$deleg || empty($regMap)) {
        return array();
    }

    $rows = $objSCP->getRecordsModule(
        'stic_Attendances',
        "stic_attendances.assigned_user_id = '" . sticpa_pl_safe_id($deleg) . "'",
        array(
            'id', 'status', 'description',
            'stic_attendances_stic_registrationsstic_registrations_ida',
            'stic_attendances_stic_sessionsstic_sessions_ida',
        ),
        array(
            'inscripcion' => array(
                'relationshipName' => 'stic_attendances_stic_registrations',
                'fields' => array('id', 'name'),
            ),
            'sesion' => array(
                'relationshipName' => 'stic_attendances_stic_sessions',
                'fields' => array('id', 'name'),
            ),
        )
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
        $sid = sticpa_pl_nvl_first($v, array(
            'sesion_id',
            'stic_attendances_stic_sessionsstic_sessions_ida',
        ));
        if ($sid === '' || $sid !== (string) $sessionId) {
            continue;
        }
        $regId = sticpa_pl_nvl_first($v, array(
            'inscripcion_id',
            'stic_attendances_stic_registrationsstic_registrations_ida',
        ));
        if ($regId === '' || !isset($regMap[$regId])) {
            continue;
        }
        $status = isset($v->status->value) ? (string) $v->status->value : '';
        $out[$regMap[$regId]] = array(
            'id' => (string) $v->id->value,
            'status' => sticpa_pl_is_state($status) ? $status : '',
            'description' => isset($v->description->value) ? (string) $v->description->value : '',
            'registration_id' => $regId,
        );
    }
    return $out;
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
        'related_fields' => array(
            'id', 'status', 'description',
            'stic_attendances_stic_registrationsstic_registrations_ida',
        ),
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
            if ($regId === '') {
                $regId = sticpa_pl_nvl_first($v, array('stic_attendances_stic_registrationsstic_registrations_ida'));
            }
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

    // RESPALDO. Si no ha salido ninguna asistencia, se piden por get_entry_list
    // con el enlace anidado —la via probada— y se filtran por sesion en PHP.
    //
    // Sin esto el guardado CREABA una asistencia nueva en vez de actualizar la
    // que el CRM ya habia hecho con la inscripcion: dos asistencias de la misma
    // persona en la misma sesion, y la nueva sin fecha ni duracion.
    if (empty($out) && !sticpa_pl_collecting()) {
        $out = sticpa_pl_session_attendances_direct($objSCP, $sessionId, $regMap);
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
/**
 * Las asistencias de VARIAS sesiones en UNA llamada, por rango de fechas.
 *
 * Las rachas de ausencias mira las tres últimas sesiones celebradas, y eso eran
 * tres llamadas al CRM en la pantalla más usada de la aplicación. Son la misma
 * tabla y fechas contiguas: `start_date` de `stic_Attendances` es una columna de
 * verdad, así que se piden por rango y se reparten aquí.
 *
 * Devuelve [sessionId][contactId] => array('id','status',…), o un array vacío
 * si no se puede: quien llame tiene que poder distinguirlo y reintentar por
 * sesión, porque un filtro de fechas que el CRM no digiera devuelve vacío sin
 * decir nada (la lección de §9 de PASAR-LISTA-CAMPOS-CRM.md).
 */
function sticpa_pl_attendances_for_sessions($objSCP, $sessions, $regMap)
{
    $deleg = sticpa_pl_delegation($objSCP);
    if (!$deleg || empty($sessions) || empty($regMap)) {
        return array();
    }

    $starts = array();
    $ids = array();
    foreach ($sessions as $s) {
        if (!empty($s['start'])) {
            $starts[] = (int) $s['start'];
        }
        $ids[(string) $s['id']] = true;
    }
    if (empty($starts)) {
        return array();
    }

    // El rango se abre un día por cada lado: la asistencia lleva la hora de la
    // sesión, y un desfase de zona horaria no puede dejar fuera un sábado.
    $from = date('Y-m-d H:i:s', min($starts) - DAY_IN_SECONDS);
    $to = date('Y-m-d H:i:s', max($starts) + DAY_IN_SECONDS);

    $cacheKey = sticpa_pl_cache_key('attrange', $objSCP, $from . '|' . $to . '|' . md5(implode(',', array_keys($ids))));
    $ttl = sticpa_pl_ttl_state();
    if ($ttl > 0) {
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $rows = $objSCP->getRecordsModule(
        'stic_Attendances',
        "stic_attendances.assigned_user_id = '" . sticpa_pl_safe_id($deleg) . "'"
        . " AND stic_attendances.start_date >= '" . esc_sql($from) . "'"
        . " AND stic_attendances.start_date <= '" . esc_sql($to) . "'",
        array(
            'id', 'status', 'description',
            'stic_attendances_stic_sessionsstic_sessions_ida',
            'stic_attendances_stic_registrationsstic_registrations_ida',
        ),
        array(
            'sesion' => array(
                'relationshipName' => 'stic_attendances_stic_sessions',
                'fields' => array('id', 'name'),
            ),
            'inscripcion' => array(
                'relationshipName' => 'stic_attendances_stic_registrations',
                'fields' => array('id', 'name'),
            ),
        )
    );

    $out = array();
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $v = isset($row->name_value_list) ? $row->name_value_list : null;
            if (!$v || empty($v->id->value)) {
                continue;
            }
            $sid = sticpa_pl_nvl_first($v, array('sesion_id', 'stic_attendances_stic_sessionsstic_sessions_ida'));
            if ($sid === '' || !isset($ids[$sid])) {
                continue;   // del rango, pero de otra sesión: no es de este grupo de fechas
            }
            $regId = sticpa_pl_nvl_first($v, array('inscripcion_id', 'stic_attendances_stic_registrationsstic_registrations_ida'));
            if ($regId === '' || !isset($regMap[$regId])) {
                continue;
            }
            $status = isset($v->status->value) ? (string) $v->status->value : '';
            $out[$sid][$regMap[$regId]] = array(
                'id' => (string) $v->id->value,
                'status' => sticpa_pl_is_state($status) ? $status : '',
                'description' => isset($v->description->value) ? (string) $v->description->value : '',
                'registration_id' => $regId,
            );
        }
    }

    sticpa_pl_cache_put($cacheKey, $out, $ttl);
    return $out;
}

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

    // UNA llamada para las tres sesiones. Si el rango de fechas no devuelve
    // nada, se cae a preguntar sesión por sesión: un filtro que el CRM no
    // digiera devuelve vacío sin decir nada, y una racha que desaparece en
    // silencio se lleva por delante el aviso de "3 ausencias seguidas".
    $porSesion = sticpa_pl_attendances_for_sessions($objSCP, $look, $regMap);

    foreach ($look as $s) {
        $att = isset($porSesion[$s['id']])
            ? $porSesion[$s['id']]
            : (empty($porSesion) ? sticpa_pl_session_attendances($objSCP, $s['id'], $regMap) : array());
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

    // Por `cache_put` y no por `set_transient` a pelo: es quien respeta la
    // pasada de recolecta (si no, cachearía el vacío que devuelve a propósito).
    sticpa_pl_cache_put($cacheKey, array('sig' => $sig, 'data' => $streaks), $ttl);
    return $streaks;
}

/**
 * La lista de un grupo en una sesión: null si no hay registro.
 *
 * `LIS_listas` es lo que distingue "no ha venido nadie" de "nadie ha pasado
 * lista", que en un modelo de un evento compartido por todos los grupos no se
 * puede deducir de las asistencias.
 */
/**
 * TODAS las listas de la delegación, en UNA llamada, indexadas por sesión y grupo.
 *
 * POR QUÉ EXISTE. El resumen pedía las listas SESIÓN POR SESIÓN: doce sesiones
 * por tres etapas son hasta treinta y seis llamadas al CRM para pintar una
 * pantalla, y cada llamada cuesta medio segundo largo. Medido: la pantalla
 * tardaba casi nueve segundos. La pantalla de marcado pedía otra por su cuenta.
 *
 * Son todas la misma tabla. Se pide una vez, filtrada por delegación, y de aquí
 * salen `sticpa_pl_lista()` y `sticpa_pl_listas_by_session()` sin gastar nada.
 *
 * El tamaño está acotado por la propia realidad: un curso son 24 sesiones y una
 * delegación grande 28 grupos, y solo existen las listas que alguien ha pasado.
 *
 * @return array [sessionId][groupId] => array('id','estado','pasada_el',…)
 */
function sticpa_pl_all_listas($objSCP)
{
    $index = sticpa_pl_listas_index($objSCP);
    return $index['participantes'];
}

/**
 * Las listas de MONITORES de la delegación: sessionId => datos de la lista.
 *
 * Van en su propio mapa, no colgadas de un grupo, porque una lista de monitores
 * no es de un grupo: el alcance de coordinación es la etapa. Sale de la MISMA
 * llamada y la MISMA caché que las de participantes (ver
 * `sticpa_pl_listas_index()`), así que no cuesta ninguna consulta extra.
 *
 * ⚠️ UNA POR SESIÓN Y DELEGACIÓN. Si MIC y COM comparten evento —y por tanto
 * sesiones— sus coordinadores comparten esta lista, y el último que guarde deja
 * sus números. Las ASISTENCIAS de cada monitor son correctas en cualquier caso
 * (son por persona); lo que se pisa es el resumen. Separarlas de verdad pide un
 * campo de etapa en `LIS_listas` que hoy NO existe, y aquí no se inventan
 * campos: está anotado en PASAR-LISTA-COORDINACION.md.
 */
function sticpa_pl_all_listas_monitores($objSCP)
{
    $index = sticpa_pl_listas_index($objSCP);
    return $index['monitores'];
}

/**
 * Una sola lectura de `LIS_listas` para las dos familias.
 *
 * Devuelve array('participantes' => [sesion][grupo] => …, 'monitores' =>
 * [sesion] => …). Lo que separa las dos es `ajmcm_tipo_c`, que es justo para
 * lo que existe ese campo: la lista del C1 y la de monitores del COM viven en
 * la misma sesión y no se pueden pisar.
 */
function sticpa_pl_listas_index($objSCP)
{
    $vacio = array('participantes' => array(), 'monitores' => array());

    $deleg = sticpa_pl_delegation($objSCP);
    if (!$deleg) {
        return $vacio;
    }

    $cacheKey = sticpa_pl_cache_key('listas', $objSCP);
    $ttl = sticpa_pl_ttl_state();
    if ($ttl > 0) {
        $cached = get_transient($cacheKey);
        // La comprobación de las dos claves NO es paranoia: una caché escrita
        // por la versión anterior tiene la forma vieja (plana) y colarla aquí
        // daría un índice sin monitores y con las participantes en el sitio
        // equivocado.
        if (is_array($cached) && isset($cached['participantes']) && isset($cached['monitores'])) {
            return $cached;
        }
    }

    $rows = $objSCP->getRecordsModule(
        'LIS_listas',
        "lis_listas.assigned_user_id = '" . sticpa_pl_safe_id($deleg) . "'",
        array(
            'id', 'estado', 'pasada_el', 'n_asistieron', 'n_faltaron', 'ajmcm_tipo_c',
            'lis_listas_stic_sessionsstic_sessions_ida',
            'lis_listas_ajmcm_gruposajmcm_grupos_ida',
        ),
        array(
            'sesion' => array(
                'relationshipName' => 'lis_listas_stic_sessions',
                'fields' => array('id', 'name'),
            ),
            'grupo' => array(
                'relationshipName' => 'lis_listas_ajmcm_grupos',
                'fields' => array('id', 'name'),
            ),
        )
    );

    $tipos = sticpa_pl_lista_tipos();
    $out = $vacio;
    if (is_array($rows)) {
        foreach ($rows as $row) {
            $v = isset($row->name_value_list) ? $row->name_value_list : null;
            if (!$v || empty($v->id->value)) {
                continue;
            }
            $sid = sticpa_pl_nvl_first($v, array('sesion_id', 'lis_listas_stic_sessionsstic_sessions_ida'));
            // Sin sesión no se puede colocar ninguna de las dos.
            if ($sid === '') {
                continue;
            }
            $datos = array(
                'id' => (string) $v->id->value,
                'estado' => isset($v->estado->value) ? (string) $v->estado->value : '',
                'pasada_el' => isset($v->pasada_el->value) ? (string) $v->pasada_el->value : '',
                'n_asistieron' => isset($v->n_asistieron->value) ? (int) $v->n_asistieron->value : 0,
                'n_faltaron' => isset($v->n_faltaron->value) ? (int) $v->n_faltaron->value : 0,
            );

            // Vacío = participantes: es el valor por defecto del CRM y lo que
            // son todas las listas de antes de que existiera el campo.
            $tipo = isset($v->ajmcm_tipo_c->value) ? (string) $v->ajmcm_tipo_c->value : '';
            if ($tipo === $tipos['monitores']) {
                $out['monitores'][$sid] = $datos;
                continue;
            }

            $gid = sticpa_pl_nvl_first($v, array('grupo_id', 'lis_listas_ajmcm_gruposajmcm_grupos_ida'));
            // Una lista de participantes sin grupo no se puede colocar, y
            // colocarla mal es peor que no tenerla: diría que un grupo pasó una
            // lista que no es.
            if ($gid === '') {
                continue;
            }
            $out['participantes'][$sid][$gid] = $datos;
        }
    }

    // El índice se cachea entero. Ojo con `sticpa_pl_cache_put`: este array
    // NUNCA está vacío (lleva sus dos claves), así que conserva el TTL de
    // estado completo, que es lo que queremos.
    sticpa_pl_cache_put($cacheKey, $out, $ttl);
    return $out;
}

/** El id del grupo de una lista, preguntando por su enlace. */
function sticpa_pl_group_of_lista($objSCP, $listaId)
{
    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'LIS_listas',
        'module_id' => sticpa_pl_safe_id($listaId),
        'link_field_name' => 'lis_listas_ajmcm_grupos',
        'related_fields' => array('id'),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));
    if (!is_array($rows)) {
        return '';
    }
    foreach ($rows as $row) {
        $g = isset($row->name_value_list) ? $row->name_value_list : null;
        if ($g && !empty($g->id->value)) {
            return (string) $g->id->value;
        }
    }
    return '';
}

function sticpa_pl_lista($objSCP, $sessionId, $groupId)
{
    $sessionId = (string) $sessionId;
    $groupId = (string) $groupId;
    if ($sessionId === '' || $groupId === '') {
        return null;
    }

    // Del cargador comun: cero llamadas propias. Antes era una por sesion y
    // grupo, y la pantalla de marcado la pedia en cada carga.
    $all = sticpa_pl_all_listas($objSCP);
    return isset($all[$sessionId][$groupId]) ? $all[$sessionId][$groupId] : null;
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
 * El motivo del último fallo del CRM, si el transporte lo ha guardado.
 *
 * Tolerante a que no exista la propiedad: los dobles de test y cualquier otro
 * cliente siguen valiendo.
 */
function sticpa_pl_crm_error($objSCP)
{
    if (is_object($objSCP) && isset($objSCP->lastError) && (string) $objSCP->lastError !== '') {
        return (string) $objSCP->lastError;
    }
    return '';
}

/** Cuántas entradas del registro de guardados se conservan. */
function sticpa_pl_save_log_max()
{
    return (int) apply_filters('sticpa_pl_save_log_max', 20);
}

/**
 * Apunta un intento de guardado en `wp_options`, salga bien o mal.
 *
 * POR QUÉ EXISTE: el 27/08/2026 el usuario pasó lista y en el CRM no apareció
 * NADA — ni un registro tocado. Sin rastro por nuestro lado no había forma de
 * saber si el POST llegó, si las marcas venían vacías o si el CRM las rechazó,
 * y cada intento de diagnóstico costaba una sesión entera de pruebas a mano.
 * Ahora cada intento deja una línea, incluidos los que NO escriben (nonce
 * caducado, sin marcas), que son justo los que se confundían con «no llegó».
 */
function sticpa_pl_log_save($entry)
{
    if (!function_exists('update_option')) {
        return;
    }
    $entry = array_merge(array(
        'ts' => date('Y-m-d H:i:s', sticpa_pl_now()),
        'pantalla' => '',
        'motivo' => '',
        'grupo' => '',
        'sesion' => '',
        'marcas_post' => 0,
        'marcas_usadas' => 0,
        'saved' => 0,
        'failed' => 0,
        'lista_id' => '',
        'errores' => array(),
        'llamadas' => class_exists('SugarRestApiCall') ? (int) SugarRestApiCall::$callCount : 0,
    ), (array) $entry);

    // Los motivos se recortan: esto es un diario de diagnóstico, no un almacén.
    $entry['errores'] = array_slice((array) $entry['errores'], 0, 10);

    $log = get_option('sticpa_pl_save_log', array());
    if (!is_array($log)) {
        $log = array();
    }
    $log[] = $entry;
    $max = sticpa_pl_save_log_max();
    if (count($log) > $max) {
        $log = array_slice($log, -$max);
    }
    update_option('sticpa_pl_save_log', $log, false);
}

/** El registro de intentos de guardado, del más reciente al más viejo. */
function sticpa_pl_save_log()
{
    $log = function_exists('get_option') ? get_option('sticpa_pl_save_log', array()) : array();
    if (!is_array($log)) {
        return array();
    }
    return array_reverse($log);
}

/**
 * Comprueba, LEYENDO EL CRM, que lo que se dijo que se guardó está guardado.
 *
 * Es el criterio de cierre del bug convertido en código: una lista con su
 * estado, y el estado de cada persona marcada en la asistencia de ESA sesión.
 * No cuesta ninguna llamada extra: la pantalla ya vuelve a leer las dos cosas
 * después de guardar (y la caché de estado se acaba de invalidar).
 *
 * Devuelve la lista de problemas encontrados; vacía es que de verdad se guardó.
 */
function sticpa_pl_check_saved($marks, $lista, $attendances, $omitida = false, $checkLista = true)
{
    $problemas = array();

    if (!$checkLista) {
        $lista = null;   // la lista de monitores no se escribe todavía (ver docs)
    } elseif (!is_array($lista) || empty($lista['id'])) {
        $problemas[] = __('la lista no aparece en el CRM al volver a leerla', 'sticpa');
    } elseif (is_array($lista)) {
        $estados = sticpa_pl_lista_estados();
        $esperado = $omitida ? $estados['omitida'] : $estados['pasada'];
        if ((string) $lista['estado'] !== (string) $esperado) {
            $problemas[] = sprintf(
                /* translators: 1: estado leído, 2: estado esperado */
                __('la lista está en «%1$s» y debería estar en «%2$s»', 'sticpa'),
                (string) $lista['estado'],
                (string) $esperado
            );
        }
    }

    if ($omitida) {
        return $problemas;   // sin registro: no hay asistencias que comprobar
    }

    $sinEscribir = 0;
    foreach ((array) $marks as $contactId => $key) {
        if ((string) $key === '' || !sticpa_pl_is_state($key)) {
            continue;
        }
        $leido = isset($attendances[$contactId]['status']) ? (string) $attendances[$contactId]['status'] : '';
        if ($leido !== (string) $key) {
            $sinEscribir++;
        }
    }
    if ($sinEscribir > 0) {
        $problemas[] = sprintf(
            /* translators: %d: número de marcas que no se han guardado */
            _n('%d marca no ha quedado guardada', '%d marcas no han quedado guardadas', $sinEscribir, 'sticpa'),
            $sinEscribir
        );
    }

    return $problemas;
}

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
    $result = array(
        'saved' => 0, 'failed' => 0, 'lista_id' => '',
        'counts' => array('yes' => 0, 'no' => 0),
        // Cada fallo, con el paso en el que ocurrió y lo que dijo el CRM. Un
        // `failed` a secas no se puede diagnosticar.
        'errors' => array(),
    );
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
                    $result['errors'][] = array(
                        'paso' => 'asistencia_actualizar',
                        'id' => $existing[$contactId]['id'],
                        'error' => sticpa_pl_crm_error($objSCP),
                    );
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
                $result['errors'][] = array(
                    'paso' => 'asistencia_crear',
                    'id' => $contactId,
                    'error' => sticpa_pl_crm_error($objSCP),
                );
                continue;
            }
            // Las relaciones SÍ se comprueban: una asistencia sin sesión o sin
            // inscripción queda huérfana y el CRM no la cuenta. Antes se
            // lanzaban y nadie miraba el resultado.
            if ($objSCP->set_relationship('stic_Attendances', $newId, 'stic_attendances_stic_sessions', array($sessionId)) === false) {
                $result['failed']++;
                $result['errors'][] = array(
                    'paso' => 'asistencia_enlazar_sesion',
                    'id' => $newId,
                    'error' => sticpa_pl_crm_error($objSCP),
                );
            }
            // Sin la inscripción detrás, la asistencia queda huérfana y el CRM
            // no la cuenta en el porcentaje de la inscripción.
            $regId = array_search($contactId, (array) $regMap, true);
            if ($regId !== false) {
                if ($objSCP->set_relationship('stic_Attendances', $newId, 'stic_attendances_stic_registrations', array($regId)) === false) {
                    $result['failed']++;
                    $result['errors'][] = array(
                        'paso' => 'asistencia_enlazar_inscripcion',
                        'id' => $newId,
                        'error' => sticpa_pl_crm_error($objSCP),
                    );
                }
            } else {
                $result['errors'][] = array(
                    'paso' => 'sin_inscripcion',
                    'id' => $contactId,
                    'error' => __('esta persona no tiene inscripción en el evento: su asistencia queda sin enlazar', 'sticpa'),
                );
            }
            $result['saved']++;
        }
    }

    // La lista: se crea o se actualiza, nunca se duplica.
    $lista = sticpa_pl_lista($objSCP, $sessionId, $groupId);
    $tipos = sticpa_pl_lista_tipos();
    $payload = array(
        'estado' => $omitida ? $estados['omitida'] : $estados['pasada'],
        // REQUERIDO en el CRM (verificado con get_module_fields el 27/08/2026).
        // Se enviaba vacío y salía bien solo porque el CRM tiene 'participantes'
        // como valor por defecto: apoyarse en eso es apoyarse en nada.
        'ajmcm_tipo_c' => $tipos['participantes'],
        'pasada_el' => date('Y-m-d H:i:s', sticpa_pl_now()),
        'n_asistieron' => $result['counts']['yes'],
        'n_faltaron' => $result['counts']['no'],
        'assigned_user_id' => sticpa_pl_delegation($objSCP),
    );
    if ($lista !== null) {
        $payload['id'] = $lista['id'];
        $listaId = $objSCP->set_entry('LIS_listas', $payload);
        // ANTES ESTE FALLO NO CONTABA: la pantalla decía «Lista guardada» aunque
        // la lista no se hubiera escrito.
        if (!$listaId) {
            $result['failed']++;
            $result['errors'][] = array(
                'paso' => 'lista_actualizar',
                'id' => $lista['id'],
                'error' => sticpa_pl_crm_error($objSCP),
            );
        }
        $result['lista_id'] = $listaId ? $listaId : $lista['id'];
    } else {
        $listaId = $objSCP->set_entry('LIS_listas', $payload);
        if ($listaId) {
            if ($objSCP->set_relationship('LIS_listas', $listaId, 'lis_listas_stic_sessions', array($sessionId)) === false) {
                $result['failed']++;
                $result['errors'][] = array(
                    'paso' => 'lista_enlazar_sesion',
                    'id' => $listaId,
                    'error' => sticpa_pl_crm_error($objSCP),
                );
            }
            if ($objSCP->set_relationship('LIS_listas', $listaId, 'lis_listas_ajmcm_grupos', array($groupId)) === false) {
                $result['failed']++;
                $result['errors'][] = array(
                    'paso' => 'lista_enlazar_grupo',
                    'id' => $listaId,
                    'error' => sticpa_pl_crm_error($objSCP),
                );
            }
            // Quién la pasó es informativo: si falla, se anota pero no invalida
            // el guardado (la lista y las asistencias son lo que importa).
            $who = isset($_SESSION['scp_user_id']) ? $_SESSION['scp_user_id'] : '';
            if ($who) {
                if ($objSCP->set_relationship('LIS_listas', $listaId, 'lis_listas_contacts', array($who)) === false) {
                    $result['errors'][] = array(
                        'paso' => 'lista_enlazar_monitor',
                        'id' => $listaId,
                        'error' => sticpa_pl_crm_error($objSCP),
                    );
                }
            }
        } else {
            $result['failed']++;
            $result['errors'][] = array(
                'paso' => 'lista_crear',
                'id' => '',
                'error' => sticpa_pl_crm_error($objSCP),
            );
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

    sticpa_pl_cache_put($cacheKey, $out, $ttl);
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
    $userId = isset($_SESSION['scp_user_id']) ? (string) $_SESSION['scp_user_id'] : '';
    if ($userId === '') {
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

    // Camino normal: del mapa comun. Devuelve TODOS sus grupos, no el primero:
    // se puede ser monitor de varios.
    $ids = array();
    foreach (sticpa_pl_all_relationships($objSCP) as $rel) {
        if ($rel['role'] !== 'monitor' || $rel['person']['id'] !== $userId) {
            continue;
        }
        if ($rel['group_id'] !== '') {
            $ids[$rel['group_id']] = true;
        }
    }

    // RESPALDO, por lo mismo que en las personas de un grupo: si los enlaces no
    // vienen, el mapa no sabe de quien es cada relacion. Se pregunta por MIS
    // relaciones —esa llamada si funciona, es la que ya usa el alcance de
    // coordinacion— y luego el grupo de cada una. Son una o dos relaciones de
    // monitor por persona, asi que el coste es de una o dos llamadas.
    //
    // Sin esto la portada dice "no tienes ningun grupo asignado como monitor/a"
    // a un monitor con su relacion vigente, y el atajo del sabado desaparece.
    if (empty($ids) && !sticpa_pl_collecting()) {
        $ids = sticpa_pl_my_groups_direct($objSCP, $userId);
    }

    $ids = array_keys($ids);
    sticpa_pl_cache_put($cacheKey, $ids, $ttl);
    return $ids;
}

/**
 * Mis grupos preguntando por MIS relaciones, y el grupo de cada una.
 *
 * Devuelve un mapa id => true, para que quien llame lo trate igual que el
 * camino normal.
 */
function sticpa_pl_my_groups_direct($objSCP, $userId)
{
    $ids = array();

    $rels = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'Contacts',
        'module_id' => sticpa_pl_safe_id($userId),
        'link_field_name' => 'stic_contacts_relationships_contacts',
        // Se piden tambien los campos planos del grupo: si la instancia los
        // resuelve, no hace falta la segunda llamada.
        'related_fields' => array(
            'id', 'relationship_type', 'end_date',
            'ajmcm_grupos_stic_contacts_relationshipsajmcm_grupos_ida',
        ),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));
    if (!is_array($rels)) {
        return $ids;
    }

    $now = sticpa_pl_now();
    $asked = 0;
    $maxAsk = (int) apply_filters('sticpa_pl_max_my_groups_lookups', 6);

    foreach ($rels as $rel) {
        $v = isset($rel->name_value_list) ? $rel->name_value_list : null;
        if (!$v || empty($v->id->value)) {
            continue;
        }
        if (sticpa_pl_rel_role(isset($v->relationship_type->value) ? $v->relationship_type->value : '') !== 'monitor') {
            continue;
        }
        $endRaw = isset($v->end_date->value) ? trim((string) $v->end_date->value) : '';
        if ($endRaw !== '') {
            $endTs = strtotime($endRaw . ' 23:59:59');
            if ($endTs && $endTs < $now) {
                continue;
            }
        }

        // Primero el campo plano; si no, una llamada por relacion.
        $gid = sticpa_pl_nvl_first($v, array('ajmcm_grupos_stic_contacts_relationshipsajmcm_grupos_ida'));
        if ($gid === '' && $asked < $maxAsk) {
            $asked++;
            $gid = sticpa_pl_group_of_relationship($objSCP, (string) $v->id->value);
        }
        if ($gid !== '') {
            $ids[$gid] = true;
        }
    }

    return $ids;
}

/** El id del grupo al que apunta una relación, o '' si no apunta a ninguno. */
function sticpa_pl_group_of_relationship($objSCP, $relId)
{
    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'stic_Contacts_Relationships',
        'module_id' => sticpa_pl_safe_id($relId),
        'link_field_name' => 'ajmcm_grupos_stic_contacts_relationships',
        'related_fields' => array('id', 'name'),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0, 'order_by' => '', 'offset' => 0, 'limit' => 0,
    ));
    if (!is_array($rows)) {
        return '';
    }
    foreach ($rows as $row) {
        $g = isset($row->name_value_list) ? $row->name_value_list : null;
        if ($g && !empty($g->id->value)) {
            return (string) $g->id->value;
        }
    }
    return '';
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
/**
 * El nombre corto de la ficha: el de pila, y el completo si no lo hay.
 *
 * En la fila de su propio teléfono el artboard pone «Solete», no el nombre
 * entero: el apellido ya está tres centímetros más arriba, en el título.
 */
function sticpa_pl_nombre_corto_ficha($ficha)
{
    $first = isset($ficha['first_name']) ? trim((string) $ficha['first_name']) : '';
    if ($first !== '') {
        return $first;
    }
    return isset($ficha['name']) ? trim((string) $ficha['name']) : '';
}

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
        'related_fields' => array('id', 'status', 'stic_attendances_stic_sessionsstic_sessions_ida'),
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
                $sid = sticpa_pl_nvl_first($v, array('stic_attendances_stic_sessionsstic_sessions_ida'));
            }
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

    // LOS DOS CAMPOS PLANOS ACABAN EN `_ida`, Y ESO ERA EL BUG.
    //
    // El código pedía `stic_personal_environment_contacts_1contacts_idb`, que
    // NO EXISTE en el módulo (verificado con `get_module_fields` el 27/08/2026):
    // cada lado de la relación tiene su propio `..._ida`. Y como los datos del
    // familiar se leían SOLO del enlace anidado —que esta instancia no puebla
    // (trampa §3.1)— el bloque de la familia salía vacío en TODAS las fichas,
    // sin decir nada. Sin familia no hay teléfonos, y sin teléfonos la ficha no
    // sirve para lo que se abre un sábado.
    $ladoParticipante = 'stic_personal_environment_contactscontacts_ida';
    $ladoFamiliar = 'stic_personal_environment_contacts_1contacts_ida';

    $vinculos = array();   // id de contacto => datos de la relación
    // Se pregunta por los dos enlaces porque la relación puede estar creada en
    // cualquiera de los dos sentidos. En el piloto solo contesta el primero.
    foreach (array('stic_personal_environment_contacts', 'stic_personal_environment_contacts_1') as $link) {
        $rows = $objSCP->getRelatedElementsForLoggedUser(array(
            'module_name' => 'Contacts',
            'module_id' => $contactId,
            'link_field_name' => $link,
            'related_fields' => array(
                'id', 'relationship_type', 'reference_contact', 'authorized_signer', 'end_date',
                $ladoParticipante,
                $ladoFamiliar,
            ),
            // Se piden igual: si algún día la instancia los puebla, sale gratis
            // el nombre y el teléfono sin la segunda consulta.
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

            $rel = array(
                'relationship' => isset($v->relationship_type->value) ? (string) $v->relationship_type->value : '',
                'reference' => sticpa_pl_bool_crm(isset($v->reference_contact->value) ? $v->reference_contact->value : ''),
                'signer' => sticpa_pl_bool_crm(isset($v->authorized_signer->value) ? $v->authorized_signer->value : ''),
            );

            // Camino bueno: los campos planos. Los DOS lados, y se descarta el
            // del propio participante — el otro es el familiar.
            foreach (array($ladoParticipante, $ladoFamiliar) as $campo) {
                $id = isset($v->$campo->value) ? sticpa_pl_safe_id($v->$campo->value) : '';
                if ($id === '' || $id === $contactId || isset($vinculos[$id])) {
                    continue;
                }
                $vinculos[$id] = $rel;
            }

            // Y si el enlace anidado SÍ vino, se aprovecha lo que trae.
            foreach (sticpa_pl_link_records($row) as $rec) {
                $lv = $rec['value'];
                $id = isset($lv->id->value) ? sticpa_pl_safe_id($lv->id->value) : '';
                if ($id === '' || $id === $contactId) {
                    continue;
                }
                $first = isset($lv->first_name->value) ? trim((string) $lv->first_name->value) : '';
                $last = isset($lv->last_name->value) ? trim((string) $lv->last_name->value) : '';
                $full = trim($first . ' ' . $last);
                if ($full === '' && isset($lv->name->value)) {
                    $full = trim((string) $lv->name->value);
                }
                $vinculos[$id] = array_merge(
                    isset($vinculos[$id]) ? $vinculos[$id] : $rel,
                    array(
                        'name' => $full,
                        'first' => $first,
                        'last' => $last,
                        'mobile' => isset($lv->phone_mobile->value) ? (string) $lv->phone_mobile->value : '',
                    )
                );
            }
        }
    }

    if (empty($vinculos)) {
        return array();
    }

    // Los datos de los familiares, en UNA consulta para todos. El teléfono vive
    // en `phone_mobile` (verificado en el CRM), y no llega por el enlace: hay
    // que leer el contacto. Una llamada para toda la familia, nunca una por
    // persona.
    $faltan = array();
    foreach ($vinculos as $id => $datos) {
        if (!isset($datos['name']) || $datos['name'] === '' || !isset($datos['mobile'])) {
            $faltan[] = $id;
        }
    }
    if (!empty($faltan)) {
        $lista = array();
        foreach ($faltan as $id) {
            $lista[] = "'" . sticpa_pl_safe_id($id) . "'";
        }
        $rows = $objSCP->getRecordsModule(
            'Contacts',
            'contacts.id IN (' . implode(',', $lista) . ')',
            array('id', 'first_name', 'last_name', 'name', 'phone_mobile', 'email1')
        );
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $v = isset($row->name_value_list) ? $row->name_value_list : null;
                if (!$v || empty($v->id->value)) {
                    continue;
                }
                $id = (string) $v->id->value;
                if (!isset($vinculos[$id])) {
                    continue;
                }
                $first = isset($v->first_name->value) ? trim((string) $v->first_name->value) : '';
                $last = isset($v->last_name->value) ? trim((string) $v->last_name->value) : '';
                $full = trim($first . ' ' . $last);
                if ($full === '' && isset($v->name->value)) {
                    $full = trim((string) $v->name->value);
                }
                $vinculos[$id] = array_merge($vinculos[$id], array(
                    'name' => $full,
                    'first' => $first,
                    'last' => $last,
                    'mobile' => isset($v->phone_mobile->value) ? (string) $v->phone_mobile->value : '',
                    'email' => isset($v->email1->value) ? (string) $v->email1->value : '',
                ));
            }
        }
    }

    $people = array();
    foreach ($vinculos as $id => $datos) {
        $first = isset($datos['first']) ? $datos['first'] : '';
        $last = isset($datos['last']) ? $datos['last'] : '';
        $full = isset($datos['name']) ? $datos['name'] : '';
        // Sin nombre no se pinta: una fila con un teléfono y sin dueño no dice
        // a quién estás llamando.
        if ($full === '') {
            continue;
        }
        $people[] = array(
            'id' => $id,
            'name' => $full,
            'initials' => sticpa_pl_initials($first, $last, $full),
            'mobile' => isset($datos['mobile']) ? $datos['mobile'] : '',
            'email' => isset($datos['email']) ? $datos['email'] : '',
            'relationship' => isset($datos['relationship']) ? $datos['relationship'] : '',
            'reference' => !empty($datos['reference']),
            'signer' => !empty($datos['signer']),
        );
    }

    // La familia de referencia primero: es a quien se llama.
    usort($people, 'sticpa_pl_cmp_family');
    return $people;
}

/**
 * Cómo se lee un parentesco del CRM.
 *
 * Las claves están en INGLÉS (`mother`, verificado en el piloto) y pintarlas a
 * pelo deja «mother» debajo del nombre de la madre. La lista completa del
 * desplegable no está confirmada, así que lo que no esté aquí se enseña tal
 * cual con la primera letra en mayúscula: peor que la traducción, mejor que
 * esconder el dato.
 */
function sticpa_pl_parentescos()
{
    return apply_filters('sticpa_pl_parentescos', array(
        'mother' => __('Madre', 'sticpa'),
        'father' => __('Padre', 'sticpa'),
        'tutor' => __('Tutor/a', 'sticpa'),
        'grandmother' => __('Abuela', 'sticpa'),
        'grandfather' => __('Abuelo', 'sticpa'),
        'sister' => __('Hermana', 'sticpa'),
        'brother' => __('Hermano', 'sticpa'),
        'aunt' => __('Tía', 'sticpa'),
        'uncle' => __('Tío', 'sticpa'),
        'other' => __('Otro', 'sticpa'),
        // Y las de la convención en castellano, por si conviven las dos.
        'madre' => __('Madre', 'sticpa'),
        'padre' => __('Padre', 'sticpa'),
    ));
}

/** El parentesco, ya legible. */
function sticpa_pl_parentesco_label($raw)
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }
    $mapa = sticpa_pl_parentescos();
    $clave = strtolower($raw);
    if (isset($mapa[$clave])) {
        return $mapa[$clave];
    }
    return function_exists('mb_strtoupper')
        ? mb_strtoupper(mb_substr($raw, 0, 1)) . mb_substr($raw, 1)
        : ucfirst($raw);
}

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
 * El campo del CRM que dice si un grupo ENTRA en Pasar Lista.
 *
 * En `ajmcm_GRUPOS` hay ~150 grupos y la mayoría son históricos: aparecían
 * todos en el árbol y en el alcance de coordinación, y eso era ruido para quien
 * usa la pantalla y trabajo de más para el servidor.
 *
 * Es una casilla (`bool`) y se llama `ajmcm_pasar_lista_c`. Si algún día se
 * renombra en el CRM, se arregla SIN TOCAR CÓDIGO con este filtro.
 */
function sticpa_pl_grupo_activo_field()
{
    return (string) apply_filters('sticpa_pl_grupo_activo_field', 'ajmcm_pasar_lista_c');
}

/**
 * ¿Se puede pedir ese campo al CRM?
 *
 * Se pide en la MISMA consulta que ya se hace, así que no cuesta nada — pero
 * hasta que exista en el CRM hay que poder apagarlo:
 *
 *     add_filter('sticpa_pl_has_grupo_activo', '__return_false');
 */
function sticpa_pl_has_grupo_activo()
{
    return sticpa_pl_grupo_activo_field() !== ''
        && (bool) apply_filters('sticpa_pl_has_grupo_activo', true);
}

/**
 * ¿Es «sí» el valor de una casilla del CRM?
 *
 * SuiteCRM devuelve las casillas de formas distintas según por dónde salgan:
 * `1`/`0`, `on`/`off`, `true`/`false` o vacío. Tratar solo `'1'` como sí deja
 * fuera la mitad de los casos, y con este campo eso significaría esconder
 * grupos que sí están marcados.
 */
function sticpa_pl_bool_crm($raw)
{
    $v = strtolower(trim((string) $raw));
    return in_array($v, array('1', 'on', 'yes', 'true', 'checked'), true);
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

    // UNA sola carga para todas las sesiones. Antes esto era una llamada POR
    // SESION: doce sesiones por tres etapas son hasta treinta y seis llamadas
    // para pintar el resumen, y ahi se iban casi nueve segundos.
    $all = sticpa_pl_all_listas($objSCP);

    // Se conserva la forma de antes —cada celda con su sesión y sus listas—
    // porque el árbol y el resumen la recorren así: lo que cambia es de dónde
    // salen los datos, no lo que reciben.
    $out = array();
    foreach ($sessions as $s) {
        $sid = (string) $s['id'];
        $out[$sid] = array(
            'session' => $s,
            'listas' => isset($all[$sid]) ? $all[$sid] : array(),
        );
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
    $out = array();
    // Del mapa comun: antes esta funcion hacia su propia consulta a TODAS las
    // relaciones de la delegacion, la misma que ya hace el mapa. Y ademas
    // pasaba el nombre del enlace a pelo, lo que en PHP 8 era un TypeError
    // fatal que se llevaba la pantalla de resumen entera.
    foreach (sticpa_pl_all_relationships($objSCP) as $rel) {
        if ($rel['role'] !== 'participante' || $rel['group_id'] !== '') {
            continue;
        }
        $name = $rel['person']['name'];
        $out[] = array(
            'rel_id' => $rel['rel_id'],
            'name' => ($name !== '') ? $name : __('(sin nombre)', 'sticpa'),
            'initials' => $rel['person']['initials'],
        );
    }

    usort($out, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
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
        // El campo plano del grupo ademas del enlace. SIN ESTO el segmento del
        // alcance salia SIEMPRE vacio, y un coordinador de COM II veia la
        // delegacion entera en vez de su segmento. No es solo comodidad: es
        // quien puede editar los datos de quien.
        'related_fields' => array(
            'id', 'relationship_type', 'end_date', 'ajmcm_etapa_relacion_c',
            'ajmcm_grupos_stic_contacts_relationshipsajmcm_grupos_ida',
        ),
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
            if ($gid === '') {
                $gid = sticpa_pl_nvl_first($v, array('ajmcm_grupos_stic_contacts_relationshipsajmcm_grupos_ida'));
            }
            if ($gid === '') {
                $gid = sticpa_pl_group_of_relationship($objSCP, (string) $v->id->value);
            }
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

    sticpa_pl_cache_put($cacheKey, array('scope' => $scope), $ttl);
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
/**
 * El curso escolar de un grupo, convertido en un número que se puede ordenar.
 *
 * `cursos_c` es TEXTO LIBRE en el CRM («4º Primaria», «1º ESO», «2n
 * Batxillerat», «Adultos»…), así que ordenar alfabéticamente pone «1º ESO»
 * antes que «4º Primaria», que es justo al revés de como se lee una lista de
 * grupos. Aquí se traduce a un número: primero primaria por curso, luego la
 * ESO, luego bachillerato, y al final lo que no se reconozca.
 *
 * Se aceptan las formas en castellano y en valenciano porque en el CRM
 * conviven las dos. Lo que no encaje va al final, nunca se pierde.
 */
function sticpa_pl_curso_rank($cursos)
{
    $txt = trim((string) $cursos);
    if ($txt === '') {
        return 9000;   // sin curso: al final, pero antes que lo desconocido
    }

    $norm = function_exists('mb_strtolower') ? mb_strtolower($txt) : strtolower($txt);
    // Sin acentos, para que «Primària» y «Primaria» sean lo mismo.
    $norm = strtr($norm, array('à' => 'a', 'á' => 'a', 'è' => 'e', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ï' => 'i', 'ü' => 'u'));

    $etapas = apply_filters('sticpa_pl_rangos_curso', array(
        'infantil' => 0,
        'primaria' => 100,
        'prim' => 100,
        'eso' => 200,
        'secundaria' => 200,
        'bach' => 300,
        'batxillerat' => 300,
        'bachillerato' => 300,
        'fp' => 350,
        'universi' => 400,
        'adult' => 500,
    ));

    $base = 8000;   // no reconocido: detrás de todo lo que sí lo está
    foreach ($etapas as $aguja => $valor) {
        if (strpos($norm, $aguja) !== false) {
            $base = $valor;
            break;
        }
    }

    $curso = 0;
    if (preg_match('/(\d+)/', $norm, $m)) {
        $curso = (int) $m[1];
    }
    return $base + $curso;
}

function sticpa_pl_monitors_of($objSCP, $groups)
{
    // UNA pasada por el mapa de relaciones, no una consulta por grupo.
    //
    // Antes esto llamaba a sticpa_pl_group_people() por cada grupo del alcance.
    // Con el mapa caliente eran cero llamadas, sí, pero cada grupo VACÍO caía
    // en el respaldo por grupo: con ~150 grupos en el CRM, decenas de llamadas
    // al CRM para pintar una lista de doce monitores. Ahora el recorrido es
    // local y el coste no depende de cuántos grupos haya.
    $out = array();
    $mapaSirve = false;
    foreach (sticpa_pl_all_relationships($objSCP) as $rel) {
        if ($rel['person']['id'] !== '') {
            $mapaSirve = true;
        }
        if ($rel['role'] !== 'monitor' || $rel['person']['id'] === '') {
            continue;
        }
        $gid = $rel['group_id'];
        if ($gid === '' || !isset($groups[$gid])) {
            continue;   // de otro alcance, o sin grupo
        }
        $id = $rel['person']['id'];
        if (!isset($out[$id])) {
            $out[$id] = $rel['person'];
            $out[$id]['groups'] = array();
            // La etapa y el curso salen del grupo, y sirven para agrupar y
            // ordenar la pantalla. Con varios grupos manda el PRIMERO por
            // curso: un monitor de 4º de primaria y de 2º de la ESO se lee
            // antes con los pequeños, que es donde empieza su sábado.
            $out[$id]['etapa'] = '';
            $out[$id]['curso'] = '';
            $out[$id]['rank'] = 99999;
        }
        // Un monitor de dos grupos sale UNA vez con sus dos códigos, y sin
        // repetir el mismo código si tiene dos relaciones con el mismo grupo.
        $code = $groups[$gid]['code'];
        if (!in_array($code, $out[$id]['groups'], true)) {
            $out[$id]['groups'][] = $code;
        }
        $rank = sticpa_pl_curso_rank(isset($groups[$gid]['cursos']) ? $groups[$gid]['cursos'] : '');
        if ($rank < $out[$id]['rank']) {
            $out[$id]['rank'] = $rank;
            $out[$id]['curso'] = isset($groups[$gid]['cursos']) ? (string) $groups[$gid]['cursos'] : '';
            $out[$id]['etapa'] = isset($groups[$gid]['etapa']) ? (string) $groups[$gid]['etapa'] : '';
        }
    }

    // RESPALDO, con la misma regla que en sticpa_pl_group_people(): solo si el
    // mapa entero viene vacío. Aquí sí se paga el precio por grupo, porque sin
    // monitores coordinación no puede pasar su lista.
    if (empty($out) && !$mapaSirve && !sticpa_pl_collecting()) {
        foreach ($groups as $gid => $g) {
            $people = sticpa_pl_group_people_bulk($objSCP, $gid);
            foreach ($people['monitors'] as $m) {
                if (!isset($out[$m['id']])) {
                    $out[$m['id']] = $m;
                    $out[$m['id']]['groups'] = array();
                }
                if (!in_array($g['code'], $out[$m['id']]['groups'], true)) {
                    $out[$m['id']]['groups'][] = $g['code'];
                }
            }
        }
    }

    $out = array_values($out);
    // Por curso primero (los de 4.º antes que los de 5.º) y, a igual curso,
    // alfabético por apellido, que es como se lee una lista de personas.
    usort($out, function ($a, $b) {
        $ra = isset($a['rank']) ? (int) $a['rank'] : 99999;
        $rb = isset($b['rank']) ? (int) $b['rank'] : 99999;
        if ($ra !== $rb) {
            return ($ra < $rb) ? -1 : 1;
        }
        return sticpa_pl_cmp_person($a, $b);
    });
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

    if ($found !== null) {
        sticpa_pl_cache_put($cacheKey, $found, $ttl);
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
    $result = array(
        'saved' => 0, 'failed' => 0, 'lista_id' => '',
        'counts' => array('yes' => 0, 'no' => 0),
        'errors' => array(),
        // Lo que se ha intentado escribir, para que la pantalla pueda releer el
        // CRM y comprobar que está. En monitores no coincide con lo marcado: el
        // que no está marcado se guarda como «vino», que aquí es una afirmación.
        'written' => array(),
    );
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
        $result['written'][$m['id']] = $key;

        if (isset($existing[$m['id']]['id'])) {
            $ok = $objSCP->set_entry('stic_Attendances', array(
                'id' => $existing[$m['id']]['id'],
                'status' => $key,
            ));
            if ($ok) {
                $result['saved']++;
            } else {
                $result['failed']++;
                $result['errors'][] = array(
                    'paso' => 'asistencia_actualizar',
                    'id' => $existing[$m['id']]['id'],
                    'error' => sticpa_pl_crm_error($objSCP),
                );
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
            $result['errors'][] = array(
                'paso' => 'asistencia_crear',
                'id' => $m['id'],
                'error' => sticpa_pl_crm_error($objSCP),
            );
            continue;
        }
        if ($objSCP->set_relationship('stic_Attendances', $newId, 'stic_attendances_stic_sessions', array($sessionId)) === false) {
            $result['failed']++;
            $result['errors'][] = array(
                'paso' => 'asistencia_enlazar_sesion',
                'id' => $newId,
                'error' => sticpa_pl_crm_error($objSCP),
            );
        }
        $regId = array_search($m['id'], (array) $regMap, true);
        if ($regId !== false) {
            if ($objSCP->set_relationship('stic_Attendances', $newId, 'stic_attendances_stic_registrations', array($regId)) === false) {
                $result['failed']++;
                $result['errors'][] = array(
                    'paso' => 'asistencia_enlazar_inscripcion',
                    'id' => $newId,
                    'error' => sticpa_pl_crm_error($objSCP),
                );
            }
        } else {
            // Un monitor sin inscripción en el evento: la asistencia se queda
            // sin enlazar y el CRM no la contará. Es dato, no ruido.
            $result['errors'][] = array(
                'paso' => 'sin_inscripcion',
                'id' => $m['id'],
                'error' => __('este monitor no tiene inscripción en el evento: su asistencia queda sin enlazar', 'sticpa'),
            );
        }
        $result['saved']++;
    }

    // ---------------------------------------------------------------------
    // La lista de monitores de la sesión.
    // ---------------------------------------------------------------------
    // Antes esto no se escribía: se guardaban las asistencias y no quedaba
    // constancia de que la lista se hubiera pasado. `ajmcm_tipo_c` existe
    // justo para esto —`monitores` frente a `participantes`— y el plugin
    // tenía el mapa de valores sin que nadie lo llamara.
    //
    // NO lleva grupo, a diferencia de la de participantes: el alcance de
    // coordinación es la etapa, no un grupo. Ver el aviso de
    // `sticpa_pl_all_listas_monitores()` sobre qué pasa si dos etapas
    // comparten evento.
    $estados = sticpa_pl_lista_estados();
    $tipos = sticpa_pl_lista_tipos();
    $existentes = sticpa_pl_all_listas_monitores($objSCP);
    $lista = isset($existentes[$sessionId]) ? $existentes[$sessionId] : null;

    $payload = array(
        'estado' => $estados['pasada'],
        'ajmcm_tipo_c' => $tipos['monitores'],
        'pasada_el' => date('Y-m-d H:i:s', sticpa_pl_now()),
        'n_asistieron' => $result['counts']['yes'],
        'n_faltaron' => $result['counts']['no'],
        'assigned_user_id' => sticpa_pl_delegation($objSCP),
    );

    if ($lista !== null) {
        $payload['id'] = $lista['id'];
        $listaId = $objSCP->set_entry('LIS_listas', $payload);
        if (!$listaId) {
            $result['failed']++;
            $result['errors'][] = array(
                'paso' => 'lista_actualizar',
                'id' => $lista['id'],
                'error' => sticpa_pl_crm_error($objSCP),
            );
        }
        $result['lista_id'] = $listaId ? $listaId : $lista['id'];
    } else {
        $listaId = $objSCP->set_entry('LIS_listas', $payload);
        if (!$listaId) {
            $result['failed']++;
            $result['errors'][] = array(
                'paso' => 'lista_crear',
                'id' => '',
                'error' => sticpa_pl_crm_error($objSCP),
            );
        } else {
            if ($objSCP->set_relationship('LIS_listas', $listaId, 'lis_listas_stic_sessions', array($sessionId)) === false) {
                $result['failed']++;
                $result['errors'][] = array(
                    'paso' => 'lista_enlazar_sesion',
                    'id' => $listaId,
                    'error' => sticpa_pl_crm_error($objSCP),
                );
            }
            // Quién la pasó: informativo, no invalida el guardado.
            $who = isset($_SESSION['scp_user_id']) ? $_SESSION['scp_user_id'] : '';
            if ($who && $objSCP->set_relationship('LIS_listas', $listaId, 'lis_listas_contacts', array($who)) === false) {
                $result['errors'][] = array(
                    'paso' => 'lista_enlazar_monitor',
                    'id' => $listaId,
                    'error' => sticpa_pl_crm_error($objSCP),
                );
            }
        }
        $result['lista_id'] = $listaId ? $listaId : '';
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
 * ENCENDIDO Y VERIFICADO. El acceso del usuario de la API a `stic_FollowUps`
 * está dado, y los nombres de `sticpa_pl_seg_map()` están comprobados contra la
 * instancia con `get_module_fields` — el único que no coincidía con la
 * convención documentada era la fecha (`start_date`, no `date_start`), y ya está
 * corregido. Si algún día cambiara, se arregla SIN TOCAR CÓDIGO con el filtro
 * `sticpa_pl_seg_map`. Y si hay que apagarlo:
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
        'f_date' => 'start_date',
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

    sticpa_pl_cache_put($cacheKey, array('is' => $is), $ttl);
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
