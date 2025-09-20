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
$listSettings['moduleName'] = "stic_Job_Offers"; // list title
$listSettings['title'] = __('Job offers', 'sticpa'); // list title
$listSettings['linkDestination'] = '?internalpage=single_stic_job_offers'; //The link destination of each record in the list
$listSettings['linkDestinationLabel'] = __('Edit', 'sticpa'); //The link destination label of each record in the list
$listSettings['actions'] = array(
    array('label' => __('View', 'sticpa'), 'link' => '?internalpage=single_stic_job_offers&action=detail'),
    array('label' => __('Register', 'sticpa'), 'link' => '?internalpage=single_stic_job_applications&action=create&from=stic_job_offers'),
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
$columnsList[] = array('name' => 'stic_job_offers_accounts_name');
$relationshipFields['stic_job_offers_accounts_name'] = array('fields' => array ('name'), 'relationshipName' => 'stic_job_offers_accounts');
$columnsList[] = array('name' => 'status', 'format' => 'enum');
$columnsList[] = array('name' => 'offered_positions');
$columnsList[] = array('name' => 'offer_code');
$columnsList[] = array('name' => 'applications_start_date', 'type' => 'date');
$columnsList[] = array('name' => 'applications_end_date', 'type' => 'date');
#########################################################

$fieldsToRetrieve = array_column($columnsList, 'name');

#########################################################
# Params for the API query to retrieve related beans
#########################################################
#########################################################
$filterParam = "(stic_job_offers.status = 'open' OR stic_job_offers.status = 'reopened')";
$fields = array_map(function ($elem) {
    return $elem['name'];
},$columnsList);

$listSettings['fileName'] = basename(__FILE__, ".php"); //The list name, from the filename. Don't touch.
$getElements = $objSCP->getRecordsModule($listSettings['moduleName'], $filterParam, $fields, $relationshipFields);
// debug($getElements, 'List');
$html .= makeList($columnsList, $listSettings, $getElements);
