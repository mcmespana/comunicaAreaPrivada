<?php
/**
 * ARNÉS DE RENDER OFFLINE de «ese correo no está» (31/10/2026).
 * design.md §9: una pantalla no está hecha hasta que se ha capturado a 375px y
 * se ha mirado, en los dos temas.
 *
 *   php tests/manual/render-acceso-correo.php > /tmp/correo.html
 *   php tests/manual/render-acceso-correo.php dark > /tmp/correo-dark.html
 *
 * Pinta la pantalla REAL del login (la función se saca del fichero principal,
 * como hace el test) en tres estados: tras un correo que no existe, tras un
 * aviso corto (correo mal escrito) y la de siempre, para comparar.
 */

require_once __DIR__ . '/../bootstrap.php';
$B = dirname(__DIR__, 2) . '/';
require_once $B . 'inc/stic-otp.php';

if (!function_exists('shortcode_exists')) {
    function shortcode_exists($tag) { return false; }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($k) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $k)); }
}
if (!function_exists('is_email')) {
    function is_email($e) { return (bool) filter_var((string) $e, FILTER_VALIDATE_EMAIL); }
}
$fuente = file_get_contents($B . 'sinergiacrm-private-area.php');
foreach (array('sticpa_icon', 'sticpa_greeting', 'sugar_crm_portal_login_form') as $funcion) {
    if (!function_exists($funcion) && preg_match('/^function ' . $funcion . '\(.*?\n}\n/ms', $fuente, $m)) {
        eval($m[0]);
    }
}

$scheme = (isset($argv[1]) && $argv[1] === 'dark') ? 'dark' : 'light';
$_SERVER['REQUEST_URI'] = '/area-privada';
$_GET = array();

function pantalla($request, $titulo)
{
    $_REQUEST = $request;
    $html = sugar_crm_portal_login_form();
    return "<h2>{$titulo}</h2><div class='stic-auth-shell'><div class='stic-login-form stic-form'>{$html}</div></div>";
}

$_SESSION['sticpa_email_miss'] = 'david@movimientoconsolacon.com';
$salida = pantalla(array('email_error' => 'nohay'), 'El correo no existe (con la errata a la vista)')
    . pantalla(array('email_error' => 'formato'), 'Correo mal escrito')
    . pantalla(array('email_error' => 'throttled'), 'Demasiadas direcciones seguidas')
    . pantalla(array(), 'La de siempre');

$css = file_get_contents($B . 'css/custom-style.css');
echo "<!doctype html><html lang=es data-stic-scheme={$scheme}><head><meta charset=utf-8>
<meta name=viewport content='width=device-width,initial-scale=1'><title>Acceso — correo no encontrado</title>
<style>{$css}</style><style>*{box-sizing:border-box}
body{margin:0;background:var(--bg-color,#f6f7f9)}
h2{font:700 .8rem/1 system-ui;text-transform:uppercase;letter-spacing:.06em;color:#888;margin:2rem 1rem .75rem}
</style></head><body>{$salida}</body></html>";
