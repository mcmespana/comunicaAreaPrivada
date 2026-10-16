<?php
/**
 * ARNÉS DE RENDER OFFLINE de los grupos de actividad en la pantalla del evento.
 * design.md §9: una pantalla no está hecha hasta que se ha capturado a 375px y
 * se ha mirado, en los dos temas.
 *
 *   php tests/manual/render-grupos-actividad.php > /tmp/gac.html
 *   php tests/manual/render-grupos-actividad.php dark > /tmp/gac-dark.html
 *
 * Pinta, con el CRM falso de los tests (convivencia `ev-bus` con dos patrullas
 * y una habitación), las tres vistas una debajo de otra: la lista con sus
 * pestañas, la pestaña de patrullas y la lista por patrullas con la 1 elegida.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../PasarListaRenderTest.php';
$B = dirname(__DIR__, 2) . '/';

$scheme = (isset($argv[1]) && $argv[1] === 'dark') ? 'dark' : 'light';

$pintar = function ($request) use ($B) {
    $GLOBALS['__stic_pl_now'] = mktime(17, 0, 0, 11, 15, 2025);
    $GLOBALS['__stic_transients'] = array();
    $_SESSION = array('scp_user_id' => 'm1', 'scp_user_assigned_user_id' => 'deleg-castellon', 'scp_user_contact_name' => 'David Soler');
    $_REQUEST = $request;
    $_POST = array();
    $objSCP = new FakeSCP();
    $objSCP->conEventos = true;
    $objSCP->conGac = true;
    $html = '';
    $pageSettings = array();
    require $B . 'pages/single_stic_pasar_lista_evento.php';
    return $html;
};

$vistas = array(
    'La lista, con sus pestañas' => array('evento' => 'ev-bus'),
    'Pestaña «Patrullas»' => array('evento' => 'ev-bus', 'grupos' => 'patrulla|'),
    'Pestaña «Habitaciones»' => array('evento' => 'ev-bus', 'grupos' => 'habitacion|'),
    'Lista por patrullas, con la 1 elegida' => array('evento' => 'ev-bus', 'por' => 'patrulla|', 'grupo' => 'gp1'),
);

$css = file_get_contents($B . 'css/custom-style.css') . file_get_contents($B . 'css/pasar-lista.css');

echo "<!doctype html><html lang=es data-stic-scheme={$scheme}><head><meta charset=utf-8>
<meta name=viewport content='width=device-width,initial-scale=1'><title>Grupos de actividad</title>
<style>{$css}</style><style>*{box-sizing:border-box}
body{margin:0;background:var(--bg-color,#f6f7f9)}.harness{padding:1rem;max-width:760px;margin:0 auto}
.harness>h2{font:700 .8rem/1 system-ui;text-transform:uppercase;letter-spacing:.06em;color:#888;margin:2.5rem 0 .75rem}
.harness>h2:first-child{margin-top:0}
/* La barra de guardar va fija abajo: en el arnés, en su sitio. */
.pl-savebar{position:static!important}
.pl-sheet,.pl-sheet-veil{display:none!important}</style></head><body>
<div class='stic-container'><div class='stic-tab-content'><div class=harness>";
foreach ($vistas as $titulo => $req) {
    echo '<h2>' . htmlspecialchars($titulo) . '</h2>' . $pintar($req);
}
echo "</div></div></div><script>" . file_get_contents($B . 'js/stic-pasar-lista.js') . "</script></body></html>";
