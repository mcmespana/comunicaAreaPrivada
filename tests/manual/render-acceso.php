<?php
/**
 * ARNÉS DE RENDER OFFLINE del RESCATE del acceso.
 * design.md §9: una pantalla no está hecha hasta que se ha capturado a 375px y
 * se ha mirado, en los dos temas.
 *
 *   php tests/manual/render-acceso.php > /tmp/acceso.html
 *   php tests/manual/render-acceso.php dark > /tmp/acceso-dark.html
 *
 * Se pinta la ayuda de la pantalla del código («¿Necesitas ayuda?») —con y sin
 * correo conocido—, las dos ayudas abiertas (que es como se ven cuando alguien
 * las pulsa) y los cuatro mensajes de error, que es donde más fácil es
 * escribir de más.
 */

require_once __DIR__ . '/../bootstrap.php';
$B = dirname(__DIR__, 2) . '/';
require_once $B . 'inc/stic-otp.php';
// Los iconos de verdad: sin ellos (`sticpa_otp_icon` devuelve '' si no existe
// `sticpa_icon`) no se veía que el del DNI se montaba encima del texto.
if (!function_exists('sticpa_icon')
    && preg_match('/function sticpa_icon\(.*?\n}\n/s', file_get_contents($B . 'sinergiacrm-private-area.php'), $m)) {
    eval($m[0]);
}

$scheme = (isset($argv[1]) && $argv[1] === 'dark') ? 'dark' : 'light';
$volver = '/area-privada?stic_auth=1';

$conCorreo = sticpa_access_rescue_html(sticpa_otp_mask_email('david@movimientoconsolacion.com'), $volver);
$sinCorreo = sticpa_access_rescue_html('', $volver);
// Las dos ayudas, abiertas: es lo que ve quien las pulsa.
$abierto = str_replace('<details class=', '<details open class=', $conCorreo . sticpa_login_help_html($volver));

$errores = '';
foreach (array('formato', 'throttled', 'sincorreo', 'nohay') as $caso) {
    $errores .= "<span class='error stic-msg-long' role='alert'>" . esc_html(sticpa_dni_error_message($caso)) . "</span>";
}

$css = file_get_contents($B . 'css/custom-style.css');

echo "<!doctype html><html lang=es data-stic-scheme={$scheme}><head><meta charset=utf-8>
<meta name=viewport content='width=device-width,initial-scale=1'><title>Acceso — rescate</title>
<style>{$css}</style><style>/* Astra pone un box-sizing global en el sitio real. */
*{box-sizing:border-box}
body{margin:0;background:var(--bg-color,#f6f7f9)}.harness{padding:1rem}
.harness h2{font:700 .8rem/1 system-ui;text-transform:uppercase;letter-spacing:.06em;color:#888;margin:2rem 0 .75rem}
.harness h2:first-child{margin-top:0}</style></head><body>
<div class='stic-auth-shell'><div class='stic-login-form stic-form'><div class='stic-auth-panel'><div class=harness>
<h2>Sabemos a qué correo se mandó</h2>{$conCorreo}
<h2>No lo sabemos (se pidió en otro dispositivo)</h2>{$sinCorreo}
<h2>Las dos ayudas, abiertas</h2>{$abierto}
<h2>Los cuatro mensajes de error</h2>{$errores}
</div></div></div></div></body></html>";
