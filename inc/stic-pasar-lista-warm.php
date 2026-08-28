<?php
/**
 * PASAR LISTA · CALENTADOR DE CACHÉ
 * ============================================================================
 * Deja la caché de Pasar Lista hecha de madrugada, para que el primero que
 * entre el sábado no pague las 6-8 llamadas al CRM.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * Las pantallas de Pasar Lista son rápidas cuando la caché está caliente y
 * lentas cuando está fría, y quien la calienta es siempre el primero que entra:
 * el monitor que abre la app el sábado a las cuatro y cuarto. Ese pago no tiene
 * por qué ser suyo. El Guardián Nocturno ya pasa por el CRM cada madrugada, así
 * que después de hacer sus recuentos llama aquí y esto rellena la caché.
 *
 * ── Quién llama y cómo se sabe que es él ────────────────────────────────────
 * El Guardián corre en GitHub Actions, o sea desde fuera y sin login. Así que:
 *
 *   · La petición va FIRMADA: HMAC-SHA256 del cuerpo crudo con un secreto
 *     compartido, en la cabecera `X-Comunica-Firma`. Se compara con
 *     `hash_equals()`, que no se puede medir por tiempos.
 *   · Lleva `ts` dentro del cuerpo y se rechaza si se ha ido más de cinco
 *     minutos: sin eso, quien capture una petición válida la puede repetir para
 *     siempre.
 *   · El secreto se define en `wp-config.php` como `STICPA_PL_WARM_SECRET`. NO
 *     se genera solo a propósito: un secreto autogenerado que vive en
 *     `wp_options` no hay forma de leerlo para copiarlo al secreto de GitHub, y
 *     acabaríamos sacándolo por pantalla en algún sitio. Sin la constante, el
 *     endpoint contesta 501 y dice qué falta.
 *
 * ── Qué calienta, y qué no ──────────────────────────────────────────────────
 * Solo la familia `struct`: grupos, relaciones, eventos, sesiones e
 * inscripciones. Son las caras y las que no cambian de un día para otro.
 *
 * La familia `state` (las listas de cada sesión, las asistencias, las ausencias
 * seguidas) NO se calienta: su TTL son cinco minutos porque tiene que reflejar
 * lo que se acaba de guardar, así que calentarla a las dos de la mañana no
 * sirve de nada — a las 2:05 ya está fría.
 *
 * ── El TTL, que es la parte que se olvida ───────────────────────────────────
 * El TTL normal de la estructura son 12 horas. Calentada a las 2:30, la caché
 * caducaría a las 14:30 — ANTES de las sesiones del sábado, que son por la
 * tarde. O sea, el calentado no habría servido para nada. Mientras calienta se
 * sube el TTL a 26 horas con el filtro que ya existe: cubre hasta la pasada de
 * la noche siguiente y algo de margen.
 *
 * ── Nada interdelegacional ──────────────────────────────────────────────────
 * La clave de caché lleva la delegación dentro, así que se calienta UNA VEZ POR
 * DELEGACIÓN y el Guardián dice cuáles. Los cargadores leen la delegación de
 * `$_SESSION`, que aquí no existe: se pone a mano con
 * `sticpa_pl_delegation_forced()`.
 *
 * Documentación: docs/comunica/GUARDIAN-NOCTURNO.md §5
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Espacio de nombres y ruta del endpoint. */
const STICPA_PL_WARM_NS = 'comunica/v1';
const STICPA_PL_WARM_ROUTE = '/pasar-lista/calentar';

/** Cabecera con la firma. */
const STICPA_PL_WARM_HEADER = 'X-Comunica-Firma';

/**
 * Margen de reloj admitido, en segundos. Cinco minutos: los crons de Actions
 * llegan tarde, pero la petición se manda al final de la pasada, no cuando
 * arranca, así que el desfase real es el del reloj del runner.
 */
function sticpa_pl_warm_skew()
{
    return (int) apply_filters('sticpa_pl_warm_skew', 5 * MINUTE_IN_SECONDS);
}

/**
 * TTL de la estructura mientras se calienta. Tiene que llegar viva hasta la
 * pasada de la noche siguiente, o el calentado no sirve para las sesiones de la
 * tarde del sábado.
 */
function sticpa_pl_warm_ttl()
{
    return (int) apply_filters('sticpa_pl_warm_ttl', 26 * HOUR_IN_SECONDS);
}

/** Cuántas delegaciones como mucho en una petición. Un tope, no una regla. */
function sticpa_pl_warm_max_delegations()
{
    return (int) apply_filters('sticpa_pl_warm_max_delegations', 30);
}

/**
 * El secreto compartido, o '' si no está configurado.
 *
 * Se lee de la constante `STICPA_PL_WARM_SECRET` (wp-config.php). Ver la
 * cabecera del archivo para el por qué de no autogenerarlo.
 */
function sticpa_pl_warm_secret()
{
    $secret = defined('STICPA_PL_WARM_SECRET') ? (string) STICPA_PL_WARM_SECRET : '';
    return (string) apply_filters('sticpa_pl_warm_secret', $secret);
}

/**
 * ¿La firma cuadra con el cuerpo?
 *
 * @param string $body   cuerpo CRUDO, tal cual llegó (reconstruirlo desde el
 *                       array decodificado cambiaría el orden de las claves y
 *                       la firma no cuadraría nunca).
 * @param string $given  el valor de la cabecera, con o sin el prefijo `sha256=`.
 */
function sticpa_pl_warm_signature_ok($body, $given)
{
    $secret = sticpa_pl_warm_secret();
    if ($secret === '') {
        return false;
    }
    $given = trim((string) $given);
    if (strpos($given, 'sha256=') === 0) {
        $given = substr($given, 7);
    }
    if ($given === '') {
        return false;
    }
    $expected = hash_hmac('sha256', (string) $body, $secret);
    return hash_equals($expected, strtolower($given));
}

/** ¿El sello de tiempo está dentro del margen? */
function sticpa_pl_warm_fresh($ts, $now = null)
{
    $ts = (int) $ts;
    if ($ts <= 0) {
        return false;
    }
    $now = ($now === null) ? sticpa_pl_now() : (int) $now;
    return abs($now - $ts) <= sticpa_pl_warm_skew();
}

/**
 * Calienta la caché de UNA delegación. Devuelve qué se ha metido dentro.
 *
 * El orden importa: primero se SUBE la generación (que es como se invalida todo
 * lo cacheado de esa delegación) y luego se rellena. Al revés, lo que acabamos
 * de pedir al CRM quedaría guardado bajo la generación vieja y la subida lo
 * tiraría al momento.
 */
function sticpa_pl_warm_delegation($objSCP, $deleg)
{
    $deleg = sticpa_pl_safe_id($deleg);
    if ($deleg === '') {
        return array('delegacion' => '', 'error' => 'delegación vacía');
    }

    $t0 = microtime(true);
    $previo = sticpa_pl_delegation_forced();
    sticpa_pl_delegation_forced($deleg);

    // El TTL largo, solo mientras calentamos. Se quita al terminar para que una
    // página normal siga escribiendo con el TTL de siempre.
    $ttl = static function () { return sticpa_pl_warm_ttl(); };
    add_filter('sticpa_pl_ttl_structure', $ttl, 99);

    $out = array('delegacion' => $deleg);
    try {
        // Fuera lo viejo, y de las dos familias: el Guardián acaba de escribir
        // recuentos y monitores en los grupos, así que lo cacheado de antes ya
        // no vale.
        sticpa_pl_flush($objSCP, 'all');

        $groups = sticpa_pl_groups($objSCP);
        $out['grupos'] = count($groups);

        // Las relaciones de toda la delegación en una llamada: de aquí salen las
        // personas de cada grupo y los monitores, o sea lo que más se mira.
        $rels = sticpa_pl_all_relationships($objSCP);
        $out['relaciones'] = is_array($rels) ? count($rels) : 0;

        $events = sticpa_pl_etapa_events($objSCP);
        // Un evento multi-etapa aparece bajo cada etapa: se deduplica por id
        // para no pedir dos veces sus sesiones.
        $eventIds = array();
        foreach ($events as $ev) {
            if (!empty($ev['id'])) {
                $eventIds[$ev['id']] = true;
            }
        }
        $out['eventos'] = count($eventIds);

        $sesiones = 0;
        $inscripciones = 0;
        foreach (array_keys($eventIds) as $eventId) {
            $ses = sticpa_pl_event_sessions($objSCP, $eventId);
            $sesiones += is_array($ses) ? count($ses) : 0;
            $regs = sticpa_pl_event_registrations($objSCP, $eventId);
            $inscripciones += is_array($regs) ? count($regs) : 0;
        }
        $out['sesiones'] = $sesiones;
        $out['inscripciones'] = $inscripciones;

        // El evento de reuniones: `false` = mirar y no crearlo. Crear registros
        // en el CRM desde un calentador de caché sería una sorpresa desagradable.
        $reu = sticpa_pl_reuniones_event($objSCP, false);
        $out['reuniones'] = ($reu === null) ? 0 : 1;
        // Y sus sesiones e inscripciones, que la ficha de un monitor necesita
        // para la fila de reuniones. Son estructura —cambian tres veces al
        // año—, así que calentarlas de madrugada las deja hechas todo el día.
        if ($reu !== null && !empty($reu['id']) && !isset($eventIds[$reu['id']])) {
            $ses = sticpa_pl_event_sessions($objSCP, $reu['id']);
            $out['sesiones'] += is_array($ses) ? count($ses) : 0;
            $regs = sticpa_pl_event_registrations($objSCP, $reu['id']);
            $out['inscripciones'] += is_array($regs) ? count($regs) : 0;
        }
    } catch (Exception $e) {
        // Un fallo calentando NO puede tumbar la respuesta: lo que se calienta es
        // opcional por definición, y la pantalla sabe pedir sus datos sola.
        $out['error'] = $e->getMessage();
    }

    remove_filter('sticpa_pl_ttl_structure', $ttl, 99);
    sticpa_pl_delegation_forced($previo);

    $out['ms'] = (int) round((microtime(true) - $t0) * 1000);
    return $out;
}

/**
 * El endpoint. Ojo con el orden de las comprobaciones: primero si está
 * configurado (501), luego la firma (403) y solo después se mira el contenido.
 * Al revés se estaría contestando cosas sobre el cuerpo a quien no ha firmado.
 */
function sticpa_pl_warm_handle_request($request)
{
    if (sticpa_pl_warm_secret() === '') {
        return new WP_Error(
            'sticpa_pl_warm_no_secret',
            'El calentador no está configurado: define STICPA_PL_WARM_SECRET en wp-config.php.',
            array('status' => 501)
        );
    }

    $body = $request->get_body();
    $firma = $request->get_header(STICPA_PL_WARM_HEADER);
    if (!sticpa_pl_warm_signature_ok($body, $firma)) {
        return new WP_Error('sticpa_pl_warm_bad_signature', 'Firma no válida.', array('status' => 403));
    }

    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        return new WP_Error('sticpa_pl_warm_bad_body', 'Cuerpo no válido.', array('status' => 400));
    }
    if (!sticpa_pl_warm_fresh(isset($data['ts']) ? $data['ts'] : 0)) {
        return new WP_Error(
            'sticpa_pl_warm_stale',
            'Petición caducada o con el reloj muy desviado.',
            array('status' => 403)
        );
    }

    $delegs = isset($data['delegaciones']) && is_array($data['delegaciones'])
        ? $data['delegaciones']
        : array();
    // Únicas y sin vacíos: el Guardián las saca de los grupos, así que llegan
    // repetidas por definición (105 grupos, 1 delegación).
    $delegs = array_values(array_unique(array_filter(array_map('sticpa_pl_safe_id', $delegs))));
    if (empty($delegs)) {
        return new WP_Error('sticpa_pl_warm_no_delegs', 'No se ha pedido ninguna delegación.', array('status' => 400));
    }
    $max = sticpa_pl_warm_max_delegations();
    $sobran = max(0, count($delegs) - $max);
    if ($sobran > 0) {
        $delegs = array_slice($delegs, 0, $max);
    }

    $objSCP = SugarRestApiCall::getObjSCP();

    $resultado = array();
    foreach ($delegs as $deleg) {
        $resultado[] = sticpa_pl_warm_delegation($objSCP, $deleg);
    }

    return rest_ensure_response(array(
        'ok' => true,
        // Se dice en claro si se ha recortado: un tope silencioso se lee como
        // "he calentado todo" habiendo calentado la mitad.
        'omitidas' => $sobran,
        'delegaciones' => $resultado,
    ));
}

/** Registro de la ruta. `permission_callback` abierto: manda la firma HMAC. */
function sticpa_pl_warm_register_route()
{
    register_rest_route(STICPA_PL_WARM_NS, STICPA_PL_WARM_ROUTE, array(
        'methods' => 'POST',
        'callback' => 'sticpa_pl_warm_handle_request',
        // La autorización es la firma del cuerpo, que se comprueba dentro. Un
        // `permission_callback` no puede hacerlo: necesita el cuerpo crudo.
        'permission_callback' => '__return_true',
    ));
}
add_action('rest_api_init', 'sticpa_pl_warm_register_route');
