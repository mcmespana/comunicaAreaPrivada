<?php
/**
 * Display Sessions of the Events that the user is registered
 */

#########################################################
# List settings                                         #
#########################################################
switch (getDestinationModule()) {
    case 'Accounts':
        $relationship = 'stic_registrations_accounts';
        $parentModule = 'Accounts';
        break;
    case 'Contacts':
        $relationship = 'stic_registrations_contacts';
        $parentModule = 'Contacts';
        break;
}
$listSettings['moduleName'] = "stic_Sessions"; // list title
// NOTA: ya NO usa makeList(). Una sesión se leía en dos filas con el día
// repetido y los segundos ("Fecha inicio: 04-11-2025 17:30:00 / Fecha fin:
// 04-11-2025 19:00:00"). Ahora es una cápsula con el día y "17:30 – 19:00".
// Se pinta con sticpa_sessions_list_html() (inc/stic-sessions.php).
$listTitle = __('Mis sesiones', 'sticpa');

#########################################################
# Params for the API query to retrieve related beans
#########################################################
//set the params for the API query
// Getting first registrations

$params = array(
    'module_name' => $parentModule,
    "module_id" => $_SESSION['scp_user_id'], //Do not touch
    "link_field_name" => $relationship,
    // "related_module_query" => "(end_date is null OR end_date >curdate())", //sql where conditions
    "related_fields" => array('id'), //Do not touch
    "related_module_link_name_to_fields_array" => array(),
    "deleted" => 0, //show or not deleted elements (usually 0)
    "order_by" => "",
    "offset" => "",
    "limit" => 0,
);
#########################################################

$getRelatedRegistrations = $objSCP->getRelatedElementsForLoggedUser($params);
$availableSessions = array();
$sessionIds = array();
// Eventos ya consultados: varias inscripciones pueden apuntar al MISMO evento, y
// entonces se repetía la consulta de sus sesiones para acabar descartando el
// resultado duplicado más abajo (por $sessionIds). Se salta directamente.
$seenEventIds = array();
// is_array: el cliente del CRM devuelve null si la llamada falla o expira.
foreach((is_array($getRelatedRegistrations) ? $getRelatedRegistrations : array()) as $element) {
    $parentModule = 'stic_Registrations';
    $relationship = 'stic_registrations_stic_events';
    $params = array(
        'module_name' => $parentModule,
        "module_id" => $element->name_value_list->id->value, //Do not touch
        "link_field_name" => $relationship,
        // "related_module_query" => "(end_date is null OR end_date >curdate())", //sql where conditions
        "related_fields" => array('id'), //Do not touch
        "related_module_link_name_to_fields_array" => array(),
        "deleted" => 0, //show or not deleted elements (usually 0)
        "order_by" => "",
        "offset" => "",
        "limit" => 0,
    );
    
    $getRelatedEvents = $objSCP->getRelatedElementsForLoggedUser($params);

    foreach((is_array($getRelatedEvents) ? $getRelatedEvents : array()) as $element) {
        $eventId = $element->name_value_list->id->value ?? null;
        if (!$eventId || isset($seenEventIds[$eventId])) {
            continue;
        }
        $seenEventIds[$eventId] = true;
        $parentModule = 'stic_Events';
        $relationship = 'stic_sessions_stic_events';
        $params = array(
            'module_name' => $parentModule,
            "module_id" => $element->name_value_list->id->value, //Do not touch
            "link_field_name" => $relationship,
            // "related_module_query" => "(end_date is null OR end_date >curdate())", //sql where conditions
            "related_fields" => sticpa_session_list_fields(), //Do not touch
            "related_module_link_name_to_fields_array" => array(),
            "deleted" => 0, //show or not deleted elements (usually 0)
            "order_by" => "",
            "offset" => "",
            "limit" => 0,
        );
        $getRelatedSessions = $objSCP->getRelatedElementsForLoggedUser($params);
        foreach((is_array($getRelatedSessions) ? $getRelatedSessions : array()) as $session) {
            $data = $session->name_value_list;
            if (!in_array($data->id->value, $sessionIds)) {
                $availableSessions[] = $session;
                $sessionIds[] = $data->id->value;
            }
        }
    }
}
#########################################################

$html .= "<div class='stic-entry-header'><h3>" . esc_html($listTitle) . "</h3></div>";
$html .= sticpa_sessions_list_html($availableSessions);
