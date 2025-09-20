<?php

#########################################################
# List settings                                         #
#########################################################
switch (getDestinationModule()) {
    case 'Accounts':
        $relationship = 'stic_payments_accounts';
        $parentModule = 'Accounts';
        break;
    case 'Contacts':
        $relationship = 'stic_payments_contacts';
        $parentModule = 'Contacts';
        $templateId = '98ef1880-7cce-28c6-f824-636232379dbd';
        if ($templateId) {
            $hostUrl = get_option('sticpa_scp_host_url');
            $listSettings['additionalButtons'][] = array('label' => __('Donations certificate', 'sticpa'), 'link' => $hostUrl.'/index.php?entryPoint=sticGeneratePdf&task=pdf&module=Contacts&uid='.(isset($_SESSION['scp_tutor_user_id']) ? $_SESSION['scp_tutor_user_id'] : $_SESSION['scp_user_id']).'&templateID='.$templateId);
        }
        break;
}
$listSettings['moduleName'] = "stic_Payments"; // list title
$listSettings['title'] = __('Payments', 'sticpa'); // list title
$listSettings['linkDestination'] = '?internalpage=single_stic_payments&action=create'; //The link destination of each record in the list
$listSettings['actions'] = array(
    array('label' => __('Edit', 'sticpa'), 'link' => '?internalpage=single_stic_payments&action=edit'),
    array('label' => __('View', 'sticpa'), 'link' => '?internalpage=single_stic_payments&action=detail'),
    // array('label' => __('Delete', 'sticpa'), 'link' => '?internalpage=single_stic_payments&action=delete'),
);
$listSettings['datatables'] = array('value' => true, 'jsonSettings' => array( 'paging' =>false, 'searching' => true)); // if columns are sortable or filterable (this use jquery plugin datatables) /json Settings in json format from https://datatables.net/manual/options
$listSettings['msgDelete'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('Record successfully deleted.', 'sticpa')); //messages that will be shown on the screen after processing the data
$listSettings['fileName'] = basename(__FILE__, ".php"); //The list name, from the filename. Don't touch.


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
if (isset($_SESSION['scp_tutor_is_user']) && $_SESSION['scp_tutor_is_user']) {
    $columnsList[] = array(
        'name' => 'stic_payment_commitments_contacts_1_name',
        'label' => __('Recipient contact', 'sticpa'),
    );
}
$columnsList[] = array('name' => 'status', 'format' => 'enum');
$columnsList[] = array('name' => 'payment_type', 'format' => 'enum');
$columnsList[] = array('name' => 'amount', 'format' => 'currency');
$columnsList[] = array('name' => 'payment_method', 'format' => 'enum');
$columnsList[] = array('name' => 'bank_account');

#########################################################

$fieldsToRetrieve = array_column($columnsList, 'name');

#########################################################
# Params for the API query to retrieve related beans
#########################################################
//set the params for the API query
$availablePayments = array();
if ((isset($_SESSION['scp_tutor_is_user']) && $_SESSION['scp_tutor_is_user']) || isset($_SESSION['scp_user_adult']) && $_SESSION['scp_user_adult']) {
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

    $getRelatedElements = $objSCP->getRelatedElementsForLoggedUser($params);
    foreach($getRelatedElements as $key => $payment) {
        $params = array(
            'module_name' => 'stic_Payments',
            "module_id" => $payment->id, //Do not touch
            "link_field_name" => 'stic_payments_stic_payment_commitments',
            // "related_module_query" => "(end_date is null OR end_date >curdate())", //sql where conditions
            "related_fields" => array('stic_payment_commitments_contacts_1_name'), //Do not touch
            "related_module_link_name_to_fields_array" => array(),
            "deleted" => 0, //show or not deleted elements (usually 0)
            "order_by" => "",
            "offset" => "",
            "limit" => 0,
        );
        $getRelatedPC = $objSCP->getRelatedElementsForLoggedUser($params);
        $getRelatedElements[$key]->name_value_list->stic_payment_commitments_contacts_1_name = $getRelatedPC[0]->name_value_list->stic_payment_commitments_contacts_1_name;
    }
    $availablePayments = $getRelatedElements;
} else {
    $params = array(
        'module_name' => $parentModule,
        "module_id" => $_SESSION['scp_user_id'], //Do not touch
        "link_field_name" => 'stic_payment_commitments_contacts_1',
        "related_fields" => array('id'), //Do not touch
        "related_module_link_name_to_fields_array" => array(),
        "deleted" => 0, //show or not deleted elements (usually 0)
        "order_by" => "",
        "offset" => "",
        "limit" => 0,
    );

    $getRelatedElements = $objSCP->getRelatedElementsForLoggedUser($params);
    foreach($getRelatedElements as $key => $PC) {
        $params = array(
            'module_name' => 'stic_Payment_Commitments',
            "module_id" => $PC->id, //Do not touch
            "link_field_name" => 'stic_payments_stic_payment_commitments',
            // "related_module_query" => "(end_date is null OR end_date >curdate())", //sql where conditions
            "related_fields" => $fieldsToRetrieve, //Do not touch
            "related_module_link_name_to_fields_array" => array(),
            "deleted" => 0, //show or not deleted elements (usually 0)
            "order_by" => "",
            "offset" => "",
            "limit" => 0,
        );
        $getRelatedPayments = $objSCP->getRelatedElementsForLoggedUser($params);

        if (is_array($getRelatedPayments)) {
            foreach($getRelatedPayments as $payment) {
                $availablePayments[] = $payment;
            }
        }
        
    }
}

$html .= makeList($columnsList, $listSettings, $availablePayments);
