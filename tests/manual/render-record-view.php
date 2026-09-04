<?php
/**
 * ARNÉS DE RENDER OFFLINE de la ficha de registro.
 * ----------------------------------------------------------------------------
 * No hay entorno de pruebas con WordPress, y la ley de diseño (design.md §9)
 * exige que una pantalla nueva se CAPTURE a 375px y se MIRE antes de darla por
 * hecha. Esto genera un HTML con el CSS real y el marcado real de
 * inc/stic-record-view.php, para abrirlo o capturarlo con Chromium.
 *
 *   php tests/manual/render-record-view.php > /tmp/ficha.html
 *
 * Los casos que pinta son los que de verdad hacen daño: la tarjeta con dos
 * acciones, el estado vacío, la ficha con importe grande, la ficha con avisos
 * de varios tonos y la ficha larga con secciones de texto.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../inc/stic-record-view.php';

$cards = array(
    array(
        'url'     => '#',
        'ts'      => strtotime('2026-07-01'),
        'name'    => 'Campamento de verano 2026 — El Escorial',
        'lines'   => array(
            array('icon' => 'calendar', 'text' => sticpa_record_date_line(strtotime('2026-07-01'), strtotime('2026-07-10'))),
            array('icon' => 'pin', 'text' => 'Albergue Juvenil de San Lorenzo de El Escorial'),
        ),
        'chips'   => array(array('label' => 'Inscripción abierta', 'tone' => 'ok')),
        'actions' => array(
            array('label' => 'Ver detalle', 'url' => '#'),
            array('label' => 'Inscribirme', 'url' => '#', 'primary' => true),
        ),
    ),
    array(
        'url'     => '#',
        'ts'      => strtotime('2026-03-14'),
        'name'    => 'Cuota de marzo',
        'lines'   => array(array('icon' => 'card', 'text' => 'Domiciliación bancaria · ES12 **** 3456')),
        'chips'   => array(array('label' => 'Devuelto', 'tone' => 'danger')),
        'actions' => array(array('label' => 'Ver el recibo', 'url' => '#', 'primary' => true)),
    ),
    array(
        'ts'      => null,
        'icon'    => 'file',
        'name'    => 'Autorización de imagen firmada.pdf',
        'lines'   => array(array('icon' => 'clock', 'text' => 'Subido el 4 de septiembre de 2026')),
        'chips'   => array(array('label' => 'Pendiente de revisar', 'tone' => 'warn')),
        'actions' => array(array('label' => 'Descargar', 'url' => '#', 'primary' => true, 'icon' => 'download')),
    ),
    array(
        'url'     => '#',
        'ts'      => strtotime('2025-11-08'),
        'name'    => 'Convivencia de inicio de curso',
        'is_past' => true,
        'lines'   => array(array('icon' => 'calendar', 'text' => sticpa_record_date_line(strtotime('2025-11-08')))),
        'chips'   => array(array('label' => 'Ya celebrado', 'tone' => 'past')),
        'actions' => array(array('label' => 'Ver detalle', 'url' => '#')),
    ),
);

$detailPago = sticpa_record_detail_html(array(
    'back'     => array('url' => '#', 'label' => 'Pagos'),
    'title'    => 'Campamento de verano 2026 — 1.º plazo',
    'meta'     => array(array('icon' => 'calendar', 'text' => '14 de marzo de 2026')),
    'chips'    => array(array('label' => 'Devuelto', 'tone' => 'danger')),
    'headline' => array('label' => 'Importe', 'text' => '120,00 €', 'sub' => 'Domiciliación bancaria'),
    'notes'    => array(
        array('tone' => 'danger', 'text' => 'El banco devolvió este recibo el 20 de marzo. Ponte en contacto con tu delegación para volver a intentarlo.'),
    ),
    'facts'    => array(
        array('icon' => 'bank', 'label' => 'Cuenta', 'text' => 'ES12 **** **** **** 3456'),
        array('icon' => 'tag', 'label' => 'Concepto', 'text' => 'MCM Campamento 2026 1/3'),
        array('icon' => 'calendar', 'label' => 'Fecha de devolución', 'text' => '20 de marzo de 2026'),
        array('icon' => 'link', 'label' => 'Inscripción', 'text' => 'Campamento de verano 2026'),
    ),
    'actions'  => array(
        array('label' => 'Ver la inscripción', 'url' => '#', 'primary' => true, 'icon' => 'go'),
        array('label' => 'Ver el evento', 'url' => '#'),
    ),
));

$detailLargo = sticpa_record_detail_html(array(
    'back'     => array('url' => '#', 'label' => 'Eventos'),
    'title'    => 'Campamento de verano 2026 — El Escorial',
    'meta'     => array(array('icon' => 'calendar', 'text' => 'del 1 al 10 de julio de 2026')),
    'chips'    => array(array('label' => 'Inscripción abierta', 'tone' => 'ok')),
    'notes'    => array(array('tone' => 'info', 'text' => 'La inscripción se cierra el 15 de junio o cuando se agoten las plazas.')),
    'facts'    => array(
        array('icon' => 'clock', 'label' => 'Duración', 'text' => '10 días'),
        array('icon' => 'pin', 'label' => 'Lugar', 'text' => 'Albergue Juvenil de San Lorenzo de El Escorial'),
        array('icon' => 'users', 'label' => 'Plazas', 'text' => '60'),
        array('icon' => 'euro', 'label' => 'Precio', 'text' => '285,00 €'),
    ),
    'sections' => array(array(
        'title' => 'Sobre esta actividad',
        'body'  => "Diez días de convivencia, juego y servicio en la sierra de Madrid.\n\nLa cuota incluye el alojamiento en régimen de pensión completa, el material de las actividades, el seguro y el transporte desde el punto de encuentro de cada delegación.",
    )),
    'actions'  => array(array('label' => 'Inscribirme en esta actividad', 'url' => '#', 'primary' => true, 'icon' => 'go')),
));

$vacio = sticpa_record_empty_html(
    'card',
    'Todavía no tienes pagos',
    'Cuando se emita un recibo aparecerá aquí, con su importe y su estado.',
    array('label' => 'Ver mis inscripciones', 'url' => '#')
);

$css = file_get_contents(__DIR__ . '/../../css/custom-style.css');
$listado = sticpa_record_list_html($cards);

echo <<<HTML
<!doctype html>
<html lang="es" data-stic-scheme="light">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ficha de registro — render offline</title>
<style>{$css}</style>
<style>
  body { margin: 0; background: var(--bg-color, #f6f7f9); }
  .harness { padding: 1rem; }
  .harness h2 { font: 700 0.8rem/1 system-ui; text-transform: uppercase; letter-spacing: .06em;
                color: #888; margin: 2rem 0 .75rem; }
  .harness h2:first-child { margin-top: 0; }
</style>
</head>
<body>
<div class="stic-container"><div class="stic-tab-content"><div class="harness">
  <h2>Listado — tarjetas</h2>
  {$listado}
  <h2>Estado vacío</h2>
  {$vacio}
  <h2>Ficha con importe y aviso</h2>
  {$detailPago}
  <h2>Ficha larga con secciones</h2>
  {$detailLargo}
</div></div></div>
</body>
</html>
HTML;
