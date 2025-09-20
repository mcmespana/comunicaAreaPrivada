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
$relationship = 'documents';
$listSettings['moduleName'] = 'Documents'; // list title
$listSettings['title'] = __('Documents', 'sticpa'); // list title
$listSettings['linkDestination'] = '?internalpage=single_stic_documents&action=create'; //page must be the basename of the file to proccess (without extension)
$listSettings['actions'] = array(
    array('label' => __('Edit', 'sticpa'), 'link' => '?internalpage=single_stic_documents&action=edit'),
    array('label' => __('View', 'sticpa'), 'link' => '?internalpage=single_stic_documents&action=detail'),
    // array('label' => __('Download', 'sticpa'), 'link' => '?internalpage=single_stic_documents&action=detail&download=true'),     
);
// $listSettings['action'] = 'download';
$listSettings['createButton'] = array('value' => true, 'label' => __('New document', 'sticpa')); // show create button and its label. If not defined, there will be no create button
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
#########################################################
$columnsList[] = array('name' => 'id');
$columnsList[] = array('name' => 'document_name');
$columnsList[] = array('name' => 'filename');
$columnsList[] = array('name' => 'status_id', 'format' => 'enum');
$columnsList[] = array('name' => 'active_date', 'format' => 'date');
$columnsList[] = array('name' => 'exp_date', 'format' => 'date');
#########################################################

$fieldsToRetrieve = array_column($columnsList, 'name'); //do not touch

#########################################################
# Params for the API query to retrieve related beans
#########################################################
//set the params for the API query
$params = array(
    'module_name' => $parentModule,
    "module_id" => $_SESSION['scp_user_id'], //Do not touch
    "link_field_name" => $relationship,
    "related_module_query" => "", //sql where conditions Attention, not all sql run ok
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
