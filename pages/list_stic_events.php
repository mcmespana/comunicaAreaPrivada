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

$filterParam = '';
$listSettings['fileName'] = basename(__FILE__, ".php"); //The list name, from the filename. Don't touch.
$getElements = $objSCP->getRecordsModule($listSettings['moduleName'], $filterParam, $fields);

// Ocultamos de "Eventos disponibles" los que el usuario YA tiene inscritos
// (siguen visibles en "Inscripciones"). Evita ofrecer "Inscribirse" a algo ya hecho.
if (is_array($getElements) && function_exists('prefix_user_active_event_ids')) {
    $registeredIds = prefix_user_active_event_ids($objSCP);
    if (!empty($registeredIds)) {
        $getElements = array_values(array_filter($getElements, function ($ev) use ($registeredIds) {
            $evId = $ev->name_value_list->id->value ?? null;
            return $evId === null || !in_array($evId, $registeredIds, true);
        }));
    }
}

// Etiquetas del desplegable `status` tal y como están traducidas en el CRM
// (el valor crudo es un código tipo "Planned", que no se le enseña a nadie).
$statusMap = array();
$statusDef = sticpa_cached_field_definition($objSCP, $listSettings['moduleName'], array('status'));
if (!empty($statusDef['status']['options']) && is_array($statusDef['status']['options'])) {
    foreach ($statusDef['status']['options'] as $key => $option) {
        $statusMap[$key] = is_array($option) ? ($option['value'] ?? '') : (string) $option;
    }
}

$html .= renderDeleteMessage($listSettings['msgDelete'] ?? array());
$html .= "<div class='stic-entry-header'><h3>" . esc_html($listSettings['title']) . "</h3></div>";
$html .= sticpa_events_list_html($getElements, $statusMap);
