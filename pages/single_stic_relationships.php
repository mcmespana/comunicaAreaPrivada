<?php

switch (getDestinationModule()) {
    case 'Accounts':
        $formSettings['moduleName'] = 'stic_Accounts_Relationships'; // module name, case sensitive
        $relationshipField = 'stic_accounts_relationships_accountsaccounts_ida';
        break;
    case 'Contacts':
        $formSettings['moduleName'] = 'stic_Contacts_Relationships'; // module name, case sensitive
        $relationshipField = 'stic_contacts_relationships_contactscontacts_ida';
        break;
}
$formSettings['action'] = $_REQUEST['action'];
$formSettings['title'] = __('Relationship with the organization', 'sticpa'); // form title
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('The record has been successfully saved.', 'sticpa')); //messages that will be shown on the screen after processing the data
$formSettings['fileName'] = basename(__FILE__, ".php"); //The page name, from the filename. Don't touch.

switch ($formSettings['action']) {
    case 'delete':
        $formSettings['submitButton'] = __('Delete', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonActions'] = array(
            'onclick' => 'confirmDelete',
        );
        break;
    case 'create':
    case 'edit':
        $formSettings['submitButton'] = __('Save', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonActions'] = array(
            'onclick' => 'verifyFormIsValid',
        );
        break;
    case 'detail':
        $formSettings['submitButton'] = __('Back', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        break;
    default:
        $formSettings['submitButton'] = __('Submit', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonActions'] = array(
            'onclick' => 'verifyFormIsValid',
        );
        break;
}
$formSettings['colClass'] = 'stic-form-one-col'; // set class to have one column form
#########################################################

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
$fieldList[] = array(
    'name' => 'id',
    'type' => 'hidden',
);
$fieldList[] = array(
    'name' => $relationshipField,
    'defaultValue' => $_SESSION['scp_user_id'],
    'value' => $_SESSION['scp_user_id'],
    'type' => 'hidden',
);
$fieldList[] = array('name' => 'name', 'required' => false);
$fieldList[] = array('name' => 'relationship_type');
$fieldList[] = array('name' => 'start_date');
$fieldList[] = array('name' => 'end_date', 'required' => false);

$data = $objSCP->getRecordDetail($_REQUEST['id'] ?? null, $formSettings['moduleName'])->entry_list[0]->name_value_list;

// If it's only detailview, disable fields
if ($_REQUEST['action'] == 'detail') {
    $fieldList = array_map(function($elem) {
        $elem['attributes'] = array('disabled' => 'disabled');
        $elem['required'] = false;
       return $elem;
    }, $fieldList);
}

$html .= makeForm($fieldList, $formSettings, $data, $formSettings['action']);
