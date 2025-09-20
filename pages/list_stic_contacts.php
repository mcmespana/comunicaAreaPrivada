<?php

#########################################################
# List settings                                         #
#########################################################
switch (getDestinationModule()) {
    case 'Accounts':
        $listSettings['moduleName'] = 'stic_Contacts_Relationships'; // module name, case sensitive
        $relationship = 'contacts';
        $parentModule = 'Accounts';
        break;
    case 'Contacts':
        $listSettings['moduleName'] = 'stic_Contacts_Relationships'; // list title
        $relationship = 'stic_contacts_relationships_contacts';
        $parentModule = 'Contacts';
        break;
}
$listSettings['title'] = __('Organization contacts', 'sticpa'); // list title
$listSettings['linkDestination'] = '?internalpage=single_stic_contacts&action=create'; //The link destination of each record in the list
$listSettings['actions'] = array(
    array('label' => __('Edit', 'sticpa'), 'link' => '?internalpage=single_stic_contacts&action=edit'),
    array('label' => __('View', 'sticpa'), 'link' => '?internalpage=single_stic_contacts&action=detail'),
    // array('label' => __('Delete', 'sticpa'), 'link' => '?internalpage=single_stic_relationships&action=delete'),
);
$listSettings['createButton'] = array('value' => true, 'label' => __('New contact', 'sticpa')); // show create button and its label
$listSettings['datatables'] = array('value' => true, 'jsonSettings' => array('paging' =>false, 'searching' => false)); // if columns are sortable or filterable (this use jquery plugin datatables) /json Settings in json format from https://datatables.net/manual/options
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
$columnsList[] = array('name' => 'name');
$columnsList[] = array('name' => 'title', 'label' => __('Title'));
$columnsList[] = array('name' => 'email1', 'label' => __('Email'));
$columnsList[] = array('name' => 'phone_mobile', 'label' => __('Phone'));
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
