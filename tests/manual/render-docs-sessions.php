<?php
/**
 * ARNÉS DE RENDER OFFLINE de Documentos, Sesiones y Asistencias.
 * design.md §9: capturar a 375px, en los dos temas, y MIRARLO.
 *
 *   php tests/manual/render-docs-sessions.php > /tmp/docs.html
 */

require_once __DIR__ . '/../bootstrap.php';
$B = dirname(__DIR__, 2) . '/';
require_once $B . 'inc/stic-record-view.php';
require_once $B . 'inc/stic-formatter.php';
require_once $B . 'inc/stic-documents.php';
require_once $B . 'inc/stic-sessions.php';

function stic_nvl(array $a)
{
    $o = new stdClass();
    foreach ($a as $k => $v) {
        $o->$k = (object) array('value' => $v);
    }
    return $o;
}

function stic_row(array $a)
{
    $r = new stdClass();
    $r->name_value_list = stic_nvl($a);
    return $r;
}

$defDocs = array('status_id' => array('options' => array(
    'Active' => array('value' => 'Vigente'),
    'Draft'  => array('value' => 'Borrador'),
)));

$defAtt = array('status' => array('options' => array(
    'attended'      => array('value' => 'Vino'),
    'no_attended'   => array('value' => 'No vino'),
    'justified'     => array('value' => 'Falta justificada'),
)));

$documentos = array(
    stic_row(array('id' => 'd1', 'document_name' => 'Autorización de imagen firmada',
        'filename' => 'autorizacion-imagen-2026.pdf', 'status_id' => 'Active',
        'active_date' => '2026-09-01')),
    stic_row(array('id' => 'd2', 'document_name' => 'Foto del carnet',
        'filename' => 'IMG_20260412_carnet.heic', 'status_id' => 'Draft',
        'active_date' => '2026-04-12')),
    stic_row(array('id' => 'd3', 'document_name' => 'Certificado médico',
        'filename' => 'certificado.pdf', 'status_id' => 'Active',
        'active_date' => '2024-06-01', 'exp_date' => '2025-06-01')),
    stic_row(array('id' => 'd4', 'document_name' => 'Ficha sin archivo', 'filename' => '')),
);

$sesiones = array(
    stic_row(array('id' => 's1', 'name' => 'Sesión del martes',
        'start_date' => '2026-11-04 17:30:00', 'end_date' => '2026-11-04 19:00:00',
        'stic_sessions_stic_events_name' => 'Grupo COM — curso 2026/2027',
        'stic_sessions_stic_eventsstic_events_ida' => 'ev-1')),
    stic_row(array('id' => 's2', 'name' => 'Convivencia de inicio',
        'start_date' => '2025-11-08 10:00:00', 'end_date' => '2025-11-09 18:00:00',
        'stic_sessions_stic_events_name' => 'Convivencia de inicio',
        'stic_sessions_stic_eventsstic_events_ida' => 'ev-2')),
);

$asistencias = array(
    stic_row(array('id' => 'a1', 'name' => 'AST-1', 'status' => 'attended',
        'start_date' => '2026-08-25 17:30:00', 'duration' => '1.5',
        'stic_attendances_stic_sessions_name' => 'Sesión del martes',
        'stic_attendances_stic_registrations_name' => 'Grupo COM — curso 2026/2027')),
    stic_row(array('id' => 'a2', 'name' => 'AST-2', 'status' => 'no_attended',
        'start_date' => '2026-08-18 17:30:00', 'duration' => '1.5',
        'stic_attendances_stic_sessions_name' => 'Sesión del martes')),
    stic_row(array('id' => 'a3', 'name' => 'AST-3', 'status' => 'justified',
        'start_date' => '2026-08-11 17:30:00', 'duration' => '0.75',
        'stic_attendances_stic_sessions_name' => 'Taller de dinámicas de grupo para monitores')),
);

$listaDocs = sticpa_documents_list_html($documentos, $defDocs);
$vacioDocs = sticpa_documents_list_html(array(), $defDocs);
$listaSes  = sticpa_sessions_list_html($sesiones);
$vacioSes  = sticpa_sessions_list_html(array());
$listaAtt  = sticpa_attendances_list_html($asistencias, $defAtt);
$vacioAtt  = sticpa_attendances_list_html(array(), $defAtt);

$css = file_get_contents($B . 'css/custom-style.css');

echo "<!doctype html><html lang=es data-stic-scheme=light><head><meta charset=utf-8>
<meta name=viewport content='width=device-width,initial-scale=1'><title>Documentos, sesiones y asistencias</title>
<style>{$css}</style><style>body{margin:0;background:var(--bg-color,#f6f7f9)}.harness{padding:1rem}
.harness h2{font:700 .8rem/1 system-ui;text-transform:uppercase;letter-spacing:.06em;color:#888;margin:2rem 0 .75rem}
.harness h2:first-child{margin-top:0}</style></head><body>
<div class='stic-container'><div class='stic-tab-content'><div class=harness>
<h2>Documentos</h2>{$listaDocs}
<h2>Documentos — vacío</h2>{$vacioDocs}
<h2>Sesiones</h2>{$listaSes}
<h2>Sesiones — vacío</h2>{$vacioSes}
<h2>Asistencias</h2>{$listaAtt}
<h2>Asistencias — vacío</h2>{$vacioAtt}
</div></div></div></body></html>";
