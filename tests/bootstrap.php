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
if (!function_exists('add_filter'))  { function add_filter(...$a) { return true; } }
if (!function_exists('apply_filters')) { function apply_filters($tag, $value = null) { return $value; } }
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

// --- Código bajo prueba ---
require_once __DIR__ . '/../inc/stic-theme.php';
require_once __DIR__ . '/../inc/stic-magic-login.php';
require_once __DIR__ . '/../inc/stic-otp.php';
