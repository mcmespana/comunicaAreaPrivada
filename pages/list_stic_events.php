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
$listSettings['title'] = __('Events', 'sticpa'); // list title
// "Inscribirse" lleva a una pantalla única: info del evento + formulario de inscripción.
$listSettings['actions'] = array(
    array('label' => __('Inscribirse', 'sticpa'), 'link' => '?internalpage=single_stic_registrations&action=create&from=stic_events'),
);
$listSettings['datatables'] = array('value' => true, 'jsonSettings' => array( 'paging' =>false, 'searching' => true)); // if columns are sortable or filterable (this use jquery plugin datatables) /json Settings in json format from https://datatables.net/manual/options
$listSettings['msgDelete'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('Record successfully deleted.', 'sticpa')); //messages that will be shown on the screen after processing the data

#########################################################

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
$columnsList[] = array('name' => 'status', 'format' => 'enum');
$columnsList[] = array('name' => 'start_date', 'format' => 'date');
$columnsList[] = array('name' => 'end_date', 'format' => 'date');
#########################################################

$fieldsToRetrieve = array_column($columnsList, 'name');

#########################################################
# Params for the API query to retrieve related beans
#########################################################
#########################################################
// $filterParam = "(end_date is null OR end_date >curdate())";
$fields = array_map(function ($elem) {
    return $elem['name'];
},$columnsList);
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
if (empty($getElements)) {
    $getElements = null; // fuerza el estado vacío con estilo
}

$html .= makeList($columnsList, $listSettings, $getElements);
