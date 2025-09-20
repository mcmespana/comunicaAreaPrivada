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
$listSettings['actions'] = array(
    array('label' => __('View', 'sticpa'), 'link' => '?internalpage=single_stic_events&action=detail'),
    array('label' => __('Register', 'sticpa'), 'link' => '?internalpage=single_stic_registrations&action=create&from=stic_events'),
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
$columnsList[] = array('name' => 'type', 'format' => 'enum');
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

$html .= makeList($columnsList, $listSettings, $getElements);
