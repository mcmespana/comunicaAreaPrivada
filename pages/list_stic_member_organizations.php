<?php

#########################################################
# List settings                                         #
#########################################################
switch (getDestinationModule()) {
    case 'Accounts':
        $parentModule = 'Accounts';
        break;
    case 'Contacts':
        $parentModule = 'Contacts';
        break;
}
$listSettings['moduleName'] = "Accounts"; // list title
$listSettings['title'] = __('Member organizations', 'sticpa'); // list title
//$listSettings['linkDestination'] = '?internalpage=single_csme_member_organizations'; //The link destination of each record in the list
$listSettings['linkDestinationLabel'] = __('Edit', 'sticpa'); //The link destination label of each record in the list
$listSettings['actions'] = array(
    array('label' => __('View', 'sticpa'), 'link' => '?internalpage=single_stic_profile&action=detail'),
    array('label' => __('Edit', 'sticpa'), 'link' => '?internalpage=single_stic_profile&action=edit'),
    //array('label' => __('Request Cancellation', 'sticpa'), 'link' => '?internalpage=single_stic_profile&action=delete'),
    //array('label' => __('Verify', 'sticpa'), 'link' => '?internalpage=single_stic_profile&action=verify'),
);
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
// $columnsList[] = array('name' => 'peticion_baja_entidad_c', 'format' => 'enum'); 
// $columnsList[] = array('name' => 'entidad_registrada_desde_ap_c', 'format' => 'enum');
#########################################################

$fieldsToRetrieve = array_column($columnsList, 'name');

#########################################################
# Params for the API query to retrieve related beans
#########################################################
#########################################################
$id = $_SESSION['scp_user_id'];
$filterParam = "(accounts.parent_id = '$id')";
$fields = array_map(function ($elem) {
    return $elem['name'];
},$columnsList);

$listSettings['fileName'] = basename(__FILE__, ".php"); //The list name, from the filename. Don't touch.
$getElements = $objSCP->getRecordsModule($listSettings['moduleName'], $filterParam, $fields);
// debug($getElements, 'List');
$html .= makeList($columnsList, $listSettings, $getElements);
