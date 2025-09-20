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
$listSettings['title'] = __('Attendances', 'sticpa'); // list title
$listSettings['linkDestination'] = '?internalpage=single_stic_attendances&action=create'; //The link destination of each record in the list
$listSettings['actions'] = array(
    // array('label' => __('Edit', 'sticpa'), 'link' => '?internalpage=single_stic_attendances&action=edit'),
    array('label' => __('View', 'sticpa'), 'link' => '?internalpage=single_stic_attendances&action=detail'),
    // array('label' => __('Delete', 'sticpa'), 'link' => '?internalpage=single_stic_attendances&action=delete'),
);
// $listSettings['createButton'] = array('value' => true, 'label' => __('New registration', 'sticpa')); // show create button and its label
$listSettings['datatables'] = array('value' => true, 'jsonSettings' => array( 'paging' =>false, 'searching' => true)); // if columns are sortable or filterable (this use jquery plugin datatables) /json Settings in json format from https://datatables.net/manual/options
$listSettings['msgDelete'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('Record successfully deleted.', 'sticpa')); //messages that will be shown on the screen after processing the data

#########################################################
# Columns list
# Important: Include id field for update operations.
# The field definition will be retrieved from the CRM. But it can also be specified like this:
# $columnsList[] = array(
#    'name' => '<field_name>',
#    'label' => __('<field_label>', 'sticpa'),
#    'format' => '<format_type>',   # currency, number, date... if "translate" it will transalate the value to a label
#    'attributes' => array ()
# "');
#
#########################################################
$columnsList[] = array('name' => 'id');
$columnsList[] = array('name' => 'name');
$columnsList[] = array('name' => 'stic_attendances_stic_registrations_name');
$columnsList[] = array('name' => 'stic_attendances_stic_sessions_name');
$columnsList[] = array('name' => 'status', 'format' => 'enum');
$columnsList[] = array('name' => 'start_date', 'format' => 'datetime');
$columnsList[] = array('name' => 'duration');
#########################################################

$fieldsToRetrieve = array_column($columnsList, 'name');
$listSettings['fileName'] = basename(__FILE__, ".php"); //The list name, from the filename. Don't touch.

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
foreach($getRelatedRegistrations as $element) {
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
    // debug($getAttendances, 'wek');
    if (is_array($getAttendances)) {
        foreach($getAttendances as $attendance) {
            $availableAttendances[] = $attendance;
        }
    }
}

$html .= makeList($columnsList, $listSettings, $availableAttendances);
