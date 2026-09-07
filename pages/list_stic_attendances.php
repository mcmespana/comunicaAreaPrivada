<?php

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
$listSettings['moduleName'] = "stic_Attendances"; // list title
// NOTA: ya NO usa makeList(). `duration` se pintaba en crudo (un decimal:
// "1.5") y el estado como texto suelto. Ahora es "1 h 30 min" y un chip con su
// color. Se pinta con sticpa_attendances_list_html() (inc/stic-sessions.php).
$listTitle = __('Mis asistencias', 'sticpa');
$fieldsToRetrieve = sticpa_attendance_list_fields();

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
$availableAttendances = array();
// is_array: el cliente del CRM devuelve null si la llamada falla o expira.
foreach((is_array($getRelatedRegistrations) ? $getRelatedRegistrations : array()) as $element) {
    $params = array(
        'module_name' => 'stic_Registrations',
        "module_id" => $element->name_value_list->id->value, //Do not touch
        "link_field_name" => 'stic_attendances_stic_registrations',
        // "related_module_query" => "(stic_personal_environment.relationship_type = 'father' OR 
        //     stic_personal_environment.relationship_type = 'mother' OR stic_personal_environment.relationship_type = 'legal')",
        "related_fields" => $fieldsToRetrieve,
        // 'link_name_to_fields_array' => array(
        "related_module_link_name_to_fields_array" => array(),
        "deleted" => 0, //show or not deleted elements (usually 0)
        "order_by" => "",
        "offset" => "",
        "limit" => 0,
    );
    $getAttendances = $objSCP->getRelatedElementsForLoggedUser($params);
    if (is_array($getAttendances)) {
        foreach($getAttendances as $attendance) {
            $availableAttendances[] = $attendance;
        }
    }
}

// Etiqueta traducida del estado (definición cacheada 6h).
$definition = sticpa_cached_field_definition($objSCP, 'stic_Attendances', array('status'));

$html .= "<div class='stic-entry-header'><h3>" . esc_html($listTitle) . "</h3></div>";
$html .= sticpa_attendances_list_html($availableAttendances, $definition);
