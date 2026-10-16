<?php
/**
 * GRUPOS DE ACTIVIDAD — patrullas, habitaciones, grupos de dinámica… de UN
 * evento, para CONSULTARLOS en el móvil (y pasar lista por ellos).
 * ----------------------------------------------------------------------------
 * Se crean y se reparten en Comunica Tools (escritorio, arrastrando); aquí
 * solo se LEEN. Viven en dos módulos del CRM que hizo ese proyecto:
 * `GAC_grupos` (un grupo) y `GAC_miembros` (una ficha por persona y grupo, con
 * su papel: monitor o participante). Nada que ver con `ajmcm_GRUPOS` ni con
 * las relaciones de los sábados.
 *
 * Dónde se ven: en la pantalla del evento de Pasar lista
 * (pages/single_stic_pasar_lista_evento.php), una pestaña por agrupación
 * («Lista · Patrullas · Habitaciones»). La portada de Pasar lista no se entera:
 * ni una llamada más los sábados.
 *
 * Coste: dos consultas (grupos y miembros), en la PRIMERA tanda paralela de la
 * pantalla del evento y cacheadas con el TTL de estado. Mientras el módulo no
 * exista en el CRM, el «no existe» se recuerda una hora y no se vuelve a
 * preguntar.
 *
 * Diseño y decisiones: docs/comunica/GRUPOS-ACTIVIDAD.md.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Los nombres del CRM, EN UN SOLO SITIO (como `api/_lib/fields.ts` en Comunica
 * Tools). Son los previstos en CAMPOS.md: si al crear el módulo alguno sale
 * distinto (un `_c`, un `_1`), se cambia aquí o con el filtro, y en ningún otro
 * sitio.
 */
function sticpa_gac_nombres()
{
    return (array) apply_filters('sticpa_gac_nombres', array(
        'mod_grupos' => 'GAC_grupos',
        'mod_miembros' => 'GAC_miembros',
        'tabla_grupos' => 'gac_grupos',
        'tabla_miembros' => 'gac_miembros',
        // Miembro → grupo, persona e inscripción: campo plano y enlace.
        'm_grupo_ida' => 'gac_grupos_gac_miembrosgac_grupos_ida',
        'm_grupo_link' => 'gac_grupos_gac_miembros',
        // Persona e inscripción son MUCHOS A MUCHOS (comprobado el 16/10/2026):
        // no tienen campo plano y solo llegan por el enlace.
        'm_persona_link' => 'gac_miembros_contacts',
        'm_insc_link' => 'gac_miembros_stic_registrations',
    ));
}

/**
 * Los tipos de agrupación (`gac_tipo_list`): singular para un grupo, plural
 * para la pestaña. En el orden de las pestañas. Los mismos que
 * `ACTIVITY_TYPES` de Comunica Tools.
 */
function sticpa_gac_tipos()
{
    return array(
        'patrulla' => array(__('Patrulla', 'sticpa'), __('Patrullas', 'sticpa')),
        'dinamica' => array(__('Grupo de dinámica', 'sticpa'), __('Dinámica', 'sticpa')),
        'habitacion' => array(__('Habitación', 'sticpa'), __('Habitaciones', 'sticpa')),
        'olimpiadas' => array(__('Olimpiadas', 'sticpa'), __('Olimpiadas', 'sticpa')),
        'limpieza' => array(__('Limpieza', 'sticpa'), __('Limpieza', 'sticpa')),
        'subgrupo' => array(__('Subgrupo', 'sticpa'), __('Subgrupos', 'sticpa')),
        // Añadidos al desplegable el 16/10/2026 (`tipo_list`, el propietario).
        'clan' => array(__('Clan', 'sticpa'), __('Clanes', 'sticpa')),
        'casa' => array(__('Casa', 'sticpa'), __('Casas', 'sticpa')),
        'agrupacion' => array(__('Agrupación grande', 'sticpa'), __('Agrupaciones', 'sticpa')),
        'otros' => array(__('Otros', 'sticpa'), __('Otros', 'sticpa')),
    );
}

/**
 * El grupo de una persona, CORTO, para la línea pequeña bajo su nombre en la
 * lista del evento: «Patrulla Halcones», «Hab. 12», «Clan Norte». Si el nombre
 * ya dice el tipo («Patrulla 3»), tal cual; si no, con el tipo delante. Los
 * nombres largos se cortan: es una pista, el grupo entero está en su pestaña.
 */
function sticpa_gac_etiqueta_corta($tipo, $name)
{
    $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
    $corto = array(
        'patrulla' => __('Patrulla', 'sticpa'),
        'dinamica' => __('Dinámica', 'sticpa'),
        'habitacion' => __('Hab.', 'sticpa'),
        'olimpiadas' => __('Olimp.', 'sticpa'),
        'limpieza' => __('Limpieza', 'sticpa'),
        'subgrupo' => __('Subgrupo', 'sticpa'),
        'clan' => __('Clan', 'sticpa'),
        'casa' => __('Casa', 'sticpa'),
    );
    $len = function ($t) {
        return function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t);
    };
    if ($len($name) > 22) {
        $name = rtrim(function_exists('mb_substr') ? mb_substr($name, 0, 21, 'UTF-8') : substr($name, 0, 21)) . '…';
    }
    if (!isset($corto[$tipo]) || $name === '') {
        return $name;
    }
    $norm = function ($t) {
        $t = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
        return strtr($t, array('á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u'));
    };
    // «Patrulla 3», «Habitación 12», «Hab 12»: ya lo dice.
    $raiz = $norm(rtrim($corto[$tipo], '.'));
    $raiz = substr($raiz, 0, max(3, min(5, strlen($raiz))));
    if (strpos($norm($name), $raiz) === 0) {
        return $name;
    }
    return $corto[$tipo] . ' ' . $name;
}

/**
 * Los grupos del EVENTO de cada persona, cortos y en el orden de los tipos,
 * para la lista del autobús: contactId => array('Patrulla Halcones', 'Hab. 12').
 * Solo de los grupos que se enseñan. La persona sale por el enlace del miembro
 * o, si no llegó, por su inscripción.
 *
 * PURA.
 *
 * @param array $grupos   sticpa_gac_visibles()
 * @param array $miembros sticpa_gac_miembros()
 * @param array $regs     sticpa_pl_ev_inscripciones() (reg_id → contact_id)
 */
function sticpa_gac_de_cada_uno($grupos, $miembros, $regs = array())
{
    $porId = array();
    foreach ((array) $grupos as $g) {
        $porId[$g['id']] = $g;
    }
    $regContacto = array();
    foreach ((array) $regs as $r) {
        if (!empty($r['reg_id']) && !empty($r['contact_id'])) {
            $regContacto[$r['reg_id']] = $r['contact_id'];
        }
    }
    $orden = array_flip(array_keys(sticpa_gac_tipos()));
    $tmp = array();
    foreach ((array) $miembros as $m) {
        if (empty($m['grupo_id']) || !isset($porId[$m['grupo_id']])) {
            continue;
        }
        $cid = !empty($m['contact_id']) ? $m['contact_id']
            : ((!empty($m['reg_id']) && isset($regContacto[$m['reg_id']])) ? $regContacto[$m['reg_id']] : '');
        if ($cid === '') {
            continue;
        }
        $g = $porId[$m['grupo_id']];
        $pos = isset($orden[$g['tipo']]) ? $orden[$g['tipo']] : 99;
        $tmp[$cid][$pos . '|' . $g['tab'] . '|' . $g['id']] = sticpa_gac_etiqueta_corta($g['tipo'], $g['name']);
    }
    $out = array();
    foreach ($tmp as $cid => $etiquetas) {
        ksort($etiquetas, SORT_NATURAL);
        $out[$cid] = array_values(array_unique($etiquetas));
    }
    return $out;
}

/** El nombre de la tanda, limpio: «Mitades». '' si no hay. */
function sticpa_gac_agrupacion($raw)
{
    return trim(preg_replace('/\s+/u', ' ', (string) $raw));
}

/**
 * La clave de una pestaña: el tipo y, si la hay, la tanda («subgrupo|mitades»).
 * Es la misma que `tabKeyOf` de Comunica Tools, así que una pestaña se llama
 * igual en las dos apps.
 */
function sticpa_gac_tab_key($tipo, $agrupacion = '')
{
    $agr = sticpa_gac_agrupacion($agrupacion);
    return (string) $tipo . '|' . (function_exists('mb_strtolower') ? mb_strtolower($agr, 'UTF-8') : strtolower($agr));
}

/** El nombre de una pestaña: la tanda si la hay; si no, el tipo en plural. */
function sticpa_gac_tab_label($tipo, $agrupacion = '')
{
    $agr = sticpa_gac_agrupacion($agrupacion);
    if ($agr !== '') {
        return $agr;
    }
    $tipos = sticpa_gac_tipos();
    return isset($tipos[$tipo]) ? $tipos[$tipo][1] : __('Grupos', 'sticpa');
}

/** «Sin patrulla», «Sin habitación», «Sin grupo». */
function sticpa_gac_sin_label($tipo, $agrupacion = '')
{
    $sin = array(
        'patrulla' => __('Sin patrulla', 'sticpa'),
        'habitacion' => __('Sin habitación', 'sticpa'),
    );
    return (sticpa_gac_agrupacion($agrupacion) === '' && isset($sin[$tipo])) ? $sin[$tipo] : __('Sin grupo', 'sticpa');
}

/** ¿Marcada? Una casilla del CRM vacía cuenta como marcada: así nace. */
function sticpa_gac_casilla($raw)
{
    $raw = strtolower(trim((string) $raw));
    return ($raw === '') ? true : in_array($raw, array('1', 'on', 'yes', 'true'), true);
}

// ---------------------------------------------------------------------------
// Lectura (CRM)
// ---------------------------------------------------------------------------

/**
 * ¿El CRM ha dicho que el módulo no existe (o que no deja verlo)? Se recuerda
 * UNA HORA, para que, hasta que se cree, la pantalla del evento no pague cada
 * vez una consulta que va a fallar. El botón de refrescar lo olvida (va en la
 * familia de estructura).
 */
function sticpa_gac_ttl_ausente()
{
    return (int) apply_filters('sticpa_gac_ttl_ausente', HOUR_IN_SECONDS);
}

/** El error del CRM es «no existe el módulo» o «sin acceso». */
function sticpa_gac_error_es_ausencia($error)
{
    return (bool) preg_match('/module does not exist|no existe|access denied|#20\b|#40\b/i', (string) $error);
}

/**
 * Los GRUPOS de un evento. Una consulta por `evento_id` (en este CRM no se
 * puede filtrar por el campo plano de un enlace; ese campo de texto lo escribe
 * Comunica Tools al crear el grupo).
 *
 * El plugin entra con un usuario técnico que lo ve TODO: los grupos de seguridad
 * no filtran nada aquí, así que se filtra a mano por la delegación dueña del
 * evento (CLAUDE.md). Quien llama ya ha comprobado que el evento es suyo.
 *
 * @return array de array('id', 'name', 'tipo', 'agrupacion', 'tab',
 *               'con_monitor', 'plazas', 'activo')
 */
function sticpa_gac_grupos($objSCP, $eventId)
{
    $eventId = sticpa_pl_safe_id($eventId);
    $deleg = sticpa_pl_delegation($objSCP);
    if ($eventId === '' || !$deleg) {
        return array();
    }
    $ausenteKey = sticpa_pl_cache_key('gacaus', $objSCP);
    if (get_transient($ausenteKey)) {
        return array();
    }
    $cacheKey = sticpa_pl_cache_key('gacg', $objSCP, $eventId);
    $ttl = sticpa_pl_ttl_state();
    if ($ttl > 0) {
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $n = sticpa_gac_nombres();
    $rows = $objSCP->getRecordsModule(
        $n['mod_grupos'],
        $n['tabla_grupos'] . ".evento_id = '" . $eventId . "'",
        array('id', 'name', 'tipo', 'agrupacion', 'con_monitor', 'plazas', 'activo', 'evento_id', 'assigned_user_id')
    );
    if (sticpa_pl_collecting()) {
        return array();
    }
    $error = sticpa_pl_crm_error($objSCP);
    if (empty($rows) && $error !== '' && sticpa_gac_error_es_ausencia($error)) {
        set_transient($ausenteKey, 1, sticpa_gac_ttl_ausente());
        return array();
    }

    $tipos = sticpa_gac_tipos();
    $out = array();
    foreach ((array) $rows as $row) {
        $v = isset($row->name_value_list) ? $row->name_value_list : null;
        if (!$v || empty($v->id->value)) {
            continue;
        }
        $owner = isset($v->assigned_user_id->value) ? (string) $v->assigned_user_id->value : '';
        $ev = isset($v->evento_id->value) ? (string) $v->evento_id->value : '';
        $tipo = isset($v->tipo->value) ? (string) $v->tipo->value : '';
        // De otra delegación, de otro evento o con un tipo que no conocemos: fuera.
        if ($owner !== (string) $deleg || $ev !== $eventId || !isset($tipos[$tipo])) {
            continue;
        }
        $agr = sticpa_gac_agrupacion(isset($v->agrupacion->value) ? $v->agrupacion->value : '');
        $plazas = isset($v->plazas->value) ? (int) $v->plazas->value : 0;
        $name = isset($v->name->value) ? trim((string) $v->name->value) : '';
        $out[] = array(
            'id' => sticpa_pl_safe_id($v->id->value),
            'name' => ($name !== '') ? $name : __('(sin nombre)', 'sticpa'),
            'tipo' => $tipo,
            'agrupacion' => $agr,
            'tab' => sticpa_gac_tab_key($tipo, $agr),
            'con_monitor' => sticpa_gac_casilla(isset($v->con_monitor->value) ? $v->con_monitor->value : ''),
            'plazas' => ($plazas > 0) ? $plazas : 0,
            'activo' => sticpa_gac_casilla(isset($v->activo->value) ? $v->activo->value : ''),
        );
    }

    // Un fallo del CRM no se cachea como «no hay grupos».
    if (!empty($out) || $error === '') {
        sticpa_pl_cache_put($cacheKey, $out, $ttl);
    }
    return $out;
}

/**
 * Las FICHAS DE MIEMBRO de un evento, por `evento_id`.
 *
 * El grupo y la persona se piden por los dos caminos —campo plano y enlace de
 * `get_entry_list`— y se usa el que llegue (PASAR-LISTA-ESTADO.md §3.1: esta
 * instancia deja vacíos algunos campos planos de enlace).
 *
 * @return array de array('id', 'name', 'papel', 'tab', 'grupo_id',
 *               'contact_id', 'reg_id')
 */
function sticpa_gac_miembros($objSCP, $eventId)
{
    $eventId = sticpa_pl_safe_id($eventId);
    $deleg = sticpa_pl_delegation($objSCP);
    if ($eventId === '' || !$deleg) {
        return array();
    }
    if (get_transient(sticpa_pl_cache_key('gacaus', $objSCP))) {
        return array();
    }
    $cacheKey = sticpa_pl_cache_key('gacm', $objSCP, $eventId);
    $ttl = sticpa_pl_ttl_state();
    if ($ttl > 0) {
        $cached = get_transient($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }
    }

    $n = sticpa_gac_nombres();
    // Los alias NO pueden llamarse como un campo del módulo: el aplanado
    // escribe `<alias>_id`, y `evento_id` es un campo de verdad de GAC_*.
    $rows = $objSCP->getRecordsModule(
        $n['mod_miembros'],
        $n['tabla_miembros'] . ".evento_id = '" . $eventId . "'",
        array('id', 'name', 'papel', 'tipo', 'agrupacion', 'evento_id', 'assigned_user_id',
            $n['m_grupo_ida']),
        array(
            'gac_grupo' => array('relationshipName' => $n['m_grupo_link'], 'fields' => array('id')),
            'gac_persona' => array('relationshipName' => $n['m_persona_link'], 'fields' => array('id')),
            'gac_insc' => array('relationshipName' => $n['m_insc_link'], 'fields' => array('id')),
        )
    );
    if (sticpa_pl_collecting()) {
        return array();
    }
    $error = sticpa_pl_crm_error($objSCP);

    $tipos = sticpa_gac_tipos();
    $out = array();
    foreach ((array) $rows as $row) {
        $v = isset($row->name_value_list) ? $row->name_value_list : null;
        if (!$v || empty($v->id->value)) {
            continue;
        }
        $owner = isset($v->assigned_user_id->value) ? (string) $v->assigned_user_id->value : '';
        $ev = isset($v->evento_id->value) ? (string) $v->evento_id->value : '';
        $tipo = isset($v->tipo->value) ? (string) $v->tipo->value : '';
        if ($owner !== (string) $deleg || $ev !== $eventId || !isset($tipos[$tipo])) {
            continue;
        }
        $agr = sticpa_gac_agrupacion(isset($v->agrupacion->value) ? $v->agrupacion->value : '');
        $out[] = array(
            'id' => sticpa_pl_safe_id($v->id->value),
            'name' => isset($v->name->value) ? trim((string) $v->name->value) : '',
            'papel' => (isset($v->papel->value) && (string) $v->papel->value === 'monitor') ? 'monitor' : 'participante',
            'tab' => sticpa_gac_tab_key($tipo, $agr),
            'grupo_id' => sticpa_pl_safe_id(sticpa_pl_nvl_first($v, array($n['m_grupo_ida'], 'gac_grupo_id'))),
            'contact_id' => sticpa_pl_safe_id(sticpa_pl_nvl_first($v, array('gac_persona_id'))),
            'reg_id' => sticpa_pl_safe_id(sticpa_pl_nvl_first($v, array('gac_insc_id'))),
        );
    }

    if (!empty($out) || $error === '') {
        sticpa_pl_cache_put($cacheKey, $out, $ttl);
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Lógica pura
// ---------------------------------------------------------------------------

/**
 * Los grupos que se ENSEÑAN.
 *
 * Un grupo «anulado» en Comunica Tools lleva `activo` desmarcado y no sale.
 * Pero cuando el evento acaba, el flujo nocturno del CRM desmarca TODOS (pasan
 * a archivo), y entonces se enseñan los que tienen a alguien dentro: un grupo
 * anulado casi siempre está vacío.
 *
 * @param array $grupos   de sticpa_gac_grupos()
 * @param array $miembros de sticpa_gac_miembros()
 * @param bool  $acabado  el evento ya ha terminado
 */
function sticpa_gac_visibles($grupos, $miembros, $acabado)
{
    $conGente = array();
    foreach ((array) $miembros as $m) {
        if ($m['grupo_id'] !== '') {
            $conGente[$m['grupo_id']] = true;
        }
    }
    $out = array();
    foreach ((array) $grupos as $g) {
        if ($g['activo'] || ($acabado && isset($conGente[$g['id']]))) {
            $out[] = $g;
        }
    }
    return $out;
}

/**
 * Las PESTAÑAS: una por agrupación, en el orden de los tipos y, dentro, por
 * nombre de la tanda. Cada una con cuántos grupos tiene.
 *
 * @return array tabKey => array('key', 'tipo', 'agrupacion', 'label', 'n')
 */
function sticpa_gac_pestanas($grupos)
{
    $orden = array_flip(array_keys(sticpa_gac_tipos()));
    $out = array();
    foreach ((array) $grupos as $g) {
        if (!isset($out[$g['tab']])) {
            $out[$g['tab']] = array(
                'key' => $g['tab'],
                'tipo' => $g['tipo'],
                'agrupacion' => $g['agrupacion'],
                'label' => sticpa_gac_tab_label($g['tipo'], $g['agrupacion']),
                'n' => 0,
            );
        }
        $out[$g['tab']]['n']++;
    }
    uasort($out, function ($a, $b) use ($orden) {
        $pa = isset($orden[$a['tipo']]) ? $orden[$a['tipo']] : 99;
        $pb = isset($orden[$b['tipo']]) ? $orden[$b['tipo']] : 99;
        return ($pa !== $pb) ? $pa - $pb : strcmp($a['agrupacion'], $b['agrupacion']);
    });
    return $out;
}

/**
 * «Patrulla 2» antes que «Patrulla 10»: orden natural, sin distinguir
 * mayúsculas.
 */
function sticpa_gac_cmp_nombre($a, $b)
{
    $cmp = strnatcasecmp($a['name'], $b['name']);
    return ($cmp !== 0) ? $cmp : strcmp($a['id'], $b['id']);
}

/**
 * UNA PESTAÑA, ARMADA: sus grupos con sus monitores y participantes, y quién
 * de los que van se ha quedado sin grupo. PURA.
 *
 * La persona sale de la lista del evento (`sticpa_pl_ev_lista()`): así tiene
 * su curso, su grupo de los sábados y el enlace a su ficha, igual que en la
 * lista del autobús. Un monitor que NO está inscrito (pasa: no siempre se
 * apuntan) no está en esa lista; sale con el nombre de su ficha de miembro y
 * no se puede marcar.
 *
 * @param string $tab      clave de la pestaña
 * @param array  $grupos   los visibles (sticpa_gac_visibles())
 * @param array  $miembros de sticpa_gac_miembros()
 * @param array  $lista    de sticpa_pl_ev_lista(): 'activos' y 'fuera'
 * @param array  $regs     de sticpa_pl_ev_inscripciones(): para atar una
 *                         ficha de miembro que solo trae la inscripción
 * @return array('grupos' => [ grupo + 'monitores', 'participantes', 'n' ],
 *               'sin' => [personas activas sin grupo en esta pestaña],
 *               'de' => contactId => grupoId)
 */
function sticpa_gac_armar($tab, $grupos, $miembros, $lista, $regs = array())
{
    $activos = array();
    foreach ((array) $lista['activos'] as $p) {
        $activos[$p['id']] = $p;
    }
    $fuera = array();
    foreach ((array) $lista['fuera'] as $p) {
        $fuera[$p['id']] = $p;
    }
    $porReg = array();
    foreach ((array) $regs as $r) {
        if (!empty($r['reg_id']) && !empty($r['contact_id'])) {
            $porReg[$r['reg_id']] = $r['contact_id'];
        }
    }

    $out = array();
    foreach ((array) $grupos as $g) {
        if ($g['tab'] === $tab) {
            $out[$g['id']] = $g + array('monitores' => array(), 'participantes' => array(), 'n' => 0);
        }
    }

    $de = array();
    foreach ((array) $miembros as $m) {
        if ($m['tab'] !== $tab || $m['grupo_id'] === '' || !isset($out[$m['grupo_id']])) {
            continue;   // de otra pestaña, sin grupo, o de un grupo anulado
        }
        $cid = $m['contact_id'];
        if ($cid === '' && $m['reg_id'] !== '' && isset($porReg[$m['reg_id']])) {
            $cid = $porReg[$m['reg_id']];
        }
        if ($cid !== '' && isset($de[$cid])) {
            continue;   // uno por pestaña: si el CRM trae dos, vale el primero
        }
        if ($cid !== '' && isset($activos[$cid])) {
            $p = $activos[$cid];
            $p['marca'] = true;
        } else {
            // Monitor no inscrito, inscripción cancelada o persona sin ficha
            // legible: el nombre de la ficha de miembro (lo pone Comunica Tools).
            $p = isset($fuera[$cid]) ? $fuera[$cid] : null;
            if ($p === null) {
                $partes = explode(' ', $m['name'], 2);
                $p = sticpa_pl_person_array($cid !== '' ? $cid : $m['id'], $partes[0], isset($partes[1]) ? $partes[1] : '',
                    $m['name'] !== '' ? $m['name'] : __('(sin nombre)', 'sticpa'));
                $p['orden'] = sticpa_pl_ev_clave_nombre($p['last'], $p['first'], $p['full']);
                $p['curso_label'] = '';
                $p['grupo_id'] = '';
                $p['grupo_code'] = '';
                $p['status_label'] = '';
            }
            $p['marca'] = false;
        }
        $clave = ($m['papel'] === 'monitor' && $out[$m['grupo_id']]['con_monitor']) ? 'monitores' : 'participantes';
        $out[$m['grupo_id']][$clave][] = $p;
        $out[$m['grupo_id']]['n']++;
        if ($cid !== '') {
            $de[$cid] = $m['grupo_id'];
        }
    }

    $porNombre = function ($a, $b) {
        $cmp = strcmp($a['orden'], $b['orden']);
        return ($cmp !== 0) ? $cmp : strcmp($a['id'], $b['id']);
    };
    foreach ($out as $id => $g) {
        usort($out[$id]['monitores'], $porNombre);
        usort($out[$id]['participantes'], $porNombre);
    }
    $out = array_values($out);
    usort($out, 'sticpa_gac_cmp_nombre');

    $sin = array();
    foreach ($activos as $cid => $p) {
        if (!isset($de[$cid])) {
            $sin[] = $p;
        }
    }
    usort($sin, $porNombre);

    return array('grupos' => $out, 'sin' => $sin, 'de' => $de);
}

// ---------------------------------------------------------------------------
// Pintado
// ---------------------------------------------------------------------------

/** La URL de una pestaña del evento. `$tab` vacío es la lista de siempre. */
function sticpa_gac_url($eventId, $tab = '', $extra = array())
{
    $url = sticpa_pl_ev_url($eventId);
    if ($tab !== '') {
        $url .= '&grupos=' . rawurlencode($tab);
    }
    foreach ((array) $extra as $k => $v) {
        $url .= '&' . rawurlencode($k) . '=' . rawurlencode($v);
    }
    return $url;
}

/**
 * Las pestañas del evento: «Lista» (la de marcar, la del autobús) y una por
 * agrupación. Sin grupos de actividad, nada: la pantalla queda como siempre.
 *
 * @param string $current clave de la pestaña abierta; '' es la lista.
 */
function sticpa_gac_tabs_html($eventId, $pestanas, $current)
{
    if (empty($pestanas)) {
        return '';
    }
    $html = '<nav class="pl-tabs pl-gac-tabs" aria-label="' . esc_attr__('Vistas del evento', 'sticpa') . '">';
    $items = array('' => array('label' => __('Lista', 'sticpa'), 'n' => null));
    foreach ($pestanas as $k => $t) {
        $items[$k] = $t;
    }
    foreach ($items as $k => $t) {
        $activo = ((string) $k === (string) $current);
        $html .= '<a class="pl-tab' . ($activo ? ' is-active' : '') . '"'
            . ($activo ? ' aria-current="page"' : '')
            . ' href="' . esc_url(sticpa_gac_url($eventId, (string) $k)) . '">'
            . esc_html($t['label'])
            . ($t['n'] !== null ? '<span class="pl-gac-tab-n">' . (int) $t['n'] . '</span>' : '')
            . '</a>';
    }
    $html .= '</nav>';
    return $html;
}

/** La línea de debajo del nombre: curso corto y grupo de los sábados. */
function sticpa_gac_sub($p)
{
    $sub = array();
    if (!empty($p['curso_label'])) {
        $sub[] = $p['curso_label'];
    }
    if (!empty($p['grupo_code'])) {
        $sub[] = $p['grupo_code'];
    }
    if (!empty($p['status_label'])) {
        $sub[] = $p['status_label'];
    }
    return implode(' · ', $sub);
}

/**
 * Una persona en la vista de grupos: enlace a su ficha si tiene grupo de los
 * sábados en la delegación (teléfonos de casa, salud: lo que se busca en una
 * acampada), y fila quieta si no. El nombre del grupo va escondido dentro para
 * que el buscador encuentre «patrulla 3».
 */
function sticpa_gac_persona_html($p, $grupoNombre, $esMonitor)
{
    $sub = sticpa_gac_sub($p);
    if ($esMonitor) {
        $sub = ($sub !== '') ? __('Monitor/a', 'sticpa') . ' · ' . $sub : __('Monitor/a', 'sticpa');
    }
    $oculto = '<span hidden>' . esc_html($grupoNombre) . '</span>';
    if (!empty($p['grupo_id'])) {
        $href = '?internalpage=single_stic_pasar_lista_ficha&participante=' . rawurlencode($p['id'])
            . '&grupo=' . rawurlencode($p['grupo_id']);
        $row = sticpa_pl_person_link_html($p, $href, $sub);
        return preg_replace('/<\/a>$/', $oculto . '</a>', $row);
    }
    $html = '<div class="pl-rowwrap pl-rowstatic' . ($esMonitor ? ' pl-gac-monitor' : '') . '">' . sticpa_pl_avatar_html($p);
    $html .= '<span class="pl-row-body"><span class="pl-name">' . esc_html($p['name']) . '</span>';
    if ($sub !== '') {
        $html .= '<span class="pl-rowsub">' . esc_html($sub) . '</span>';
    }
    $html .= $oculto . '</span></div>';
    return $html;
}

/**
 * LA VISTA DE UNA PESTAÑA: cada grupo con su cabecera (nombre, cuántos,
 * plazas) y su gente, monitores primero. Al final, quién de los que van se ha
 * quedado sin grupo. Con el buscador de siempre: «¿en qué habitación está
 * Lucía?» deja solo a Lucía bajo el título de su habitación.
 *
 * Cada grupo lleva su «Pasar lista»: abre la lista del evento con solo ese
 * grupo a la vista (mismo marcado, mismo guardado, misma cola sin cobertura).
 *
 * @param array $tabInfo  la pestaña (sticpa_gac_pestanas())
 * @param array $armado   de sticpa_gac_armar()
 */
function sticpa_gac_vista_html($eventId, $tabInfo, $armado)
{
    $html = '';
    $grupos = $armado['grupos'];
    $total = 0;
    foreach ($grupos as $g) {
        $total += $g['n'];
    }

    $html .= sticpa_pl_buscador_html(__('Buscar a alguien o un grupo', 'sticpa'));

    if (empty($grupos)) {
        $html .= '<p class="pl-hint">' . sticpa_pl_icon('info') . '<span>'
            . esc_html__('Esta agrupación no tiene grupos.', 'sticpa') . '</span></p>';
        return $html;
    }

    // Lo primero que se pregunta: cuántos grupos y cuánta gente colocada.
    $html .= '<p class="pl-gac-resumen">' . esc_html(sprintf(
        /* translators: 1: «4 patrullas», 2: «32 personas» */
        __('%1$s · %2$s', 'sticpa'),
        sprintf(_n('%d grupo', '%d grupos', count($grupos), 'sticpa'), count($grupos)),
        sprintf(_n('%d persona', '%d personas', $total, 'sticpa'), $total)
    )) . '</p>';

    $html .= '<a class="pl-gac-pasar" href="' . esc_url(sticpa_pl_ev_url($eventId) . '&por=' . rawurlencode($tabInfo['key'])) . '">'
        . sticpa_pl_glyph('check') . '<span>' . esc_html(sprintf(
            /* translators: %s: nombre de la agrupación en minúscula, p. ej. «patrullas» */
            __('Pasar lista por %s', 'sticpa'),
            function_exists('mb_strtolower') ? mb_strtolower($tabInfo['label'], 'UTF-8') : strtolower($tabInfo['label'])
        )) . '</span></a>';

    foreach ($grupos as $g) {
        $meta = array();
        if ($g['plazas'] > 0) {
            $meta[] = sprintf(
                /* translators: 1: ocupadas, 2: plazas */
                __('%1$d/%2$d plazas', 'sticpa'),
                $g['n'],
                $g['plazas']
            );
        } else {
            $meta[] = (string) $g['n'];
        }
        $lleno = ($g['plazas'] > 0 && $g['n'] > $g['plazas']);
        $html .= '<div class="pl-sec pl-gac-sec" role="heading" aria-level="2">'
            . '<span class="pl-gac-sec-name">' . esc_html($g['name']) . '</span>'
            . '<span class="pl-gac-sec-n' . ($lleno ? ' is-over' : '') . '">' . esc_html(implode(' · ', $meta)) . '</span>'
            . '</div>';
        $html .= '<div class="pl-list pl-gac-list">';
        foreach ($g['monitores'] as $p) {
            $html .= sticpa_gac_persona_html($p, $g['name'], true);
        }
        foreach ($g['participantes'] as $p) {
            $html .= sticpa_gac_persona_html($p, $g['name'], false);
        }
        if ($g['n'] === 0) {
            // Vacío: se dice dentro de la lista para que el buscador lo trate
            // como una fila más (y desaparezca al buscar a alguien).
            $html .= '<div class="pl-rowwrap pl-rowstatic pl-gac-vacio"><span class="pl-row-body"><span class="pl-rowsub">'
                . esc_html__('Nadie todavía', 'sticpa') . '</span><span hidden>' . esc_html($g['name']) . '</span></span></div>';
        } elseif (!empty($g['participantes']) || !empty($g['monitores'])) {
            $html .= '<a class="pl-gac-grupo-lista" href="'
                . esc_url(sticpa_pl_ev_url($eventId) . '&por=' . rawurlencode($tabInfo['key']) . '&grupo=' . rawurlencode($g['id'])) . '">'
                . esc_html__('Pasar lista de este grupo', 'sticpa') . '<span hidden>' . esc_html($g['name']) . '</span></a>';
        }
        $html .= '</div>';
    }

    if (!empty($armado['sin'])) {
        $sinLabel = sticpa_gac_sin_label($tabInfo['tipo'], $tabInfo['agrupacion']);
        $html .= '<div class="pl-sec pl-gac-sec" role="heading" aria-level="2">'
            . '<span class="pl-gac-sec-name">' . esc_html($sinLabel) . '</span>'
            . '<span class="pl-gac-sec-n">' . count($armado['sin']) . '</span></div>';
        $html .= '<div class="pl-list pl-gac-list">';
        foreach ($armado['sin'] as $p) {
            $html .= sticpa_gac_persona_html($p, $sinLabel, false);
        }
        $html .= '</div>';
    }

    $html .= sticpa_pl_buscador_vacio_html(__('Nadie coincide con lo que buscas.', 'sticpa'));
    return $html;
}

/**
 * LA LISTA DE MARCAR, POR GRUPOS de una pestaña: en vez de etapa y curso, una
 * sección por grupo (monitores sin inscripción en su cabecera, que no se
 * marcan) y «Sin patrulla» al final. Mismas filas, mismo marcado y mismos
 * atributos que la lista por cursos, así que el filtro de chips y «Están
 * todos» funcionan igual: la clave del chip es el grupo en vez del curso.
 *
 * @param callable $fila persona => HTML de su fila (sticpa_pl_ev_row_html()).
 */
function sticpa_gac_secciones_html($armado, $tabInfo, $fila)
{
    $html = '';
    $bloques = array();
    foreach ($armado['grupos'] as $g) {
        $gente = array();
        $noMarca = array();
        foreach (array_merge($g['monitores'], $g['participantes']) as $p) {
            if (!empty($p['marca'])) {
                $gente[] = $p;
            } elseif (empty($p['status_label'])) {
                // Sin inscripción (un monitor que no se apuntó). Quien canceló
                // ya sale abajo, en «No van».
                $noMarca[] = $p['name'];
            }
        }
        $bloques[] = array('key' => $g['id'], 'name' => $g['name'], 'gente' => $gente, 'noMarca' => $noMarca);
    }
    if (!empty($armado['sin'])) {
        $bloques[] = array('key' => 'sin', 'name' => sticpa_gac_sin_label($tabInfo['tipo'], $tabInfo['agrupacion']),
            'gente' => $armado['sin'], 'noMarca' => array());
    }
    foreach ($bloques as $b) {
        if (empty($b['gente'])) {
            continue;
        }
        $html .= '<div class="pl-evetapa" data-pl-ev-etapa>';
        $html .= '<div class="pl-etapa-title pl-evsec" role="heading" aria-level="2"><span>' . esc_html($b['name']) . '</span>'
            . '<span class="pl-etapa-count" data-pl-ev-n>' . count($b['gente']) . '</span></div>';
        if (!empty($b['noMarca'])) {
            $html .= '<p class="pl-gac-nomarca">' . esc_html(sprintf(
                /* translators: %s: nombres, separados por comas */
                __('Sin inscripción, no se marca: %s', 'sticpa'),
                implode(', ', $b['noMarca'])
            )) . '</p>';
        }
        $html .= '<div class="pl-list" data-pl-ev-list>';
        foreach ($b['gente'] as $p) {
            $p['curso'] = $b['key'];   // la clave del chip: el grupo
            $html .= call_user_func($fila, $p);
        }
        $html .= '</div></div>';
    }
    return $html;
}

/**
 * Los chips de la lista por grupos: uno por grupo con gente que se marca. El
 * de `$elegido` sale apretado (venir de «Pasar lista de este grupo»).
 */
function sticpa_gac_chips_html($armado, $tabInfo, $total, $elegido = '')
{
    $chips = array();
    foreach ($armado['grupos'] as $g) {
        $n = 0;
        foreach (array_merge($g['monitores'], $g['participantes']) as $p) {
            if (!empty($p['marca'])) {
                $n++;
            }
        }
        if ($n > 0) {
            $chips[$g['id']] = array($g['name'], $n);
        }
    }
    if (!empty($armado['sin'])) {
        $chips['sin'] = array(sticpa_gac_sin_label($tabInfo['tipo'], $tabInfo['agrupacion']), count($armado['sin']));
    }
    if (!isset($chips[$elegido])) {
        $elegido = '';
    }
    $html = '<div class="pl-evchips" data-pl-ev-chips role="group" aria-label="' . esc_attr__('Filtrar por grupo', 'sticpa') . '">';
    $html .= '<button type="button" class="pl-evchip" data-pl-ev-curso="" aria-pressed="' . ($elegido === '' ? 'true' : 'false') . '">'
        . esc_html__('Todos', 'sticpa') . '<span class="pl-evchip-n">' . (int) $total . '</span></button>';
    foreach ($chips as $k => $c) {
        $html .= '<button type="button" class="pl-evchip" data-pl-ev-curso="' . esc_attr($k) . '"'
            . ' aria-pressed="' . ((string) $k === $elegido ? 'true' : 'false') . '">'
            . esc_html($c[0]) . '<span class="pl-evchip-n">' . (int) $c[1] . '</span></button>';
    }
    $html .= '</div>';
    return $html;
}
