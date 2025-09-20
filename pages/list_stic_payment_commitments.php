<?php

#########################################################
# List settings                                         #
#########################################################
switch (getDestinationModule()) {
    case 'Accounts':
        $relationship = 'stic_payment_commitments_accounts';
        $parentModule = 'Accounts';
        break;
    case 'Contacts':
        $relationship = $_SESSION['scp_user_adult'] || $_SESSION['scp_tutor_is_user'] ? 'stic_payment_commitments_contacts' : 'stic_payment_commitments_contacts_1';
        $parentModule = 'Contacts';
        break;
}
$listSettings['moduleName'] = "stic_Payment_Commitments"; // list title
// $listSettings['title'] = $_SESSION['scp_tutor_is_user'] ? __('My payment commitments', 'sticpa') : __('Payment commitments from', 'sticpa').' '.$_SESSION['scp_user_contact_name']; 
$listSettings['title'] = __('Payment commitments', 'sticpa'); 
$listSettings['linkDestination'] = '?internalpage=single_stic_payment_commitments&action=create'; //The link destination of each record in the list
$listSettings['actions'] = array(
    array('label' => __('Edit', 'sticpa'), 'link' => '?internalpage=single_stic_payment_commitments&action=edit'),
    array('label' => __('View', 'sticpa'), 'link' => '?internalpage=single_stic_payment_commitments&action=detail'),
);
$listSettings['createButton'] = array('value' => true, 'label' => __('New payment commitment', 'sticpa')); // show create button and its label
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
if (isset($_SESSION['scp_tutor_is_user']) && $_SESSION['scp_tutor_is_user']) {$columnsList[] = array('name' => 'stic_payment_commitments_contacts_1_name');}
$columnsList[] = array('name' => 'payment_type', 'format' => 'enum');
$columnsList[] = array('name' => 'amount', 'format' => 'currency', 'defaultValue' => null);
$columnsList[] = array('name' => 'periodicity', 'format' => 'enum');
$columnsList[] = array('name' => 'payment_method', 'format' => 'enum');
$columnsList[] = array('name' => 'bank_account');
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