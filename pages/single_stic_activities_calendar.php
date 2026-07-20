<?php
/**
 * CALENDARIO DE ACTIVIDADES del área privada.
 * ----------------------------------------------------------------------------
 * Muestra en un mismo calendario (FullCalendar):
 *   · Eventos abiertos a inscripción (violeta)  → clic = inscribirte.
 *   · Eventos en los que ya estás inscrito (azul suave, contexto).
 *   · Sesiones de tus eventos, coloreadas por ASISTENCIA:
 *       próxima · asististe · parcial · falta justificada · no asististe · sin registrar.
 *
 * Toda la lógica de datos, colores y etiquetas vive en inc/stic-calendar.php
 * (misma fuente que el widget "Próximas actividades" de la home). La config de
 * FullCalendar viaja en data-fc-settings y la arranca js/stic-init.js (plan 021);
 * el eventClick/eventDidMount (no serializables) se añaden allí.
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageSettings['fileName'] = basename(__FILE__, ".php");

$calData = sticpa_gather_calendar_data($objSCP);
$calEvents = sticpa_calendar_fc_events($calData);

$lang = explode('_', get_locale())[0];

$fcSettings = array(
    'initialView' => 'dayGridMonth',
    'locale' => $lang,
    'contentHeight' => 'auto',
    'handleWindowResize' => true,
    'firstDay' => 1, // la semana empieza en lunes
    'dayMaxEvents' => 3, // en móvil agrupa el exceso en "+N más"
    'eventTimeFormat' => array(
        'hour' => '2-digit',
        'minute' => '2-digit',
        'meridiem' => false,
    ),
    'headerToolbar' => array(
        'left' => 'prev,next today',
        'center' => 'title',
        'right' => 'dayGridMonth,listMonth',
    ),
    'buttonText' => array(
        'today' => __('Hoy', 'sticpa'),
        'month' => __('Mes', 'sticpa'),
        'list' => __('Agenda', 'sticpa'),
    ),
    'noEventsText' => __('No hay actividades en estas fechas', 'sticpa'),
    'events' => $calEvents,
);

$html .= "<div class='stic-entry-header'>
    <h3>" . __('Calendario', 'sticpa') . "</h3>
</div>";

$html .= "<div class='stic-calendar-wrap'>";
$html .= "<p class='stic-calendar-intro'>"
    . esc_html__('Tus sesiones se colorean según tu asistencia. Toca cualquier actividad para ver el detalle o inscribirte.', 'sticpa')
    . "</p>";
$html .= sticpa_calendar_legend_html();
$html .= "<div id='calendar' data-fc-settings='" . esc_attr(json_encode($fcSettings)) . "'></div>";
$html .= "</div>";
