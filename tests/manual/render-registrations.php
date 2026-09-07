<?php
/**
 * ARNÉS DE RENDER OFFLINE del listado y la ficha de Inscripciones.
 * design.md §9: una pantalla no está hecha hasta que se ha capturado a 375px
 * y se ha mirado. De aquí salieron tres cambios de diseño sobre lo escrito.
 *
 *   php tests/manual/render-registrations.php > /tmp/inscripciones.html
 */
require_once __DIR__ . '/../bootstrap.php';
$B = dirname(__DIR__, 2) . '/';
require_once $B.'inc/stic-record-view.php';
require_once $B.'inc/stic-registrations.php';
if (!function_exists('formatValue')) { require_once $B.'inc/stic-formatter.php'; }

// Calendario CALIENTE: con fechas de evento (el caso bueno).
$_SESSION['scp_user_id']='c1';
set_transient(sticpa_calendar_cache_key(), array('registered_events' => array(
  array('id'=>'ev-1','name'=>'Campamento de verano 2026','start'=>'2026-07-01','end'=>'2026-07-10'),
  array('id'=>'ev-2','name'=>'Convivencia de inicio de curso','start'=>'2025-11-08','end'=>'2025-11-09'),
)), 300);
function nvl($a){ $o=new stdClass(); foreach($a as $k=>$v){ $o->$k=(object)array('value'=>$v);} return $o; }
function row($a){ $r=new stdClass(); $r->name_value_list=nvl($a); return $r; }

$def = array(
 'status'=>array('options'=>array('Confirmed'=>array('value'=>'Confirmada'),'pending'=>array('value'=>'Pendiente de confirmar'),'cancelled'=>array('value'=>'Cancelada'))),
 'participation_type'=>array('options'=>array('participant'=>array('value'=>'Participante'))),
 'ajmcm_tutor1_relationship_c'=>array('options'=>array('madre'=>array('value'=>'Madre'))),
 'ajmcm_tutor2_relationship_c'=>array('options'=>array('padre'=>array('value'=>'Padre'))),
);

$rows = array(
 row(array('id'=>'r1','name'=>'INS-000123','status'=>'Confirmed','registration_date'=>'2026-03-14 10:00:00',
   'stic_registrations_stic_events_name'=>'Campamento de verano 2026',
   'stic_registrations_stic_eventsstic_events_ida'=>'ev-1',
   'ajmcm_clase_c'=>'Grupo Los Peques','ajmcm_curso_escolar_c'=>'2025/2026')),
 row(array('id'=>'r2','name'=>'INS-000124','status'=>'pending','registration_date'=>'2026-04-02 09:00:00',
   'stic_registrations_stic_events_name'=>'Taller de monitores nivel 1',
   'stic_registrations_stic_eventsstic_events_ida'=>'ev-9')),
 row(array('id'=>'r3','name'=>'INS-000090','status'=>'cancelled','registration_date'=>'2025-10-20 09:00:00',
   'stic_registrations_stic_events_name'=>'Convivencia de inicio de curso',
   'stic_registrations_stic_eventsstic_events_ida'=>'ev-2')),
);

$listado = sticpa_registrations_list_html($rows, $def);
$vacio   = sticpa_registrations_list_html(array(), $def);

$ficha = sticpa_registration_detail_html(sticpa_registration_view_model(nvl(array(
  'id'=>'r1','name'=>'INS-000123','status'=>'Confirmed','registration_date'=>'2026-03-14 10:00:00',
  'stic_registrations_stic_events_name'=>'Campamento de verano 2026',
  'stic_registrations_stic_eventsstic_events_ida'=>'ev-1',
  'ajmcm_clase_c'=>'Grupo Los Peques','ajmcm_curso_escolar_c'=>'2025/2026',
  'participation_type'=>'participant','ajmcm_registration_amount_c'=>'285.00','attendees'=>'1',
  'special_needs_description'=>'Alergia a los frutos secos. Lleva autoinyector en la mochila.',
  'ajmcm_tutor1_firstname_c'=>'Marta','ajmcm_tutor1_lastname_c'=>'Messeguer Ruiz',
  'ajmcm_tutor1_relationship_c'=>'madre','ajmcm_tutor1_phone_c'=>'600 333 444','ajmcm_tutor1_email_c'=>'marta@example.org',
  'ajmcm_tutor2_firstname_c'=>'Luis','ajmcm_tutor2_lastname_c'=>'Vilarroya',
  'ajmcm_tutor2_relationship_c'=>'padre','ajmcm_tutor2_phone_c'=>'600 555 666',
)), sticpa_registration_event_index()), $def);

$fichaPend = sticpa_registration_detail_html(sticpa_registration_view_model(nvl(array(
  'id'=>'r2','name'=>'INS-000124','status'=>'pending','registration_date'=>'2026-04-02 09:00:00',
  'stic_registrations_stic_events_name'=>'Taller de monitores nivel 1',
)), sticpa_registration_event_index()), $def);

$css = file_get_contents($B.'css/custom-style.css');
echo "<!doctype html><html lang=es data-stic-scheme=light><head><meta charset=utf-8>
<meta name=viewport content='width=device-width,initial-scale=1'><title>Inscripciones</title>
<style>{$css}</style><style>body{margin:0;background:var(--bg-color,#f6f7f9)}.harness{padding:1rem}
.harness h2{font:700 .8rem/1 system-ui;text-transform:uppercase;letter-spacing:.06em;color:#888;margin:2rem 0 .75rem}
.harness h2:first-child{margin-top:0}</style></head><body>
<div class='stic-container'><div class='stic-tab-content'><div class=harness>
<h2>Listado</h2>{$listado}
<h2>Vacío</h2>{$vacio}
<h2>Ficha completa (familia)</h2>{$ficha}
<h2>Ficha pendiente, calendario frío</h2>{$fichaPend}
</div></div></div></body></html>";
