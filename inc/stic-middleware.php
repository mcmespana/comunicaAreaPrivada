<?php
/**
 * ============================================================================
 *  EL MIDDLEWARE: Pasar Lista lee el CRM por SQL (comunicaMiddleware, fase 1)
 * ============================================================================
 *
 * comunicaMiddleware (Vercel, fra1) lee la base de datos del CRM y aplica ÉL el
 * filtro por delegación y papel (la base no tiene grupos de seguridad). Aquí
 * está el cliente: cada cargador de Pasar Lista (pasos 1a y 1b del plan del
 * middleware) le pide sus filas y, si no contesta, sigue por la API v4.1 como
 * siempre (decisión `plan-b`). Nada se escribe por aquí: las escrituras siguen
 * por la API.
 *
 * QUÉ DEVUELVE. Las filas de la MISMA consulta que hace el cargador, con los
 * nombres de campo de la API. `sticpa_mw_rows()` las convierte a la forma que
 * da `getRecordsModule()` (`->name_value_list->campo->value`, y cada enlace en
 * `campo`, `campo_id` y `campo_link`), así que lo que el cargador hace después
 * (ordenar, marcas, vigencia, cachear) es el código de siempre.
 *
 * LAS TANDAS. Dentro de `sticpa_pl_prime()` la recolecta apunta aquí lo que
 * cada cargador pediría al middleware y sale UNA petición (`/plugin/v1/lote`)
 * con todo; las respuestas esperan en un memo de un solo uso, como el de la API.
 *
 * CONFIGURACIÓN (wp-config.php):
 *
 *     define('STICPA_MIDDLEWARE_URL', 'https://comunica-middleware.vercel.app');
 *     define('STICPA_MIDDLEWARE_SECRET', '…');       // el MW_PLUGIN_SECRET de Vercel
 *     define('STICPA_MIDDLEWARE_MODO', 'admins');    // off | admins | todos
 *     define('STICPA_MIDDLEWARE_ADMINS', 'id1,id2'); // contactos que lo prueban
 *
 * Sin URL o sin secreto no hace nada. El modo también va en la opción
 * `sticpa_mw_modo` (la constante manda). «admins» = quien entra con un usuario
 * de WordPress administrador («Entrar como…») o un contacto de la lista.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Recursos del middleware que sabe pedir el plugin (src/plugin/lote.ts). */
function sticpa_mw_recursos()
{
    return array(
        'groups', 'relations', 'myRels', 'events', 'eventSessions', 'listas',
        'eventRegistrations', 'sessionAttendances', 'attendancesForSessions',
        'ficha', 'familyRels', 'familyContacts', 'avisos', 'registrationAttendances', 'personEvents',
        'monitorFicha', 'seguimientos',
        'evInscripciones', 'evPersonas', 'evRelaciones', 'gacGrupos', 'gacMiembros',
    );
}

/**
 * Los que llevan salud o DNI: van por su propia ruta (`/plugin/v1/sensible`),
 * nunca mezclados en el lote de siempre. El middleware rechaza cada uno por la
 * ruta que no es.
 */
function sticpa_mw_sensibles()
{
    return array('ficha', 'monitorFicha');
}

/** La ruta del middleware por la que va un recurso. */
function sticpa_mw_ruta($recurso)
{
    return in_array((string) $recurso, sticpa_mw_sensibles(), true) ? '/plugin/v1/sensible' : '/plugin/v1/lote';
}

function sticpa_mw_url()
{
    $url = defined('STICPA_MIDDLEWARE_URL') ? (string) STICPA_MIDDLEWARE_URL : '';
    return rtrim((string) apply_filters('sticpa_mw_url', $url), '/');
}

function sticpa_mw_secret()
{
    $secret = defined('STICPA_MIDDLEWARE_SECRET') ? (string) STICPA_MIDDLEWARE_SECRET : '';
    return (string) apply_filters('sticpa_mw_secret', $secret);
}

/** Con un secreto corto el middleware dice que no a todo: ni se intenta. */
function sticpa_mw_configured()
{
    return sticpa_mw_url() !== '' && strlen(sticpa_mw_secret()) >= 32;
}

/** 'off', 'admins' o 'todos'. Por defecto 'admins': encenderlo para todos es una decisión. */
function sticpa_mw_mode()
{
    $mode = defined('STICPA_MIDDLEWARE_MODO')
        ? (string) STICPA_MIDDLEWARE_MODO
        : (function_exists('get_option') ? (string) get_option('sticpa_mw_modo', 'admins') : 'admins');
    return in_array($mode, array('off', 'admins', 'todos'), true) ? $mode : 'admins';
}

/** El estado de esta petición: memo, lo pendiente de la tanda y por dónde ha salido cada lectura. */
function &sticpa_mw_state()
{
    if (!isset($GLOBALS['sticpa_mw']) || !is_array($GLOBALS['sticpa_mw'])) {
        $GLOBALS['sticpa_mw'] = array(
            'memo' => array(), 'pending' => array(), 'via' => array(), 'ultimo' => array(),
            'peticiones' => 0, 'ms' => 0.0, 'forzar' => '',
            // Memos de esta petición (se preguntan en cada TTL): null = sin mirar.
            'caido' => null, 'admin' => null,
            // El lote entero ha dicho que no a ESTA persona (403/422): el resto
            // de la petición va por la API sin volver a intentarlo.
            'rechazado' => false,
        );
    }
    return $GLOBALS['sticpa_mw'];
}

/** La persona de la sesión, o '' (el calentador nocturno, alguien sin entrar). */
function sticpa_mw_persona()
{
    $id = isset($_SESSION['scp_user_id']) ? (string) $_SESSION['scp_user_id'] : '';
    return preg_match('/^[A-Za-z0-9-]{1,36}$/', $id) ? $id : '';
}

/** ¿Lo prueba quien está? Administrador de WordPress o un contacto de STICPA_MIDDLEWARE_ADMINS / `sticpa_mw_admins`. */
function sticpa_mw_is_admin()
{
    $st = &sticpa_mw_state();
    if ($st['admin'] !== null) {
        return $st['admin'];
    }
    $admin = false;
    if (function_exists('current_user_can') && current_user_can('manage_options')) {
        $admin = true;
    } elseif (sticpa_mw_persona() !== '') {
        $lista = defined('STICPA_MIDDLEWARE_ADMINS')
            ? (string) STICPA_MIDDLEWARE_ADMINS
            : (function_exists('get_option') ? (string) get_option('sticpa_mw_admins', '') : '');
        $admin = in_array(sticpa_mw_persona(), array_map('trim', explode(',', $lista)), true);
    }
    return $st['admin'] = $admin;
}

/** Se cayó hace poco: durante un minuto ni se intenta, para no esperar el tope en cada pantalla. */
function sticpa_mw_down()
{
    $st = &sticpa_mw_state();
    if ($st['caido'] === null) {
        $st['caido'] = function_exists('get_transient') && get_transient('sticpa_mw_caido') !== false;
    }
    return $st['caido'];
}

function sticpa_mw_mark_down($motivo)
{
    $st = &sticpa_mw_state();
    $st['caido'] = true;
    error_log('[sticpa] middleware: ' . $motivo . '. Se lee por la API.');
    $ttl = (int) apply_filters('sticpa_mw_pausa', MINUTE_IN_SECONDS);
    if ($ttl > 0 && function_exists('set_transient')) {
        set_transient('sticpa_mw_caido', time(), $ttl);
    }
}

/**
 * ¿Va este recurso por el middleware en esta petición?
 *
 * `$st['forzar']` lo pone el modo comparar ('api' o 'mw'). `?mw=0` deja a un
 * administrador ver la pantalla por la API sin tocar nada.
 */
function sticpa_mw_on($recurso = '')
{
    if (!sticpa_mw_configured() || sticpa_mw_persona() === '') {
        return false;
    }
    // El calentador pone la delegación a mano y no tiene persona de verdad.
    if (function_exists('sticpa_pl_delegation_forced') && sticpa_pl_delegation_forced() !== '') {
        return false;
    }
    $st = &sticpa_mw_state();
    if ($st['forzar'] === 'api' || $st['rechazado']) {
        return false;
    }
    if ($st['forzar'] !== 'mw') {
        $mode = sticpa_mw_mode();
        if ($mode === 'off' || ($mode === 'admins' && !sticpa_mw_is_admin())) {
            return false;
        }
        if (isset($_GET['mw']) && (string) $_GET['mw'] === '0' && sticpa_mw_is_admin()) {
            return false;
        }
    }
    if (sticpa_mw_down()) {
        return false;
    }
    return (bool) apply_filters('sticpa_mw_usar', true, (string) $recurso);
}

/**
 * Las filas de un recurso del middleware con la forma de la API, o null si hay
 * que leerlo por la API (apagado, caído, fuera de alcance…).
 *
 * En la recolecta de una tanda apunta la petición y devuelve un array vacío: el
 * cargador no pide nada a la API y no cachea (sticpa_pl_cache_put no guarda en
 * la recolecta). `sticpa_pl_prime()` manda después el lote.
 */
function sticpa_mw_rows($objSCP, $recurso, $params = array())
{
    $rows = sticpa_mw_fetch($objSCP, $recurso, $params);
    return is_array($rows) ? sticpa_mw_api_rows($rows) : null;
}

/**
 * Lo mismo, pero las filas tal cual las manda el middleware (arrays), para un
 * recurso que no imita una consulta de la API sino varias (`personEvents`).
 */
function sticpa_mw_raw($objSCP, $recurso, $params = array())
{
    return sticpa_mw_fetch($objSCP, $recurso, $params);
}

function sticpa_mw_fetch($objSCP, $recurso, $params)
{
    $st = &sticpa_mw_state();
    $st['ultimo'][$recurso] = false;
    if (!sticpa_mw_on($recurso)) {
        return null;
    }
    $deleg = function_exists('sticpa_pl_delegation') ? (string) sticpa_pl_delegation($objSCP) : '';
    $key = md5($deleg . '|' . $recurso . '|' . json_encode($params));
    $ruta = sticpa_mw_ruta($recurso);

    if (function_exists('sticpa_pl_collecting') && sticpa_pl_collecting()) {
        $st['pending'][$ruta][$deleg][$key] = array('recurso' => $recurso, 'params' => $params);
        return array();
    }

    if (array_key_exists($key, $st['memo'])) {
        $res = $st['memo'][$key];
        unset($st['memo'][$key]);   // de un solo uso: una relectura después de guardar va a la base
    } else {
        $out = sticpa_mw_send($deleg, array($key => array('recurso' => $recurso, 'params' => $params)), $ruta);
        $res = (is_array($out) && isset($out[$key])) ? $out[$key] : null;
    }

    if (!is_array($res) || empty($res['ok']) || !isset($res['rows']) || !is_array($res['rows'])) {
        $st['via'][$recurso] = 'api';
        return null;
    }
    $st['via'][$recurso] = isset($st['via'][$recurso]) && $st['via'][$recurso] === 'api' ? 'api' : 'mw';
    $st['ultimo'][$recurso] = true;
    return $res['rows'];
}

/** Olvida cómo salió la última lectura (quien la lea después de un acierto de caché no hereda la anterior). */
function sticpa_mw_reset_ultimo($recurso)
{
    $st = &sticpa_mw_state();
    $st['ultimo'][$recurso] = false;
}

/** ¿La última lectura de este recurso salió del middleware? Para saltarse los respaldos de la API. */
function sticpa_mw_ultimo($recurso)
{
    $st = &sticpa_mw_state();
    return !empty($st['ultimo'][$recurso]);
}

/**
 * Manda lo apuntado en la recolecta. Devuelve false si el lote ha fallado
 * entero: entonces la tanda se vuelve a recolectar para la API.
 */
function sticpa_mw_flush_pending()
{
    $st = &sticpa_mw_state();
    $pending = $st['pending'];
    $st['pending'] = array();
    $ok = true;
    foreach ($pending as $ruta => $porDeleg) {
        foreach ($porDeleg as $deleg => $peticiones) {
            $out = sticpa_mw_send((string) $deleg, $peticiones, (string) $ruta);
            if (!is_array($out)) {
                $ok = false;
                continue;
            }
            foreach ($out as $key => $res) {
                $st['memo'][$key] = $res;
            }
        }
    }
    return $ok;
}

/** Olvida las respuestas traídas: después de escribir, son una foto vieja (sticpa_pl_flush). */
function sticpa_mw_forget()
{
    $st = &sticpa_mw_state();
    $st['memo'] = array();
}

/** La firma del middleware (src/authz/plugin-signature.ts): HMAC de «ts.MÉTODO.ruta.sha256(cuerpo)». */
function sticpa_mw_signature($secret, $ts, $method, $path, $body)
{
    return hash_hmac('sha256', $ts . '.' . strtoupper($method) . '.' . $path . '.' . hash('sha256', $body), $secret);
}

/**
 * Una petición al lote. `$peticiones`: clave => array('recurso', 'params').
 * Devuelve clave => resultado, o null si no ha contestado bien.
 */
function sticpa_mw_send($deleg, $peticiones, $path = '/plugin/v1/lote')
{
    if (empty($peticiones)) {
        return array();
    }
    $persona = sticpa_mw_persona();
    $body = array('persona' => $persona, 'peticiones' => array());
    if ($deleg !== '') {
        $body['delegacion'] = $deleg;
    }
    foreach ($peticiones as $key => $p) {
        $body['peticiones'][] = array(
            'clave' => (string) $key,
            'recurso' => (string) $p['recurso'],
            // Un array vacío sería `[]` en JSON, y los parámetros son un objeto.
            'params' => empty($p['params']) ? new stdClass() : $p['params'],
        );
    }
    $raw = json_encode($body);
    $ts = (string) time();

    $started = microtime(true);
    $res = sticpa_mw_http(sticpa_mw_url() . $path, $raw, array(
        'Content-Type: application/json',
        'x-mw-ts: ' . $ts,
        'x-mw-sig: ' . sticpa_mw_signature(sticpa_mw_secret(), $ts, 'POST', $path, $raw),
    ));
    $st = &sticpa_mw_state();
    $st['peticiones']++;
    $st['ms'] += (microtime(true) - $started) * 1000;

    $status = (int) $res['status'];
    $json = ($res['body'] !== '') ? json_decode($res['body'], true) : null;
    if ($status === 200 && is_array($json) && isset($json['resultados']) && is_array($json['resultados'])) {
        return $json['resultados'];
    }
    $reason = (is_array($json) && isset($json['error']['reason'])) ? (string) $json['error']['reason'] : '';
    // 403 y 422 son de ESTA persona o de esta petición: no se apaga para los demás.
    if ($status === 403 || $status === 422) {
        $st['rechazado'] = true;
        error_log('[sticpa] middleware: ' . $status . ($reason !== '' ? ' ' . $reason : '') . '. Se lee por la API.');
        return null;
    }
    sticpa_mw_mark_down($status === 0
        ? 'no contesta (' . $res['error'] . ')'
        : 'HTTP ' . $status . ($reason !== '' ? ' ' . $reason : ''));
    return null;
}

/**
 * El POST de verdad. Aparte para que los tests lo cambien
 * (`$GLOBALS['sticpa_mw_http']`) sin red.
 *
 * @return array{status: int, body: string, error: string}
 */
function sticpa_mw_http($url, $raw, $headers)
{
    if (isset($GLOBALS['sticpa_mw_http']) && is_callable($GLOBALS['sticpa_mw_http'])) {
        return call_user_func($GLOBALS['sticpa_mw_http'], $url, $raw, $headers);
    }
    if (!function_exists('curl_init')) {
        return array('status' => 0, 'body' => '', 'error' => 'sin cURL');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $raw,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        // Un tope corto: si el middleware no contesta, la API sigue estando ahí.
        CURLOPT_CONNECTTIMEOUT_MS => (int) apply_filters('sticpa_mw_connect_timeout_ms', 1500),
        CURLOPT_TIMEOUT_MS => (int) apply_filters('sticpa_mw_timeout_ms', 4000),
        CURLOPT_ENCODING => '',
    ));
    $body = curl_exec($ch);
    $out = array(
        'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body' => is_string($body) ? $body : '',
        'error' => is_string($body) ? '' : (string) curl_error($ch),
    );
    curl_close($ch);
    return $out;
}

/**
 * Filas del middleware → filas como las de `getRecordsModule()`.
 *
 * Un valor que es un array (o null) es un ENLACE, y se aplana igual que
 * `SugarRestApiCall::flattenRelationshipFields()`: `campo` (su nombre),
 * `campo_id` y `campo_link` (el registro, `->campo->value`, o null).
 */
function sticpa_mw_api_rows($rows)
{
    $out = array();
    foreach ((array) $rows as $rec) {
        if (!is_array($rec)) {
            continue;
        }
        $out[] = sticpa_mw_api_row($rec);
    }
    return $out;
}

function sticpa_mw_api_row($rec)
{
    $nvl = new stdClass();
    foreach ($rec as $k => $v) {
        $k = (string) $k;
        if (is_array($v) || $v === null) {
            $link = is_array($v) ? $v : null;
            $id = $link && isset($link['id']) ? (string) $link['id'] : '';
            $name = $link && isset($link['name']) ? (string) $link['name'] : '';
            $nvl->$k = (object) array('name' => $k, 'value' => $name);
            $nvl->{$k . '_id'} = (object) array('name' => $k . '_id', 'value' => $id);
            $nvl->{$k . '_link'} = $link ? sticpa_mw_nvl($link) : null;
            continue;
        }
        $nvl->$k = (object) array('name' => $k, 'value' => (string) $v);
    }
    $row = new stdClass();
    $row->id = isset($rec['id']) ? (string) $rec['id'] : '';
    $row->name_value_list = $nvl;
    $row->link_list = array();
    // De la base: los enlaces ya vienen resueltos en su campo plano, y un vacío
    // es un vacío (no hace falta preguntar al CRM por el enlace).
    $row->mw = true;
    return $row;
}

function sticpa_mw_nvl($fields)
{
    $o = new stdClass();
    foreach ($fields as $k => $v) {
        $o->$k = (object) array('name' => (string) $k, 'value' => is_scalar($v) ? (string) $v : '');
    }
    return $o;
}

/**
 * Por dónde ha salido lo que se ve: 'sql' (todo lo pedido, del middleware),
 * 'api' (algo ha vuelto a la API) o '' (no se ha pedido nada: caché, o apagado).
 */
function sticpa_mw_estado()
{
    $st = &sticpa_mw_state();
    if (empty($st['via'])) {
        return '';
    }
    return in_array('api', $st['via'], true) ? 'api' : 'sql';
}

/**
 * EL INDICADOR para administradores (decisión `plan-b`): una etiqueta en la
 * esquina. Discreta si todo ha salido del middleware; clara si algo ha vuelto
 * a la API. Ni nombres ni datos: solo por dónde.
 */
function sticpa_mw_badge_html()
{
    if (!sticpa_mw_configured() || !sticpa_mw_is_admin()) {
        return '';
    }
    $st = &sticpa_mw_state();
    $estado = sticpa_mw_estado();
    $pausa = sticpa_mw_down();
    if ($estado === 'api' || ($estado === '' && $pausa)) {
        $vueltas = array_keys(array_filter($st['via'], function ($v) { return $v === 'api'; }));
        $texto = __('API', 'sticpa') . (!empty($vueltas) ? ' · ' . implode(', ', $vueltas) : '')
            . ($pausa ? ' · ' . __('middleware en pausa', 'sticpa') : '');
        $clase = 'sticpa-mw-badge--api';
    } elseif ($estado === 'sql') {
        $texto = sprintf(
            /* translators: 1: peticiones al middleware, 2: milisegundos */
            __('SQL · %1$d pet. · %2$d ms', 'sticpa'),
            (int) $st['peticiones'],
            (int) round($st['ms'])
        );
        $clase = 'sticpa-mw-badge--sql';
    } else {
        $texto = __('caché', 'sticpa');
        $clase = 'sticpa-mw-badge--cache';
    }
    return '<div class="sticpa-mw-badge ' . $clase . '" role="status" title="'
        . esc_attr__('Por dónde se ha leído esta pantalla (solo lo ven los administradores)', 'sticpa') . '">'
        . esc_html($texto) . '</div>';
}

function sticpa_mw_footer()
{
    if (!sticpa_mw_configured() || !sticpa_mw_is_admin() || sticpa_mw_persona() === '') {
        return;
    }
    echo sticpa_mw_badge_html();
    if (!empty($_GET['mw_comparar'])) {
        $objSCP = class_exists('SugarRestApiCall') ? SugarRestApiCall::getObjSCP() : null;
        if ($objSCP) {
            echo '<div class="stic-container pl-diag">' . sticpa_mw_comparar_html($objSCP) . '</div>';
        }
    }
}
add_action('wp_footer', 'sticpa_mw_footer', 21);

// ---------------------------------------------------------------------------
// Modo comparar (temporal, como «Pruebas BBDD» de Comunica Tools)
// ---------------------------------------------------------------------------

/**
 * La forma de un valor, sin el valor: dígitos → 9, letras → a. Lo que se
 * enseña al comparar, para no sacar en pantalla datos de nadie.
 */
function sticpa_mw_forma($v)
{
    if (is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if ($v === null) {
        return 'null';
    }
    if (is_array($v)) {
        return 'array(' . count($v) . ')';
    }
    $s = (string) $v;
    $s = preg_replace('/[0-9]/', '9', $s);
    $s = preg_replace('/[^\W\d_]/u', 'a', $s);
    return '«' . (function_exists('mb_substr') ? mb_substr($s, 0, 24) : substr($s, 0, 24)) . '»';
}

/** Una lista de filas se indexa por su id (`id` o `rel_id`) para comparar fila con fila. */
function sticpa_mw_indexar($v)
{
    if (!is_array($v) || empty($v) || array_keys($v) !== range(0, count($v) - 1)) {
        return $v;
    }
    $out = array();
    foreach ($v as $i => $fila) {
        $id = is_array($fila) ? (isset($fila['rel_id']) ? $fila['rel_id'] : (isset($fila['id']) ? $fila['id'] : null)) : null;
        $id = is_scalar($id) ? (string) $id : null;
        $out[$id !== null && !isset($out['#' . $id]) ? '#' . $id : $i] = $fila;
    }
    return $out;
}

/** Diferencias entre dos lecturas: rutas con ids y la forma de cada lado. */
function sticpa_mw_diff($a, $b, $path, &$out, $max = 40)
{
    if (count($out) >= $max) {
        return;
    }
    if (is_array($a) && is_array($b)) {
        $a = sticpa_mw_indexar($a);
        $b = sticpa_mw_indexar($b);
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            $p = $path . '/' . $k;
            if (!array_key_exists($k, $b)) {
                $out[] = $p . ': solo por la API';
            } elseif (!array_key_exists($k, $a)) {
                $out[] = $p . ': solo por SQL';
            } else {
                sticpa_mw_diff($a[$k], $b[$k], $p, $out, $max);
            }
            if (count($out) >= $max) {
                return;
            }
        }
        return;
    }
    if ($a !== $b) {
        $out[] = $path . ': API ' . sticpa_mw_forma($a) . ' · SQL ' . sticpa_mw_forma($b);
    }
}

/** Lee lo mismo por los dos lados, sin caché, y enseña lo que no coincide. */
function sticpa_mw_comparar_html($objSCP)
{
    $userId = sticpa_mw_persona();
    $casos = array(
        'groups' => function () use ($objSCP) { return sticpa_pl_groups($objSCP); },
        'relations' => function () use ($objSCP) { return sticpa_pl_all_relationships_raw($objSCP); },
        // Solo los campos: la API trae además sus bloques de enlaces, que el plugin no lee.
        'myRels' => function () use ($objSCP, $userId) {
            $out = array();
            foreach ((array) sticpa_pl_mis_rels($objSCP, $userId) as $r) {
                if (!isset($r->name_value_list->id->value)) {
                    continue;
                }
                $fila = array();
                foreach ((array) $r->name_value_list as $k => $v) {
                    $fila[$k] = isset($v->value) && is_scalar($v->value) ? (string) $v->value : '';
                }
                // El grupo, como lo leen los consumidores: el campo plano y, si no viene, el enlace.
                $g = 'ajmcm_grupos_stic_contacts_relationshipsajmcm_grupos_ida';
                if (!isset($fila[$g]) || $fila[$g] === '') {
                    $fila[$g] = sticpa_pl_link_id($r);
                }
                $out[] = $fila;
            }
            return $out;
        },
        'events' => function () use ($objSCP) { return sticpa_pl_events_raw($objSCP); },
        'listas' => function () use ($objSCP) { return sticpa_pl_listas_index($objSCP); },
    );
    // Lo de los eventos de las etapas: sesiones, inscripciones y asistencias de las últimas.
    $events = sticpa_mw_sin_cache(function () use ($objSCP) { return sticpa_pl_etapa_events($objSCP); }, 'api');
    foreach (array_unique(array_column((array) $events, 'id')) as $evId) {
        $sesiones = sticpa_mw_sin_cache(function () use ($objSCP, $evId) { return sticpa_pl_event_sessions($objSCP, $evId); }, 'api');
        $regMap = sticpa_mw_sin_cache(function () use ($objSCP, $evId) { return sticpa_pl_event_registrations($objSCP, $evId); }, 'api');
        $ultimas = array_slice(sticpa_pl_elapsed_sessions($sesiones), -3);
        $casos['eventSessions ' . $evId] = function () use ($objSCP, $evId) { return sticpa_pl_event_sessions($objSCP, $evId); };
        $casos['eventRegistrations ' . $evId] = function () use ($objSCP, $evId) { return sticpa_pl_event_registrations($objSCP, $evId); };
        if (!empty($ultimas)) {
            $ultima = end($ultimas);
            $casos['sessionAttendances ' . $ultima['id']] = function () use ($objSCP, $ultima, $regMap) {
                return sticpa_pl_session_attendances($objSCP, $ultima['id'], $regMap);
            };
            $casos['attendancesForSessions ' . $evId] = function () use ($objSCP, $ultimas, $regMap) {
                return sticpa_pl_attendances_for_sessions($objSCP, $ultimas, $regMap);
            };
        }
    }

    // La ficha de una persona (paso 1c), si la pantalla es la de una: `?participante=`. Estas cachés se leen sin mirar
    // el TTL, así que se borran antes de cada lado.
    $persona = isset($_GET['participante']) ? sticpa_pl_safe_id($_GET['participante']) : '';
    if ($persona !== '' && sticpa_pl_persona_de_mi_delegacion($objSCP, $persona)) {
        $limpia = function ($que) use ($objSCP, $persona) {
            if (function_exists('delete_transient')) {
                delete_transient(sticpa_pl_cache_key($que, $objSCP, $persona));
            }
        };
        $casos['ficha'] = function () use ($objSCP, $persona, $limpia) {
            $limpia('ficha');
            return sticpa_pl_ficha($objSCP, $persona);
        };
        $casos['familyRels'] = function () use ($objSCP, $persona, $limpia) {
            $limpia('familia2');
            return sticpa_pl_family($objSCP, $persona);
        };
        $casos['avisos'] = function () use ($objSCP, $persona) { return sticpa_pl_avisos($objSCP, $persona); };
        $casos['personEvents'] = function () use ($objSCP, $persona, $limpia) {
            $limpia('evpers');
            return sticpa_pl_ev_de_persona($objSCP, $persona);
        };
        foreach (array_unique(array_column((array) $events, 'id')) as $evId) {
            $regMap = sticpa_mw_sin_cache(function () use ($objSCP, $evId) { return sticpa_pl_event_registrations($objSCP, $evId); }, 'api');
            $regId = array_search($persona, (array) $regMap, true);
            if ($regId !== false) {
                $casos['registrationAttendances ' . $regId] = function () use ($objSCP, $regId) {
                    return sticpa_pl_contact_attendance($objSCP, $regId);
                };
            }
        }
    }

    // La ficha de un monitor (paso 1d), en su pantalla: `?monitor=`. Los seguimientos, con los papeles de quien mira.
    $monitor = isset($_GET['monitor']) ? sticpa_pl_safe_id($_GET['monitor']) : '';
    if ($monitor !== '') {
        $casos['monitorFicha'] = function () use ($objSCP, $monitor) { return sticpa_pl_monitor_ficha($objSCP, $monitor); };
        $segRoles = sticpa_pl_seg_roles(sticpa_pl_coord_scope($objSCP) !== null, sticpa_pl_is_acompanante($objSCP));
        if (!empty($segRoles)) {
            $casos['seguimientos'] = function () use ($objSCP, $monitor, $segRoles) {
                return sticpa_pl_seguimientos($objSCP, $monitor, $segRoles);
            };
        }
    }

    // Un evento de pasar lista (paso 1e), en su pantalla: `?evento=`. Solo si es de los que se ofrecen (de la delegación).
    $evento = isset($_GET['evento']) ? sticpa_pl_safe_id($_GET['evento']) : '';
    if ($evento !== '' && function_exists('sticpa_pl_ev_evento') && sticpa_pl_ev_evento($objSCP, $evento) !== null) {
        $casos['evInscripciones'] = function () use ($objSCP, $evento) { return sticpa_pl_ev_inscripciones($objSCP, $evento); };
        $inscritos = function () use ($objSCP, $evento) {
            return array_values(array_filter(array_column(sticpa_pl_ev_inscripciones($objSCP, $evento), 'contact_id')));
        };
        $casos['evPersonas'] = function () use ($objSCP, $evento, $inscritos) {
            return sticpa_pl_ev_personas($objSCP, $inscritos(), $evento);
        };
        // La lista armada: curso y «es del equipo» salen de las relaciones de cada inscrito (evRelaciones).
        $casos['evRelaciones'] = function () use ($objSCP, $evento, $inscritos) {
            $ins = sticpa_pl_ev_inscripciones($objSCP, $evento);
            $lista = sticpa_pl_ev_lista_completa($objSCP, $ins, sticpa_pl_ev_personas($objSCP, $inscritos(), $evento), $evento);
            return array('activos' => $lista['activos'], 'cursos' => $lista['cursos']);
        };
        $casos['gacGrupos'] = function () use ($objSCP, $evento) { return sticpa_gac_grupos($objSCP, $evento); };
        $casos['gacMiembros'] = function () use ($objSCP, $evento) { return sticpa_gac_miembros($objSCP, $evento); };
    }

    $html = '<details class="pl-hint" open><summary>' . esc_html__('Comparar API y middleware (SQL)', 'sticpa') . '</summary>';
    $html .= '<p>' . esc_html__('La misma lectura por los dos lados, sin caché. Solo ids y la forma de los valores (9 = dígito, a = letra).', 'sticpa') . '</p><ul>';
    foreach ($casos as $nombre => $fn) {
        $t0 = microtime(true);
        $api = sticpa_mw_sin_cache($fn, 'api');
        $t1 = microtime(true);
        $mw = sticpa_mw_sin_cache($fn, 'mw');
        $t2 = microtime(true);
        $recurso = strtok($nombre, ' ');
        $st = &sticpa_mw_state();
        $porMw = !empty($st['ultimo'][$recurso]);
        $diffs = array();
        sticpa_mw_diff(json_decode(json_encode($api), true), json_decode(json_encode($mw), true), '', $diffs);
        $html .= '<li><code>' . esc_html($nombre) . '</code> — API ' . (int) round(($t1 - $t0) * 1000) . ' ms · SQL '
            . (int) round(($t2 - $t1) * 1000) . ' ms — ';
        if (!$porMw) {
            $html .= '<strong>' . esc_html__('el middleware no ha contestado (se ha leído por la API)', 'sticpa') . '</strong>';
        } elseif (empty($diffs)) {
            $html .= esc_html__('iguales', 'sticpa');
        } else {
            $html .= '<strong>' . esc_html(sprintf(__('%d diferencias', 'sticpa'), count($diffs))) . '</strong><br><code>'
                . implode('<br>', array_map('esc_html', $diffs)) . '</code>';
        }
        $html .= '</li>';
    }
    return $html . '</ul></details>';
}

/** Ejecuta una lectura sin caché (ni leerla ni guardarla) y por un lado concreto. */
function sticpa_mw_sin_cache(callable $fn, $lado)
{
    $st = &sticpa_mw_state();
    $antes = $st['forzar'];
    $st['forzar'] = $lado;
    $cero = function () { return 0; };
    add_filter('sticpa_pl_ttl_structure', $cero, 999);
    add_filter('sticpa_pl_ttl_state', $cero, 999);
    try {
        return $fn();
    } finally {
        remove_filter('sticpa_pl_ttl_structure', $cero, 999);
        remove_filter('sticpa_pl_ttl_state', $cero, 999);
        $st['forzar'] = $antes;
    }
}
