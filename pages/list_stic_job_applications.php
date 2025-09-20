<?php

#########################################################
# List settings                                         #
#########################################################
switch (getDestinationModule()) {
    case 'Accounts':
        $relationship = 'stic_job_applications_contacts';
        $parentModule = 'Accounts';
        break;
    case 'Contacts':
        $relationship = 'stic_job_applications_contacts';
        $parentModule = 'Contacts';
        break;
}
$listSettings['moduleName'] = "stic_Job_Applications"; // list title
$listSettings['title'] = __('Job applications', 'sticpa'); // list title
$listSettings['actions'] = array(
    array('label' => __('Edit', 'sticpa'), 'link' => '?internalpage=single_stic_job_applications&action=edit'),
    array('label' => __('View', 'sticpa'), 'link' => '?internalpage=single_stic_job_applications&action=detail'),
    array('label' => __('Delete', 'sticpa'), 'link' => '?internalpage=single_stic_job_applications&action=delete'),
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
$columnsList[] = array('name' => 'status', 'format' => 'enum');
$columnsList[] = array('name' => 'stic_job_applications_stic_job_offers_name');
$columnsList[] = array('name' => 'start_date', 'format' => 'date');
$columnsList[] = array('name' => 'end_date', 'format' => 'date');
#########################################################

$fieldsToRetrieve = array_column($columnsList, 'name');

#########################################################
# Params for the API query to retrieve related beans
#########################################################
//set the params for the API query
$params = array(
    'module_name' => $parentModule,
    "module_id" => $_SESSION['scp_user_id'], //Do not touch
    "link_field_name" => $relationship,
    // "related_module_query" => "(end_date is null OR end_date >curdate())", //sql where conditions
    "related_fields" => $fieldsToRetrieve, //Do not touch
    "related_module_link_name_to_fields_array" => array(),
    "deleted" => 0, //show or not deleted elements (usually 0)
    "order_by" => "",
    "offset" => "",
    "limit" => 0,
);
#########################################################

$listSettings['fileName'] = basename(__FILE__, ".php"); //The list name, from the filename. Don't touch.
$getRelatedElements = $objSCP->getRelatedElementsForLoggedUser($params);

$html .= makeList($columnsList, $listSettings, $getRelatedElements);
