<?php

#########################################################
# List settings                                         #
#########################################################
switch (getDestinationModule()) {
    case 'Accounts':
        // $relationship = 'stic_payment_commitments_accounts';
        $parentModule = 'Accounts';
        break;
    case 'Contacts':
        // $relationship = 'stic_payment_commitments_contacts';
        $parentModule = 'Contacts';
        break;
}
$listSettings['moduleName'] = "stic_Events"; // list title
$listSettings['title'] = __('Eventos', 'sticpa'); // list title
// NOTA: este listado ya NO usa makeList() ni DataTables. Un evento no se lee
// bien como "ETIQUETA: valor" (Estado / Fecha inicio / Fecha fin ocupaban tres
// filas para decir cuándo es), así que se pinta con tarjetas propias —
// sticpa_events_list_html() en inc/stic-events.php.

#########################################################

#########################################################
# Campos que se piden al CRM.
# Los OPCIONALES (lugar, plazas, precio…) se piden solo si están declarados en
# sticpa_event_optional_fields(): si no existen en SinergiaCRM, simplemente no
# vienen y la tarjeta no los pinta. Así, crear el campo en el CRM basta para
# que aparezca aquí (ver docs/comunica/EVENTOS.md).
#########################################################
$fields = sticpa_event_fields_to_request($objSCP);

// Ventana temporal compartida con el calendario (-3 … +12 meses por defecto).
// Antes aquí no había filtro: se descargaba el histórico ENTERO de eventos del
// CRM para mostrar los pocos a los que uno puede apuntarse.
$filterParam = function_exists('sticpa_events_window_filter') ? sticpa_events_window_filter() : '';
$listSettings['fileName'] = basename(__FILE__, ".php"); //The list name, from the filename. Don't touch.

// EN UNA TANDA (plan 042, VEL-5): los eventos de la ventana y tus
// inscripciones no dependen unos de otras; iban en fila. Con el calendario
// frío, 3 esperas → 2 (la segunda, la tanda del evento de cada inscripción).
// Los cargadores son los mismos de abajo, que encuentran su respuesta ya
// traída; con el calendario caliente tus inscripciones no recolectan nada y
// la consulta de eventos sale sola, como antes.
if (function_exists('sticpa_pl_prime') && function_exists('prefix_user_active_registration_map')) {
    sticpa_pl_prime($objSCP, function () use ($objSCP, $listSettings, $filterParam, $fields) {
        $objSCP->getRecordsModule($listSettings['moduleName'], $filterParam, $fields);
        prefix_user_active_registration_map($objSCP);
    });
}
$getElements = $objSCP->getRecordsModule($listSettings['moduleName'], $filterParam, $fields);

// TUS INSCRIPCIONES (evento => inscripción). Van ANTES de la audiencia: a lo
// que ya te has apuntado no se le aplica el filtro —quien tiene su plaza la ve
// aunque hoy no lo cumpla (le han cambiado el curso…)—, igual que en la ficha.
// Antes estos eventos se QUITABAN de aquí al inscribirte; ahora salen arriba,
// en «Te has apuntado», hasta que pasan (ver sticpa_events_list_html()).
$mine = function_exists('prefix_user_active_registration_map') ? prefix_user_active_registration_map($objSCP) : array();

// AUDIENCIA: fuera los eventos que no son para quien mira (otra delegación,
// otro perfil, otro curso escolar).
//
// OJO, esto no lo hace el CRM por nosotros: el área privada se conecta con UN
// usuario técnico, así que los grupos de seguridad no filtran nada de lo que
// se lee aquí. Ver inc/stic-event-audience.php.
if (is_array($getElements) && function_exists('sticpa_filter_events_for_viewer')) {
    $mias = array();
    $resto = array();
    foreach ($getElements as $ev) {
        $evId = (string) ($ev->name_value_list->id->value ?? '');
        if ($evId !== '' && isset($mine[$evId])) {
            $mias[] = $ev;
        } else {
            $resto[] = $ev;
        }
    }
    $getElements = array_merge($mias, sticpa_filter_events_for_viewer($objSCP, $resto));
}

// Etiquetas del desplegable `status` tal y como están traducidas en el CRM
// (el valor crudo es un código tipo "Planned", que no se le enseña a nadie).
$statusMap = array();
// De la definición COMPARTIDA de eventos, no de una lista propia: pedir aquí
// `array('status')` era otra clave de caché y por tanto otro viaje al CRM,
// cuando la definición que ya se ha traído para saber qué campos existen trae
// `status` dentro (es un campo base). Ver sticpa_event_field_definition().
$statusDef = sticpa_event_field_definition($objSCP);
if (!empty($statusDef['status']['options']) && is_array($statusDef['status']['options'])) {
    foreach ($statusDef['status']['options'] as $key => $option) {
        $statusMap[$key] = is_array($option) ? ($option['value'] ?? '') : (string) $option;
    }
}

$html .= renderDeleteMessage($listSettings['msgDelete'] ?? array());
$html .= "<div class='stic-entry-header'><h3>" . esc_html($listSettings['title']) . "</h3></div>";
$html .= sticpa_events_list_html($getElements, $statusMap, $mine);
