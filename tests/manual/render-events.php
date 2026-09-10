<?php
/**
 * ARNÉS DE RENDER OFFLINE de EVENTOS (listado + ficha).
 * ----------------------------------------------------------------------------
 * No hay entorno de pruebas con WordPress, y la ley de diseño (design.md §9)
 * exige capturar una pantalla a 375px y MIRARLA antes de darla por hecha. Esto
 * genera un HTML con el CSS real y el marcado real de inc/stic-events.php.
 *
 *   php tests/manual/render-events.php > /tmp/eventos.html
 *
 * ⚠️ LOS MESES SALEN EN INGLÉS y no es un fallo: `sticpa_record_date_line()`
 * usa `date_i18n()`, y aquí no hay WordPress — el doble de tests/bootstrap.php
 * cae en `date()`, que no tiene idioma. En producción salen en castellano. No
 * te vayas a buscar un bug que no existe.
 *
 * Los casos que pinta son los que de verdad se pueden torcer, y son casi todos
 * estados NUEVOS: el plazo de inscripción (abierto, aún no abierto, cerrado),
 * la actividad que no es para ti, y los campos que existen en el CRM pero
 * vienen a 0 y no deben pintarse (plazas y precio).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../inc/stic-formatter.php';
require_once __DIR__ . '/../../inc/stic-record-view.php';
require_once __DIR__ . '/../../inc/stic-events.php';
require_once __DIR__ . '/../../inc/stic-event-audience.php';

/** Un name_value_list como el que devuelve el CRM. */
function harness_nvl(array $fields)
{
    $o = new stdClass();
    foreach ($fields as $k => $v) {
        $o->$k = (object) array('value' => $v);
    }
    return $o;
}

function harness_row(array $fields)
{
    $r = new stdClass();
    $r->name_value_list = harness_nvl($fields);
    return $r;
}

$d = function ($rel) {
    return date('Y-m-d', strtotime($rel));
};

// --- LISTADO: los cinco estados que puede tener una tarjeta ----------------
$filas = array(
    // Dentro de plazo: fecha límite a la vista y botón.
    harness_row(array(
        'id' => 'e1', 'name' => 'Convivencia de inicio de curso · Benigànim',
        'start_date' => $d('+45 days'), 'end_date' => $d('+47 days'),
        'status' => 'registration', 'location' => 'Casa de Espiritualidad, Benigànim',
        'ajmcm_start_inscripcion_c' => $d('-5 days'), 'ajmcm_end_inscripcion_c' => $d('+12 days'),
    )),
    // Todavía no se ha abierto: se dice cuándo, y no hay botón.
    harness_row(array(
        'id' => 'e2', 'name' => 'Campamento de verano 2027',
        'start_date' => $d('+200 days'), 'end_date' => $d('+210 days'),
        'status' => 'registration',
        'ajmcm_start_inscripcion_c' => $d('+30 days'), 'ajmcm_end_inscripcion_c' => $d('+90 days'),
    )),
    // Plazo terminado: se dice cuándo terminó, y no hay botón.
    harness_row(array(
        'id' => 'e3', 'name' => 'Encuentro de monitores de zona',
        'start_date' => $d('+20 days'),
        'status' => 'registration',
        'ajmcm_end_inscripcion_c' => $d('-3 days'),
    )),
    // Sin fechas de plazo: se puede apuntar y no se cuenta nada del plazo.
    harness_row(array(
        'id' => 'e4', 'name' => 'MIC | Sesiones semanales 2026-2027 · CS',
        'start_date' => $d('-30 days'), 'end_date' => $d('+200 days'),
        'status' => 'registration', 'timetable' => 'Sábados, de 17 a 19 h',
        'max_attendees' => '0', 'price' => '0.00',
    )),
    // Ya celebrado: apagado y al final.
    harness_row(array(
        'id' => 'e5', 'name' => 'Convivencia de inicio 2025',
        'start_date' => $d('-320 days'), 'end_date' => $d('-318 days'),
        'status' => 'registration',
    )),
);
$statusMap = array('registration' => 'Inscripción abierta');
$listado = sticpa_events_list_html($filas, $statusMap);

// El estado vacío: lo que ve una delegación que todavía no tiene eventos
// suyos, que a partir de este cambio son casi todas.
$vacio = sticpa_record_empty_html(
    'calendar',
    'No hay eventos abiertos ahora mismo',
    'Cuando se abra la inscripción de una actividad, aparecerá aquí. Los eventos en los que ya estás inscrito están en “Inscripciones”.',
    array('label' => 'Ver mis inscripciones', 'url' => '#')
);

// --- FICHAS ----------------------------------------------------------------

// Con todo relleno: es la que enseña los campos que hasta ahora no se pintaban
// (horario, plazas, precio) más el plazo de inscripción.
$completa = sticpa_event_detail_html(
    sticpa_event_view_model(harness_nvl(array(
        'id' => 'e1', 'name' => 'Campamento de verano 2027 — El Escorial',
        'start_date' => $d('+200 days'), 'end_date' => $d('+210 days'),
        'status' => 'registration',
        'description' => "Diez días de convivencia, juego y servicio en la sierra de Madrid.\n\nLa cuota incluye el alojamiento en pensión completa, el material de las actividades, el seguro y el transporte desde el punto de encuentro de cada delegación.",
        'location' => 'Albergue Juvenil de San Lorenzo de El Escorial',
        'timetable' => 'Salida el día 1 a las 9:00 desde la parroquia',
        'max_attendees' => '60', 'price' => '285.00',
        'ajmcm_start_inscripcion_c' => $d('-5 days'), 'ajmcm_end_inscripcion_c' => $d('+40 days'),
    ))),
    'Inscripción abierta',
    true,
    ''
);

// Los ceros del CRM: `max_attendees = 0` y `price = 0.00` son el valor por
// defecto de SuiteCRM, no una respuesta. Esta ficha NO debe decir «Plazas 0»
// ni «Precio 0,00 €» — que es lo que decía en las cinco actividades reales.
$conCeros = sticpa_event_detail_html(
    sticpa_event_view_model(harness_nvl(array(
        'id' => 'e4', 'name' => 'COM | Sesiones semanales 2026-2027 · CS',
        'start_date' => $d('-30 days'), 'end_date' => $d('+200 days'),
        'status' => 'registration',
        'description' => 'Las sesiones de los sábados del grupo del COM.',
        'timetable' => 'Sábados, de 17 a 19 h',
        'max_attendees' => '0', 'price' => '0.00',
    ))),
    'Inscripción abierta',
    true,
    ''
);

// No es para ti (audiencia): sin botón, y con el motivo.
$otroPerfil = sticpa_event_detail_html(
    sticpa_event_view_model(harness_nvl(array(
        'id' => 'e6', 'name' => 'Congreso de monitores 2027 · Benicàssim',
        'start_date' => $d('+120 days'), 'end_date' => $d('+123 days'),
        'status' => 'registration',
        'description' => 'Tres días de formación y encuentro para monitores y monitoras de todas las delegaciones.',
        'ajmcm_dirigido_a_c' => '^monitor^',
    ))),
    'Inscripción abierta',
    true,
    'Esta actividad es solo para Monitores/as.'
);

// Fuera de plazo: el dato de la fecha arriba y el motivo abajo, coherentes.
$fueraDePlazo = sticpa_event_detail_html(
    sticpa_event_view_model(harness_nvl(array(
        'id' => 'e3', 'name' => 'Encuentro de monitores de zona',
        'start_date' => $d('+20 days'),
        'status' => 'registration',
        'description' => 'Una tarde de programación conjunta entre las delegaciones de la zona.',
        'ajmcm_end_inscripcion_c' => $d('-3 days'),
    ))),
    'Inscripción abierta',
    true,
    'El plazo de inscripción terminó el ' . date('j', strtotime($d('-3 days'))) . ' de ' . date('F', strtotime($d('-3 days'))) . ' de ' . date('Y', strtotime($d('-3 days'))) . '.'
);

// Ya inscrito: manda sobre todo lo demás, porque es un hecho sobre la persona.
$yaInscrito = sticpa_event_detail_html(
    sticpa_event_view_model(harness_nvl(array(
        'id' => 'e1', 'name' => 'Convivencia de inicio de curso · Benigànim',
        'start_date' => $d('+45 days'), 'end_date' => $d('+47 days'),
        'status' => 'registration', 'location' => 'Casa de Espiritualidad, Benigànim',
        'ajmcm_end_inscripcion_c' => $d('+12 days'),
    ))),
    'Inscripción abierta',
    false,
    'Esta actividad es solo para Monitores/as.'
);

$css = file_get_contents(__DIR__ . '/../../css/custom-style.css');

echo <<<HTML
<!doctype html>
<html lang="es" data-stic-scheme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Eventos — render offline</title>
<style>{$css}</style>
<style>
  body { margin: 0; background: var(--bg-color, #f6f7f9); }
  .harness { padding: 1rem; }
  .harness h2 { font: 700 0.8rem/1.3 system-ui; text-transform: uppercase; letter-spacing: .06em;
                color: #888; margin: 2rem 0 .75rem; }
  .harness h2:first-child { margin-top: 0; }
</style>
</head>
<body>
<div class="stic-container"><div class="stic-tab-content"><div class="harness">
  <h2>Listado — plazo abierto, aún no abierto, cerrado, sin plazo, ya celebrado</h2>
  {$listado}
  <h2>Estado vacío (delegación sin eventos propios)</h2>
  {$vacio}
  <h2>Ficha completa — horario, plazas, precio y plazo</h2>
  {$completa}
  <h2>Ficha con plazas 0 y precio 0,00 — NO deben aparecer</h2>
  {$conCeros}
  <h2>Ficha que no es para ti (otro perfil)</h2>
  {$otroPerfil}
  <h2>Ficha fuera de plazo</h2>
  {$fueraDePlazo}
  <h2>Ficha de algo en lo que ya estás inscrito</h2>
  {$yaInscrito}
</div></div></div>
</body>
</html>
HTML;
