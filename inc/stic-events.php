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
        //
        // EL LUGAR va en dos campos de texto NUESTROS y no en el módulo de
        // ubicaciones del CRM (`stic_events_fp_event_locations`), que existe:
        // para un puñado de eventos por delegación y curso, mantener un
        // catálogo de sitios es más trabajo del que ahorra. El precio de
        // decidirlo así es que «Casa de Espiritualidad» se acabará escribiendo
        // de cinco maneras, como ya pasa con `cursos_c`; se asume porque este
        // dato se LEE, y no se filtra ni se agrupa por él.
        //
        // Y son DOS y no uno porque hacen dos cosas distintas: el nombre corto
        // es lo que cabe en la tarjeta del listado, y la dirección completa es
        // lo que hace falta para llegar (y lo que se le manda al mapa).
        'ajmcm_lugar_c'     => array('label' => __('Lugar', 'sticpa'),        'icon' => 'building', 'format' => 'text'),
        'ajmcm_direccion_c' => array('label' => __('Dirección', 'sticpa'),    'icon' => 'pin',    'format' => 'text'),
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
 * Campo con el ENLACE AL MAPA. Va aparte de los opcionales porque una URL
 * cruda no es un dato que se le enseñe a nadie: se convierte en el botón del
 * dato «Lugar». Ver sticpa_event_map_url().
 */
function sticpa_event_map_field()
{
    return (string) apply_filters('sticpa_event_map_field', 'ajmcm_mapa_c');
}

/**
 * A dónde lleva el botón del mapa, y de dónde sale.
 *
 * LA IDEA IMPORTANTE: el botón NO necesita que exista el campo del enlace.
 * Con el nombre del sitio ya se puede armar una búsqueda de Google Maps, así
 * que el botón funciona desde el primer día con un solo campo de texto
 * relleno. El campo `ajmcm_mapa_c` es el ARREGLO para cuando la búsqueda no
 * acierta —«Casa de Espiritualidad» hay varias— o cuando alguien quiere pegar
 * el enlace exacto que ya tiene. Si está, manda él.
 *
 * Orden: enlace explícito → búsqueda de la dirección → búsqueda del lugar.
 * De más preciso a menos.
 *
 * @param string $explicito Valor de `ajmcm_mapa_c`.
 * @param string $direccion Dirección completa.
 * @param string $lugar     Nombre del sitio.
 * @return string URL segura (http/https) o cadena vacía.
 */
function sticpa_event_map_url($explicito = '', $direccion = '', $lugar = '')
{
    $explicito = trim((string) $explicito);
    if ($explicito !== '') {
        // Se valida ANTES de pintar: este valor lo escribe una persona en el
        // CRM y podría ser un `javascript:…`. Ver sticpa_record_safe_url().
        $url = function_exists('sticpa_record_safe_url')
            ? sticpa_record_safe_url($explicito) : '';
        if ($url !== '') {
            return $url;
        }
        // Un enlace que no vale se ignora y se cae a la búsqueda: mejor un
        // mapa aproximado que ningún botón.
    }
    $consulta = trim((string) $direccion) !== '' ? trim((string) $direccion) : trim((string) $lugar);
    if ($consulta === '') {
        return '';
    }
    $base = (string) apply_filters('sticpa_event_map_search_url', 'https://www.google.com/maps/search/?api=1&query=');
    return $base . rawurlencode($consulta);
}

/**
 * Campo con el ENLACE DEL FORMULARIO WEB AVANZADO (TODO EV-3, campo creado el
 * 30/09/2026). Vacío = el evento se apunta con el alta corta del área.
 */
function sticpa_event_fwa_field()
{
    return (string) apply_filters('sticpa_event_fwa_field', 'ajmcm_fwa_url_c');
}

/**
 * El enlace del FWA de un evento, listo para usar, o '' si no tiene.
 *
 * El CRM lo devuelve ESCAPADO (`…renderForm&amp;id=…`): sin deshacerlo, el
 * formulario recibe un parámetro `amp;id` y no sabe qué formulario pintar.
 * Y se valida como cualquier URL que escribe una persona en el CRM.
 */
function sticpa_event_fwa_url($raw)
{
    $url = trim(html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return function_exists('sticpa_record_safe_url') ? sticpa_record_safe_url($url) : '';
}

/**
 * LOS DATOS QUE EL ÁREA LE PASA AL FWA para que salga ya relleno.
 *
 * El FWA de SinergiaCRM rellena solo cualquier campo cuyo NOMBRE llegue en la
 * URL (`FormRenderService::prefillFieldsFromRequest()` en el servidor y
 * `prefillFromUrl()` en el navegador): `?first_name=Ana` rellena el campo
 * `first_name` de cualquier bloque. Lo que el formulario no tiene, lo ignora.
 *
 * Son los del CRM tal cual, y van los que identifican a la persona: con su
 * correo y su DNI exactos, el FWA la encuentra en el CRM en vez de crear un
 * contacto repetido. La fecha de nacimiento no va: su formato en el FWA no
 * está comprobado, y una fecha mal leída es peor que una vacía.
 */
function sticpa_event_fwa_prefill_fields()
{
    return (array) apply_filters('sticpa_event_fwa_prefill_fields', array(
        'first_name', 'last_name', 'email1', 'phone_mobile',
        'stic_identification_number_c', 'stic_identification_supp_c',
    ));
}

/**
 * El enlace del FWA con los datos de quien ha entrado.
 *
 * Lo que ya trae el enlace (`entryPoint`, `id`) no se toca: un campo del
 * contacto con ese nombre no puede cambiar de formulario.
 *
 * @param string $fwaUrl   sticpa_event_fwa_url().
 * @param array  $contacto campo => valor.
 */
function sticpa_event_fwa_prefilled_url($fwaUrl, $contacto)
{
    $fwaUrl = (string) $fwaUrl;
    $fragmento = '';
    if (($pos = strpos($fwaUrl, '#')) !== false) {
        $fragmento = substr($fwaUrl, $pos);
        $fwaUrl = substr($fwaUrl, 0, $pos);
    }
    $ya = array();
    parse_str((string) parse_url($fwaUrl, PHP_URL_QUERY), $ya);

    $params = array();
    foreach (sticpa_event_fwa_prefill_fields() as $campo) {
        $valor = trim((string) ($contacto[$campo] ?? ''));
        if ($valor !== '' && !array_key_exists($campo, $ya)) {
            $params[$campo] = $valor;
        }
    }
    if (empty($params)) {
        return $fwaUrl . $fragmento;
    }
    return $fwaUrl . (strpos($fwaUrl, '?') === false ? '?' : '&')
        . http_build_query($params, '', '&', PHP_QUERY_RFC3986) . $fragmento;
}

/**
 * LA PUERTA DEL ÁREA AL FWA. «Inscribirme» en un evento con FWA no apunta al
 * formulario directamente sino aquí: el handler mira la sesión, que de verdad
 * puedas apuntarte, y lee tus datos para mandarte al formulario ya relleno.
 * Leer el contacto en cada tarjeta del listado sería una llamada al CRM por
 * pintar; así solo se lee cuando alguien pulsa.
 */
function sticpa_event_fwa_door_url($eventId)
{
    $base = function_exists('admin_url') ? admin_url('admin-post.php') : '/wp-admin/admin-post.php';
    return $base . '?action=sticpa_evento_fwa&e=' . rawurlencode((string) $eventId);
}

/** A dónde lleva «Inscribirme»: el FWA (por la puerta) si lo tiene, si no el alta corta. */
function sticpa_event_signup_url($event)
{
    if (($event['fwa_url'] ?? '') !== '') {
        return sticpa_event_fwa_door_url($event['id']);
    }
    return '?internalpage=single_stic_registrations&action=create&from=stic_events&id=' . rawurlencode($event['id']);
}

add_action('admin_post_sticpa_evento_fwa', 'sticpa_event_fwa_endpoint');
add_action('admin_post_nopriv_sticpa_evento_fwa', 'sticpa_event_fwa_endpoint');

/**
 * Manda al FWA del evento, relleno con los datos de quien ha entrado.
 *
 * Si no toca —ya estás inscrito, la actividad no es para ti, el plazo está
 * cerrado o el evento no tiene FWA— vuelve al alta del área, que es la
 * pantalla que ya sabe explicar cada caso. El FWA es público y no lo protege
 * esto; lo que se evita es que el área ofrezca lo que no debe.
 */
function sticpa_event_fwa_endpoint()
{
    sticpa_require_session();

    $eventId = isset($_GET['e']) ? (string) $_GET['e'] : '';
    if (!preg_match('/^[a-f0-9-]{10,64}$/i', $eventId)) {
        wp_safe_redirect(sticpa_return_path() . '?internalpage=list_stic_events');
        exit;
    }
    $alta = sticpa_return_path() . '?internalpage=single_stic_registrations&action=create&from=stic_events&id=' . rawurlencode($eventId);

    $objSCP = SugarRestApiCall::getObjSCP();
    $ev = $objSCP->getRecordDetail($eventId, 'stic_Events', sticpa_event_fields_to_request($objSCP));
    $nvl = $ev->entry_list[0]->name_value_list ?? null;
    $campo = sticpa_event_fwa_field();
    $fwa = $nvl ? sticpa_event_fwa_url($nvl->$campo->value ?? '') : '';

    $noToca = $fwa === ''
        // Fresco, sin caché: quien acaba de apuntarse por el FWA y vuelve a
        // pulsar tiene que ver «ya estás inscrito», no el formulario otra vez.
        || (function_exists('prefix_user_active_event_ids') && in_array($eventId, prefix_user_active_event_ids($objSCP, true), true))
        || (function_exists('sticpa_event_signup_block') && !empty(sticpa_event_signup_block($objSCP, $eventId, $nvl)['bloqueado']));
    if ($noToca) {
        wp_safe_redirect($alta);
        exit;
    }

    $destino = sticpa_event_fwa_prefilled_url($fwa, sticpa_event_fwa_contact($objSCP));
    // El FWA vive en otro host (el del CRM): se permite SOLO para esta
    // redirección, y solo el del enlace que viene del propio evento.
    $host = (string) parse_url($destino, PHP_URL_HOST);
    add_filter('allowed_redirect_hosts', function ($hosts) use ($host) {
        $hosts[] = $host;
        return $hosts;
    });
    wp_safe_redirect($destino);
    exit;
}

/**
 * Los datos de quien ha entrado que se le pasan al FWA. Un fallo al leerlos no
 * impide apuntarse: el formulario sale vacío, como si se abriera a mano.
 */
function sticpa_event_fwa_contact($objSCP)
{
    $id = (string) ($_SESSION['scp_user_id'] ?? '');
    if ($id === '') {
        return array();
    }
    $campos = sticpa_event_fwa_prefill_fields();
    $res = $objSCP->getRecordDetail($id, 'Contacts', $campos);
    $nvl = $res->entry_list[0]->name_value_list ?? null;
    $out = array();
    foreach ($campos as $campo) {
        if (isset($nvl->$campo->value)) {
            $out[$campo] = html_entity_decode((string) $nvl->$campo->value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    return $out;
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
function sticpa_event_fields_to_request($objSCP, $conWeb = false)
{
    $base = sticpa_event_base_fields();
    $wanted = sticpa_event_wanted_fields();
    // Los de la web (el cuerpo largo, el cartel…) solo los pide la FICHA: el
    // listado no los pinta y el cuerpo de diez eventos es mucho texto para
    // tirarlo. Se miran igualmente contra los campos que existen.
    if ($conWeb && function_exists('sticpa_event_web_fields')) {
        $wanted = array_merge($wanted, sticpa_event_web_fields());
    }
    if (empty($wanted) || !function_exists('sticpa_cached_field_definition')) {
        return $base;
    }
    $definition = sticpa_event_field_definition($objSCP);
    $existing = is_array($definition) ? array_keys($definition) : array();

    // LA FICHA SIN CUERPO (TODO EV-1, 25/09/2026). La definición va cacheada
    // 6 h, y un campo creado en Studio DESPUÉS de cachearla no existe para el
    // área hasta que caduque: la ficha no lo pide y sale sin el cuerpo de la
    // web, sin error. Si a la ficha le falta el cuerpo, se vuelve a preguntar
    // al CRM, como mucho una vez cada 15 minutos (si el campo no existe de
    // verdad, eso es lo único que cuesta).
    $cuerpo = function_exists('mcm_cuerpo_campo') ? mcm_cuerpo_campo() : '';
    if ($conWeb && $cuerpo !== '' && !in_array($cuerpo, $existing, true)
        && function_exists('get_transient') && get_transient('sticpa_evdef_recheck') === false) {
        set_transient('sticpa_evdef_recheck', 1, 15 * MINUTE_IN_SECONDS);
        $definition = sticpa_event_field_definition($objSCP, true);
        $existing = is_array($definition) ? array_keys($definition) : array();
    }
    return array_values(array_unique(array_merge($base, array_intersect($wanted, $existing))));
}

/**
 * Los campos MÍNIMOS para poder juzgar la audiencia de un evento.
 *
 * Existe para el calendario y para el widget de la home, que no pintan la
 * ficha de un evento (no necesitan `description`, ni el lugar, ni el mapa) pero
 * SÍ tienen que decidir si la actividad es de quien mira. Pedir
 * `sticpa_event_fields_to_request()` allí sería traerse ~20 columnas de las que
 * se usan cinco.
 *
 * `assigned_user_id` va siempre: es la delegación dueña del evento, o sea el
 * eje del ámbito. Los campos de audiencia se cruzan con los que EXISTEN de
 * verdad (misma definición cacheada que usa `sticpa_event_fields_to_request()`,
 * así que no cuesta una llamada más), porque pedir una columna inexistente a
 * `get_entry_list` da error en algunas versiones en vez de ignorarla.
 *
 * @param object $objSCP Cliente del CRM.
 * @param array  $base   Campos que además necesita quien llama para pintar.
 */
function sticpa_event_audience_fields_to_request($objSCP, $base = array('id', 'name'))
{
    $base = array_merge((array) $base, array('assigned_user_id'));
    if (!function_exists('sticpa_event_audience_fields')) {
        return array_values(array_unique($base));
    }
    $definition = sticpa_event_field_definition($objSCP);
    $existing = is_array($definition) ? array_keys($definition) : array();
    return array_values(array_unique(array_merge(
        $base,
        array_intersect(sticpa_event_audience_fields(), $existing)
    )));
}

/**
 * Los campos BÁSICOS de un evento: los que se piden siempre porque siempre
 * están.
 *
 * `assigned_user_id` es BASE y no opcional: es la delegación del evento, y de
 * ella depende quién puede apuntarse (inc/stic-event-audience.php).
 */
function sticpa_event_base_fields()
{
    return array('id', 'name', 'status', 'type', 'start_date', 'end_date', 'description', 'assigned_user_id');
}

/**
 * Los campos que QUEREMOS si existen. Los de audiencia se piden igual que los
 * opcionales —solo si existen— para que el filtro se pueda desplegar antes de
 * crearlos en el CRM: un campo que no está no restringe nada y no rompe la
 * llamada.
 */
function sticpa_event_wanted_fields()
{
    $wanted = array_keys(sticpa_event_optional_fields());
    if (function_exists('sticpa_event_audience_fields')) {
        $wanted = array_merge($wanted, sticpa_event_audience_fields());
    }
    $wanted = array_merge($wanted, sticpa_event_registration_fields());
    $wanted[] = sticpa_event_map_field();
    $wanted[] = sticpa_event_fwa_field();
    // Las preguntas simples (EV-6): textos cortos que el formulario de
    // inscripción convierte en opciones. Como todo lo de aquí, solo si existen.
    if (function_exists('sticpa_event_question_event_fields')) {
        $wanted = array_merge($wanted, sticpa_event_question_event_fields());
    }
    return array_values(array_unique($wanted));
}

/**
 * LA DEFINICIÓN DE `stic_Events`, UNA SOLA VEZ.
 *
 * Existe por un viaje al CRM que se estaba pagando de más y que no se veía:
 * `sticpa_event_fields_to_request()` pedía la definición de los ~20 campos y el
 * listado de Eventos pedía DESPUÉS la de `status` a secas, para traducir el
 * desplegable. Son dos listas distintas, o sea dos claves de caché distintas, o
 * sea **dos llamadas** — y la primera ya traía `status`, que es un campo base.
 *
 * Con una sola lista hay una sola clave y una sola llamada. Quien necesite la
 * definición de un campo de eventos la pide AQUÍ; pedirla por su cuenta con
 * otra lista vuelve a partir la caché en dos.
 *
 * (Lo cazó `tests/CosteLlamadasAreaTest`, que cuenta las llamadas de las
 * pantallas de todo el mundo y avisa cuando una consulta se repite.)
 */
function sticpa_event_field_definition($objSCP, $fresh = false)
{
    if (!function_exists('sticpa_cached_field_definition')) {
        return array();
    }
    $fields = array_merge(sticpa_event_base_fields(), sticpa_event_wanted_fields());
    // Los de la web entran en la MISMA definición (una sola clave de caché),
    // aunque solo la ficha los pida.
    if (function_exists('sticpa_event_web_fields')) {
        $fields = array_merge($fields, sticpa_event_web_fields());
    }
    return sticpa_cached_field_definition($objSCP, 'stic_Events', $fields, $fresh);
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
        // A dónde lleva el botón del mapa (o '' si no hay nada que enseñar).
        'mapa_url' => sticpa_event_map_url(
            $val(sticpa_event_map_field()),
            $val('ajmcm_direccion_c'),
            $val('ajmcm_lugar_c')
        ),
        // El formulario web avanzado del evento, si tiene: entonces
        // «Inscribirme» lleva allí y no al alta corta del área.
        'fwa_url' => sticpa_event_fwa_url($val(sticpa_event_fwa_field())),
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
 * La etiqueta del estado de un evento en el chip. El CRM llama «Inscripciones»
 * a `registration`, que en un chip suelto no se entiende (¿hay inscripciones?
 * ¿me han inscrito?): lo que dice es que están ABIERTAS (08/10/2026). El resto
 * de estados conserva la etiqueta del CRM.
 */
function sticpa_event_status_label($key, $crmLabel)
{
    return strtolower(trim((string) $key)) === 'registration'
        ? __('Inscripciones abiertas', 'sticpa')
        : (string) $crmLabel;
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

function sticpa_event_date_line($startTs, $endTs)
{
    return sticpa_record_date_line($startTs, $endTs);
}

/**
 * LISTADO DE EVENTOS como tarjetas, en dos bloques (30/09/2026):
 *
 *   · «Te has apuntado»: lo que ya tienes inscrito y aún no ha pasado. Antes
 *     esto DESAPARECÍA de Eventos al inscribirte, y quien volvía a mirar si se
 *     había apuntado no lo encontraba («ay, mecachis, aquí no está»). Cada
 *     tarjeta lleva a su inscripción.
 *   · «Para apuntarte»: el resto de lo que viene.
 *
 * Lo ya celebrado NO sale: Eventos es lo que viene. Tu historial, con todo lo
 * antiguo, está en «Inscripciones».
 *
 * @param array $events    Registros del CRM (objetos con ->name_value_list).
 * @param array $statusMap Mapa valor→etiqueta del enum `status` (del CRM).
 * @param array $mine      Tus inscripciones activas, evento => inscripción
 *                         (prefix_user_active_registration_map()).
 */
function sticpa_events_list_html($events, $statusMap = array(), $mine = array())
{
    $mias = array();
    $resto = array();
    foreach ((array) $events as $row) {
        $nvl = $row->name_value_list ?? null;
        if (!$nvl) {
            continue;
        }
        $model = sticpa_event_view_model($nvl);
        if (!$model || $model['is_past']) {
            continue;
        }
        if (isset($mine[$model['id']])) {
            $model['registration_id'] = (string) $mine[$model['id']];
            $mias[] = $model;
        } else {
            $resto[] = $model;
        }
    }

    // Lo que antes ocurre, arriba.
    $porFecha = function ($a, $b) {
        return ($a['start_ts'] ?? PHP_INT_MAX) <=> ($b['start_ts'] ?? PHP_INT_MAX);
    };
    usort($mias, $porFecha);
    usort($resto, $porFecha);

    if (empty($mias) && empty($resto)) {
        return sticpa_record_empty_html(
            'calendar',
            __('No hay actividades próximas ahora mismo', 'sticpa'),
            __('Cuando se abra la inscripción de una actividad, aparecerá aquí. Las que ya pasaron están en “Inscripciones”.', 'sticpa'),
            array('label' => __('Ver mis inscripciones', 'sticpa'), 'url' => '?internalpage=list_stic_registrations')
        );
    }

    // «Para apuntarte» va PRIMERO (08/10/2026): es a lo que se viene a esta
    // pantalla. Lo que ya tienes, debajo. Los títulos solo hacen falta si hay
    // dos bloques que distinguir.
    $html = '';
    if (!empty($resto)) {
        if (!empty($mias)) {
            $html .= "<h4 class='stic-rec-group-title'>" . esc_html__('Para apuntarte', 'sticpa') . "</h4>";
        }
        $html .= sticpa_record_list_html(sticpa_events_cards($resto, $statusMap));
    }
    if (!empty($mias)) {
        $html .= "<h4 class='stic-rec-group-title'>" . esc_html__('Te has apuntado', 'sticpa') . "</h4>";
        $html .= sticpa_record_list_html(sticpa_events_cards($mias, $statusMap));
    }
    return $html;
}

/** Las tarjetas de una lista de eventos (modelos de sticpa_event_view_model()). */
function sticpa_events_cards($models, $statusMap = array())
{
    $cards = array();
    foreach ($models as $event) {
        $detailUrl = '?internalpage=single_stic_events&action=detail&id=' . rawurlencode($event['id']);
        $signUpUrl = sticpa_event_signup_url($event);

        $lines = array();
        $dateLine = sticpa_record_date_line($event['start_ts'], $event['end_ts']);
        if ($dateLine !== '') {
            $lines[] = array('icon' => 'calendar', 'text' => $dateLine);
        }
        // Una sola línea extra (el lugar): en la tarjeta manda el "cuándo";
        // el resto se ve en la ficha.
        $place = $event['optional']['ajmcm_lugar_c']['text'] ?? ($event['optional']['ajmcm_direccion_c']['text'] ?? '');
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
        //
        // A lo que ya te has apuntado no se le cuenta el plazo: ya no te toca.
        $regId = (string) ($event['registration_id'] ?? '');
        $regFact = sticpa_event_registration_fact($event['registro']);
        if ($regId === '' && !$event['is_past'] && $regFact !== null && ($event['registro']['estado'] ?? '') !== 'cerrada') {
            $lines[] = array('icon' => $regFact['icon'], 'text' => $regFact['text']);
        }

        $chips = array();
        if ($regId !== '') {
            $chips[] = array('label' => __('Inscrito', 'sticpa'), 'tone' => 'ok');
        } elseif ($event['is_past']) {
            $chips[] = array('label' => __('Ya celebrado', 'sticpa'), 'tone' => 'past');
        } elseif ($regChip !== null) {
            // Las fechas mandan sobre el `status` del CRM. Ver la nota de
            // sticpa_event_registration_chip().
            $chips[] = $regChip;
        } elseif (!empty($statusMap[$event['status']])) {
            $chips[] = array('label' => sticpa_event_status_label($event['status'], $statusMap[$event['status']]), 'tone' => '');
        }

        $actions = array(array('label' => __('Ver detalle', 'sticpa'), 'url' => $detailUrl));
        if ($regId !== '') {
            // Lo tuyo: a TU inscripción (estado, pago, cambiar o cancelar).
            $actions[] = array('label' => __('Mi inscripción', 'sticpa'), 'primary' => true,
                'url' => '?internalpage=single_stic_registrations&action=detail&id=' . rawurlencode($regId));
        } elseif (!$event['is_past'] && !empty($event['registro']['abierta'])) {
            // Fuera del plazo no se ofrece «Inscribirme»: el botón que lleva a
            // un formulario que va a rechazarte es peor que no tener botón.
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

    return $cards;
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
 * @param array|null $web   Lo de la web del evento (sticpa_event_web_view()):
 *                          cartel, lema, cuerpo, documentos. Ver
 *                          inc/stic-event-web.php.
 */
function sticpa_event_detail_html($event, $statusLabel = '', $canSignUp = true, $blockNote = '', $web = null)
{
    $dateLine = sticpa_record_date_line($event['start_ts'], $event['end_ts']);
    $signUpUrl = sticpa_event_signup_url($event);

    $regChip = $event['is_past'] ? null : sticpa_event_registration_chip($event['registro']);

    $chips = array();
    if ($event['is_past']) {
        $chips[] = array('label' => __('Ya celebrado', 'sticpa'), 'tone' => 'past');
    } elseif ($regChip !== null) {
        // Las fechas del plazo mandan sobre el `status` del CRM: ver la nota de
        // sticpa_event_registration_chip().
        $chips[] = $regChip;
    } elseif ($statusLabel !== '' || $event['status'] !== '') {
        $chips[] = array('label' => sticpa_event_status_label($event['status'], $statusLabel !== '' ? $statusLabel : $event['status']), 'tone' => '');
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
    // EL BOTÓN DEL MAPA cuelga del PRIMER dato del lugar que haya, y no de una
    // acción propia abajo: se toca donde se lee el sitio. Y no compite con
    // «Inscribirme», que sigue siendo la única acción principal de la pantalla
    // (design.md §6.2).
    $mapaUrl = (string) ($event['mapa_url'] ?? '');
    $conMapa = '';
    if ($mapaUrl !== '') {
        foreach (array('ajmcm_lugar_c', 'ajmcm_direccion_c') as $campo) {
            if (isset($event['optional'][$campo])) {
                $conMapa = $campo;
                break;
            }
        }
    }
    foreach ($event['optional'] as $campo => $item) {
        if ($campo === $conMapa) {
            $item['link'] = array('url' => $mapaUrl, 'label' => __('Ver en el mapa', 'sticpa'));
        }
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
        // Con FWA, el botón saca a otra pantalla que no es del área: se dice
        // antes, para que no parezca que algo se ha roto.
        if (($event['fwa_url'] ?? '') !== '') {
            $ctaNote = __('La inscripción se hace en el formulario de la actividad. Te lo abrimos con tus datos ya puestos.', 'sticpa');
        }
    }

    // EL TEXTO: el de la web si lo hay, y si no, la descripción de siempre.
    //
    // No los dos. El de la web es el que se escribe pensando en quien lo lee
    // —secciones, qué llevar, horarios— y la `description` suele ser su
    // resumen o una nota del equipo; con los dos, la ficha contaba lo mismo
    // dos veces y con palabras distintas.
    $conWeb = is_array($web) && function_exists('sticpa_event_web_has_content') && sticpa_event_web_has_content($web);
    $sections = $conWeb
        ? array(array('title' => __('Toda la información', 'sticpa'), 'body' => sticpa_event_web_section_html($web), 'raw' => true, 'class' => 'stic-evweb'))
        : array(array('title' => __('Sobre esta actividad', 'sticpa'), 'body' => $event['description']));

    return sticpa_record_detail_html(array(
        'back'     => array('url' => '?internalpage=list_stic_events', 'label' => __('Eventos', 'sticpa')),
        'title'    => $event['name'],
        'subtitle' => $conWeb ? (string) ($web['lema'] ?? '') : '',
        'meta'     => array(array('icon' => 'calendar', 'text' => $dateLine)),
        'chips'    => $chips,
        'cover'    => ($conWeb && !empty($web['cartel']))
            ? array('src' => $web['cartel'], 'alt' => sprintf(__('Cartel de %s', 'sticpa'), $event['name']))
            : null,
        'facts'    => $facts,
        'sections' => $sections,
        'actions'  => $actions,
        'cta_note' => $ctaNote,
        // «Inscribirme», pegado abajo (FAM-a2): con el cartel y la información
        // de la web quedaba a 2,7 pantallas, y quien llega desde WhatsApp a
        // apuntar a su hijo no sabía que existía. Solo cuando hay una acción
        // principal; «Ver mi inscripción» o «Ver otras» no merecen perseguirte.
        'sticky_cta' => !empty(array_filter($actions, function ($a) { return !empty($a['primary']); })),
    ));
}
