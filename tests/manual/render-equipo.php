<?php
/**
 * ARNÉS DE RENDER OFFLINE de «Equipo de monitores».
 * design.md §9: una pantalla no está hecha hasta que se ha capturado a 375px y
 * se ha mirado, en los dos temas.
 *
 *   php tests/manual/render-equipo.php > /tmp/equipo.html
 *   php tests/manual/render-equipo.php dark > /tmp/equipo-dark.html
 *
 * Se pintan las dos piezas nuevas y el sitio donde viven: el grupo de la home
 * con su chip, y la frase de «por qué lo ves» en sus tres variantes (con
 * alcance, sin alcance y de acompañamiento).
 */

require_once __DIR__ . '/../bootstrap.php';
$B = dirname(__DIR__, 2) . '/';
require_once $B . 'inc/stic-equipo.php';

$scheme = (isset($argv[1]) && $argv[1] === 'dark') ? 'dark' : 'light';

// Una persona que es monitora, coordina y además acompaña: el caso con más
// cosas que enseñar a la vez (es el de David Soler en el CRM real).
$_SESSION['scp_relationship_raw'] = '^acompanamiento_mic_com^,^coordinacion_mic_com^,^grupo^,^monitor^';
$_SESSION['scp_role'] = 'monitor';
$_SESSION['scp_role_resolved'] = true;
$GLOBALS['__stic_filters']['sticpa_profile_audience'] = 'miembro';

$chips = sticpa_equipo_chips_html();
$porqueHome = sticpa_equipo_por_que_html('', 'coordinacion', 'estas secciones');
$porqueAmbito = sticpa_equipo_por_que_html('COM · COM 2', 'coordinacion');
$porqueAcomp = sticpa_equipo_por_que_html('', 'acompanamiento', 'estos seguimientos');

// Las tarjetas de la home, con los mismos iconos y descripciones de verdad.
$secciones = sticpa_equipo_secciones();
$meta = array(
    'single_stic_comunica_monitor' => array('Tu formación, certificados y datos de monitor/a.', "<path d='M22 10 12 5 2 10l10 5 10-5Z'/><path d='M6 12v5c0 1 2 3 6 3s6-2 6-3v-5'/>"),
    'single_stic_pasar_lista' => array('Marca quién ha venido, sábado a sábado.', "<path d='M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2'/><rect x='9' y='3' width='6' height='4' rx='1'/><path d='m9 14 2 2 4-4'/>"),
    'single_stic_mis_grupos' => array('Las fichas de tu gente, sin pasar lista.', "<circle cx='9' cy='8' r='3.5'/><path d='M2 20v-1a6 6 0 0 1 12 0v1'/><path d='M17 8.5a3 3 0 0 1 0 5'/><path d='M19 20v-1a5 5 0 0 0-2.5-4'/>"),
    'single_stic_pasar_lista_monitores' => array('Tu equipo: asistencia, fichas y seguimientos.', "<circle cx='10' cy='8' r='3.5'/><path d='M3 20v-1a6 6 0 0 1 11-3.3'/><path d='m15 17 2 2 4-4'/>"),
    'single_stic_pasar_lista_reuniones' => array('Reuniones de programación: crearlas y pasar lista.', "<rect x='3' y='4' width='18' height='18' rx='2'/><path d='M16 2v4M8 2v4M3 10h18'/><path d='M12 13v3l2 1'/>"),
);

$cards = '';
foreach ($secciones as $key => $label) {
    list($desc, $icon) = $meta[$key];
    $cards .= "<a class='stic-dash-card' href='#'>"
        . "<span class='stic-dash-icon'><svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'>{$icon}</svg></span>"
        . "<span class='stic-dash-title'>" . esc_html($label) . "</span>"
        . "<p class='stic-dash-desc'>" . esc_html($desc) . "</p>"
        . "<span class='stic-dash-go'>Entrar</span></a>";
}

// Las dos hojas: el bloque de coordinación vive DENTRO de Pasar lista, que
// tiene su propio CSS. Sin él, la frase se veía sobre una lista sin estilo y no
// se estaba comprobando nada.
$css = file_get_contents($B . 'css/custom-style.css')
    . file_get_contents($B . 'css/pasar-lista.css');

echo "<!doctype html><html lang=es data-stic-scheme={$scheme}><head><meta charset=utf-8>
<meta name=viewport content='width=device-width,initial-scale=1'><title>Equipo de monitores</title>
<style>{$css}</style><style>/* Astra pone un box-sizing global en el sitio real; sin él, `width:100%` +
   padding de Pasar Lista desborda y el arnés mide un desbordamiento que no
   existe (la trampa de la §56.0 del CSS). */
*{box-sizing:border-box}
body{margin:0;background:var(--bg-color,#f6f7f9)}.harness{padding:1rem}
.harness h2{font:700 .8rem/1 system-ui;text-transform:uppercase;letter-spacing:.06em;color:#888;margin:2rem 0 .75rem}
.harness h2:first-child{margin-top:0}
/* Pasar Lista se pinta a todo el ancho del contenedor: el arnés le devuelve el
   sangrado que le quita a los demás bloques, o mediría mal el desbordamiento. */
.harness-pl{margin:0 -1rem}</style></head><body>
<div class='stic-container'><div class='stic-tab-content'><div class=harness>
<h2>El grupo de la home</h2>
<section class='stic-home-account stic-home-equipo'>
  <p class='stic-section-label stic-section-label--mini stic-section-label--conchips'>Equipo de monitores {$chips}</p>
  {$porqueHome}
  <div class='stic-dashboard-grid stic-dashboard-grid--mini'>{$cards}</div>
</section>
<h2>El bloque de coordinación, dentro de Pasar lista</h2>
<div class=harness-pl>
<div class='pl-etapa-title'><span class='pl-etapa-dot' style='background:var(--secondary-color)'></span>Coordinación<span class='pl-scope'>COM · COM 2</span></div>
{$porqueAmbito}
<div class='pl-list'>
  <a class='pl-group' href='#'><span class='pl-group-body'><span class='pl-name'>Monitores</span><span class='pl-group-meta'>Pasar lista del sábado</span></span></a>
  <a class='pl-group' href='#'><span class='pl-group-body'><span class='pl-name'>Reuniones</span><span class='pl-group-meta'>Programación: crear y pasar lista</span></span></a>
</div>
</div>
<h2>Los dos chips (contraste en los dos temas)</h2>
<p class='stic-section-label stic-section-label--mini stic-section-label--conchips'>Coordinación <span class='stic-equipo-chip'>Coordinación</span> <span class='stic-equipo-chip stic-equipo-chip--acompanamiento'>Acompañamiento</span></p>
<h2>La frase, con alcance (pantallas de coordinación)</h2>{$porqueAmbito}
<h2>La frase, en seguimientos (acompañamiento)</h2>{$porqueAcomp}
</div></div></div></body></html>";
