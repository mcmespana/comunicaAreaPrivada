<?php
/**
 * Bootstrap de PHPUnit para el plugin (plan 013).
 * ----------------------------------------------------------------------------
 * El plugin corre dentro de WordPress; para testear su lógica en aislamiento
 * definimos stubs MÍNIMOS de las funciones/constantes de WP que usa el código
 * bajo prueba. No se carga WordPress real: solo lo imprescindible.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

// --- Constantes de WordPress usadas por el código ---
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');           // el guard `if (!defined('ABSPATH')) exit;` necesita esto
}
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
if (!defined('HOUR_IN_SECONDS'))   { define('HOUR_IN_SECONDS', 3600); }
if (!defined('DAY_IN_SECONDS'))    { define('DAY_IN_SECONDS', 86400); }
if (!defined('YEAR_IN_SECONDS'))   { define('YEAR_IN_SECONDS', 31536000); }

// Secreto HMAC fijo para que firmar/validar sea determinista en los tests.
$GLOBALS['__stic_options'] = array(
    'sticpa_magic_secret' => 'test-secret-0123456789abcdef',
);

// --- Stubs de funciones de WordPress ---
if (!function_exists('add_action'))  { function add_action(...$a) { return true; } }
// Los filtros de verdad: add_filter/remove_filter registran y apply_filters los
// ejecuta. Hacía falta porque el calentador de caché SUBE el TTL de la
// estructura con `add_filter` mientras calienta, y con un add_filter de mentira
// eso no se podía comprobar — o sea que la parte más fácil de que no funcione
// en silencio era justo la que no se probaba.
$GLOBALS['__stic_hooks'] = array();
if (!function_exists('add_filter')) {
    function add_filter($tag, $cb, $priority = 10, $args = 1)
    {
        $GLOBALS['__stic_hooks'][$tag][] = array('cb' => $cb, 'p' => (int) $priority);
        return true;
    }
}
if (!function_exists('remove_filter')) {
    function remove_filter($tag, $cb, $priority = 10)
    {
        if (empty($GLOBALS['__stic_hooks'][$tag])) {
            return false;
        }
        foreach ($GLOBALS['__stic_hooks'][$tag] as $i => $h) {
            if ($h['cb'] === $cb && $h['p'] === (int) $priority) {
                unset($GLOBALS['__stic_hooks'][$tag][$i]);
                return true;
            }
        }
        return false;
    }
}
// apply_filters ejecuta los filtros registrados, salvo que un test haya puesto
// un valor forzado en $GLOBALS['__stic_filters'][$tag]: eso sigue mandando, que
// es la forma corta de probar el comportamiento con un filtro activo.
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value = null)
    {
        if (isset($GLOBALS['__stic_filters']) && array_key_exists($tag, $GLOBALS['__stic_filters'])) {
            return $GLOBALS['__stic_filters'][$tag];
        }
        if (!empty($GLOBALS['__stic_hooks'][$tag])) {
            $hooks = $GLOBALS['__stic_hooks'][$tag];
            usort($hooks, function ($a, $b) { return $a['p'] - $b['p']; });
            foreach ($hooks as $h) {
                $value = call_user_func($h['cb'], $value);
            }
        }
        return $value;
    }
}
if (!function_exists('do_action'))   { function do_action(...$a) {} }
if (!function_exists('get_option'))  {
    function get_option($k, $default = false) {
        return $GLOBALS['__stic_options'][$k] ?? $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($k, $v, $autoload = null) { $GLOBALS['__stic_options'][$k] = $v; return true; }
}
if (!function_exists('add_query_arg')) {
    // Versión mínima suficiente para los tests: añade ?k=v (o &k=v) a la URL.
    function add_query_arg($key, $value, $url) {
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        return $url . $sep . rawurlencode($key) . '=' . rawurlencode($value);
    }
}
if (!function_exists('__'))          { function __($t, $d = null) { return $t; } }
if (!function_exists('esc_html'))    { function esc_html($t) { return $t; } }
if (!function_exists('esc_attr'))    { function esc_attr($t) { return $t; } }
if (!function_exists('esc_attr__'))  { function esc_attr__($t, $d = null) { return $t; } }
if (!function_exists('esc_html__'))  { function esc_html__($t, $d = null) { return $t; } }
if (!function_exists('esc_url'))     { function esc_url($u) { return $u; } }
if (!function_exists('is_singular')) { function is_singular($t = '') { return false; } }
if (!function_exists('get_post'))    { function get_post($p = null) { return null; } }
if (!function_exists('has_shortcode')) { function has_shortcode($c, $tag) { return false; } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $f = 0, $depth = 512) { return json_encode($d, $f, $depth); } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($t) { return is_string($t) ? trim($t) : $t; } }
if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($t) { return is_string($t) ? trim($t) : $t; } }

// --- Transients (los usa el código OTP) ---
// Implementación en memoria con caducidad real. El reloj se puede adelantar con
// $GLOBALS['__stic_time_offset'] para probar caducidades sin esperar 40 minutos.
$GLOBALS['__stic_transients'] = array();
$GLOBALS['__stic_time_offset'] = 0;

if (!function_exists('stic_test_now')) {
    function stic_test_now() { return time() + (int) $GLOBALS['__stic_time_offset']; }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $ttl = 0) {
        $GLOBALS['__stic_transients'][$key] = array(
            'value' => $value,
            // Igual que WordPress: TTL 0 significa "sin caducidad".
            'expires' => $ttl > 0 ? stic_test_now() + (int) $ttl : 0,
        );
        return true;
    }
}
if (!function_exists('get_transient')) {
    function get_transient($key) {
        if (!isset($GLOBALS['__stic_transients'][$key])) {
            return false;
        }
        $item = $GLOBALS['__stic_transients'][$key];
        if ($item['expires'] > 0 && stic_test_now() >= $item['expires']) {
            unset($GLOBALS['__stic_transients'][$key]);
            return false;
        }
        return $item['value'];
    }
}
if (!function_exists('delete_transient')) {
    function delete_transient($key) {
        unset($GLOBALS['__stic_transients'][$key]);
        return true;
    }
}

// --- Stubs añadidos para las pantallas de Pasar Lista ---
// Se necesitan porque el test de render ejecuta las páginas de verdad
// (pages/single_stic_pasar_lista*.php) contra un CRM falso, y esas páginas usan
// plurales, fechas localizadas y nonces.
if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = null) { return ($number == 1) ? $single : $plural; }
}
if (!function_exists('esc_sql')) { function esc_sql($v) { return $v; } }
if (!function_exists('esc_js')) { function esc_js($v) { return addslashes((string) $v); } }
if (!function_exists('home_url')) { function home_url($path = '') { return 'https://example.test' . $path; } }
if (!function_exists('status_header')) { function status_header($code) { return $code; } }
if (!function_exists('plugin_dir_path')) { function plugin_dir_path($file) { return dirname($file) . '/'; } }
if (!function_exists('date_i18n')) {
    function date_i18n($format, $ts = null) { return date($format, $ts === null ? time() : $ts); }
}
if (!function_exists('wp_date')) {
    function wp_date($format, $ts = null) { return date($format, $ts === null ? time() : $ts); }
}
if (!function_exists('wp_unslash')) { function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; } }
if (!function_exists('wp_create_nonce')) { function wp_create_nonce($action = -1) { return 'nonce-' . md5((string) $action); } }
if (!function_exists('wp_verify_nonce')) {
    function wp_verify_nonce($nonce, $action = -1) { return $nonce === wp_create_nonce($action) ? 1 : false; }
}
if (!function_exists('wp_nonce_field')) {
    function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true) {
        $field = '<input type="hidden" name="' . $name . '" value="' . wp_create_nonce($action) . '">';
        if ($echo) { echo $field; }
        return $field;
    }
}

// --- Código bajo prueba ---
require_once __DIR__ . '/../inc/stic-theme.php';
require_once __DIR__ . '/../inc/stic-magic-login.php';
require_once __DIR__ . '/../inc/stic-otp.php';
// stic-calendar.php solo define funciones (más el guard de ABSPATH), así que se
// carga sin WordPress. De aquí se testea sticpa_event_ids_from_calendar_cache,
// la pieza pura del guard anti-duplicado de inscripciones.
require_once __DIR__ . '/../inc/stic-calendar.php';
// stic-action.php solo registra `add_action(...)` a nivel de archivo (ya
// stubeado arriba); el resto son definiciones de función, así que cargarlo
// aquí no ejecuta nada que dependa de WordPress/SugarCRM real.
require_once __DIR__ . '/../inc/stic-action.php';
// stic-class-6.php solo declara la clase del cliente del CRM (más un
// `define`), así que cargarlo no abre ninguna conexión. Se necesita para
// SugarRestApiCall::attachLinkList(), que es el ensamblado de la respuesta de
// `get_relationships`: el doble de los tests de render lo usa para devolver la
// forma REAL de la API en vez de una inventada.
require_once __DIR__ . '/../inc/stic-class-6.php';
// stic-pasar-lista.php es la lógica de Pasar Lista SIN CRM: qué sesión se
// ofrece, el denominador del porcentaje, las ausencias seguidas y el nombre del
// grupo. Solo define funciones, así que se carga tal cual. La parte que habla
// con el CRM está aparte (inc/stic-pasar-lista-crm.php) y no se testea aquí.
require_once __DIR__ . '/../inc/stic-pasar-lista.php';
// La capa de CRM y la de HTML también se cargan: el test de render ejecuta las
// páginas contra un doble de $objSCP, que es la única forma de comprobar sin
// WordPress que las pantallas se pintan enteras y sin avisos.
require_once __DIR__ . '/../inc/stic-pasar-lista-crm.php';
require_once __DIR__ . '/../inc/stic-pasar-lista-ui.php';
// El service worker: el archivo solo define funciones y un add_action (ya
// stubeado). El modo sin conexión está apagado por defecto, así que en los
// tests sticpa_pl_sw_register_html() devuelve cadena vacía, que es justo el
// comportamiento que hay que comprobar.
require_once __DIR__ . '/../inc/stic-pasar-lista-sw.php';
// El calentador de caché. El archivo solo define funciones y un add_action (ya
// stubeado); el endpoint REST no se registra de verdad porque register_rest_route
// no existe aquí, y no hace falta: lo que se testea son las piezas (la firma, el
// sello de tiempo y el calentado), no el enrutado de WordPress.
require_once __DIR__ . '/../inc/stic-pasar-lista-warm.php';
