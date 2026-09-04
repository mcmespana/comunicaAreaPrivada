<?php
/**
 * DETALLE DE UN EVENTO.
 * ----------------------------------------------------------------------------
 * Antes esta pantalla era el formulario genérico (makeForm) con TODOS los
 * campos puestos a `disabled`: cajas de texto grises que no se pueden tocar,
 * con etiquetas del CRM. Parecía un formulario roto, no la ficha de una
 * actividad — y encima no se enlazaba desde ningún sitio.
 *
 * Ahora es una ficha de verdad (cabecera + datos clave + descripción + CTA de
 * inscripción), que se pinta con sticpa_event_detail_html() (inc/stic-events.php),
 * el mismo formato que usan las tarjetas del listado.
 */

if (!defined('ABSPATH')) {
    exit;
}

$eventId = isset($_REQUEST['id']) ? sanitize_text_field($_REQUEST['id']) : '';

if ($eventId === '') {
    $html .= "<div class='stic-empty-state'>"
        . "<p class='stic-empty-title'>" . esc_html__('No hemos encontrado la actividad', 'sticpa') . "</p>"
        . "<p class='stic-empty-sub'>" . esc_html__('Puede que el enlace esté incompleto. Vuelve a Eventos y entra de nuevo.', 'sticpa') . "</p>"
        . "<a class='stic-rec-btn stic-rec-btn--ghost' href='?internalpage=list_stic_events'>" . esc_html__('Ver eventos', 'sticpa') . "</a>"
        . "</div>";
    return;
}

$detail = $objSCP->getRecordDetail($eventId, 'stic_Events');
$nvl = $detail->entry_list[0]->name_value_list ?? null;
$event = $nvl ? sticpa_event_view_model($nvl) : null;

if (!$event) {
    $html .= "<div class='stic-empty-state'>"
        . "<p class='stic-empty-title'>" . esc_html__('Esta actividad ya no está disponible', 'sticpa') . "</p>"
        . "<p class='stic-empty-sub'>" . esc_html__('Es posible que se haya retirado. Consulta el resto de actividades abiertas.', 'sticpa') . "</p>"
        . "<a class='stic-rec-btn stic-rec-btn--ghost' href='?internalpage=list_stic_events'>" . esc_html__('Ver eventos', 'sticpa') . "</a>"
        . "</div>";
    return;
}

// Etiqueta traducida del estado (el valor crudo es un código del CRM).
$statusLabel = '';
$statusDef = sticpa_cached_field_definition($objSCP, 'stic_Events', array('status'));
if (!empty($statusDef['status']['options'][$event['status']])) {
    $option = $statusDef['status']['options'][$event['status']];
    $statusLabel = is_array($option) ? ($option['value'] ?? '') : (string) $option;
}

// Si ya hay inscripción activa, la ficha lo dice en vez de ofrecer el botón
// (mismo criterio que el guard del formulario de inscripción).
$canSignUp = true;
if (function_exists('prefix_user_has_active_registration')) {
    $canSignUp = !prefix_user_has_active_registration($objSCP, $eventId);
}

$html .= sticpa_event_detail_html($event, $statusLabel, $canSignUp);
