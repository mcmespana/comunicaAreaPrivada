<?php

switch (getDestinationModule()) {
    case 'Accounts':
        // $relationshipField = 'stic_payment_commitments_accountsaccounts_ida';
        break;
    case 'Contacts':
        // $relationshipField = 'stic_payment_commitments_contactscontacts_ida';
        break;
}
$formSettings['title'] = __('Job offer', 'sticpa'); // form title
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('The record has been successfully saved.', 'sticpa')); //messages that will be shown on the screen after processing the data
$formSettings['moduleName'] = 'stic_Job_Offers'; // module name, case sensitive

$formSettings['submitButton']['back'] = __('Back', 'sticpa'); // submit button title. If not defined, it will be a read-only view
$formSettings['submitButtonType']['back'] = 'button';
$formSettings['submitButtonActions']['back'] = array(
    'onclick' => "location.href='?internalpage=list_stic_job_offers';",
);
$formSettings['submitButton']['register'] = __('Register', 'sticpa'); // submit button title. If not defined, it will be a read-only view

#########################################################
# Field list included in the form. Their definition is retrieved by default from the CRM.
# Important: Include id field for update operations.
# Usage: Fields can be defined in this way:
# $fieldList[] = array(
#     'name' => '<field_name>',       # Required
#     'label' => __('<field_label>', 'sticpa'), # Optional if you want to change the label from the CRM
#     'type' => '<field_type>',       # It can be: select, text, hidden,...
#     'required' => <true/false>,     # There is an error in SuiteCRM API code that doesn't return correctly if a field is required. https://github.com/SinergiaTIC/SinergiaCRM-SuiteCRM/issues/524
#     'defaultValue' => '<value>'     # Optional
#     'attributes' => array(          # Optional
#       'disabled' => 'disabled',
#     ),
#     'selectValues' => array(
#         ' ' => ' ',
#         '<item_name>' => __('<item_name>', 'sticpa'),
#         '<item_name>' => __('<item_name>', 'sticpa'),
#         '<item_name>' => __('<item_name>', 'sticpa'),
#     ),
# );
# IF only the name property is specified, the rest of the definition will be filled with the CRM field definition
#########################################################
$fieldList[] = array('name' => 'id', 'type' => 'hidden');
$fieldList[] = array('name' => 'name');
$fieldList[] = array('name' => 'stic_job_offers_accounts_name', 'type' => 'text');
$fieldList[] = array('name' => 'status');
$fieldList[] = array('name' => 'offered_positions');
$fieldList[] = array('name' => 'offer_code');
$fieldList[] = array('name' => 'applications_start_date', 'type' => 'date');
$fieldList[] = array('name' => 'applications_end_date', 'type' => 'date');

$fieldsToRetrieve = array_column($fieldList, 'name');

#########################################################
# $data must have the data to populate the form
# You must write the function to retrieve $data in stic-action.php and then call it from here
#########################################################
$data = $objSCP->getRecordDetail($_REQUEST['id'], $formSettings['moduleName'], $fieldsToRetrieve)->entry_list[0]->name_value_list;
#########################################################

$formSettings['fileName'] = basename(__FILE__, ".php"); //The page name, from the filename. Don't touch.

// If it's only detailview, disable fields
if ($_REQUEST['action'] == 'detail') {
    $newFieldList = array();
    $newFieldList = array_map(function($elem) {
        $elem['attributes'] = array('disabled' => 'disabled');
        $elem['required'] = false;
       return $elem;
    }, $fieldList);
}

$html .= makeForm($newFieldList, $formSettings, $data);
