<?php

switch (getDestinationModule()) {
    case 'Accounts':
        $formSettings['moduleName'] = 'Contacts'; // module name, case sensitive
        $relationshipField = 'accounts';
        break;
    case 'Contacts':
        $formSettings['moduleName'] = 'Accounts'; // module name, case sensitive
        $relationshipField = 'contacts';
        break;
}
$formSettings['action'] = $_REQUEST['action'];
$formSettings['title'] = __('Organization contact', 'sticpa'); // form title
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('The record has been successfully saved.', 'sticpa')); //messages that will be shown on the screen after processing the data

switch ($formSettings['action']) {
    case 'create':
    case 'edit':
        $formSettings['submitButton']['back'] = __('Back', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonType']['back'] = 'button';
        $formSettings['submitButtonActions']['back'] = array(
            'onclick' => "location.href='?internalpage=list_stic_contacts';",
            'class' => "stic-back-button",
        );
        $formSettings['submitButton']['save'] = __('Save', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonActions']['save'] = array(
            'onclick' => 'return verifyFormIsValid(this)',
        );
        $formSettings['attributes'] = 'enctype="multipart/form-data"';
        break;
    case 'detail':
        $formSettings['submitButton']['back'] = __('Back', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        // $formSettings['submitButtonType']['back'] = 'button';
        // $formSettings['submitButtonActions']['back'] = array(
        //     'onclick' => "location.href='?internalpage=list_stic_contacts';",
        //     'class' => "stic-back-button",
        // );
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
$fieldList[] = array('name' => 'assigned_user_id', 'type' => 'hidden', 'defaultValue' => $_SESSION['scp_user_id']);

switch ($formSettings['action']) {
    case 'create':
    case 'edit':
        $fieldList[] = array('name' => 'first_name', 'required' => true);
        $fieldList[] = array('name' => 'last_name', 'required' => true);
        break;
    default:
        $fieldList[] = array('name' => 'name', 'required' => false);
        break;
}

$fieldList[] = array('name' => 'title', 'required' => true);
$fieldList[] = array(
    'name' => 'email1',
    'required' => true,
    'attributes' => array(
        'pattern' => "^[a-zA-Z0-9.!#$%&’*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)+$",
    ),
);
$fieldList[] = array('name' => 'phone_mobile');
$fieldList[] = array('name' => 'sintic_janotreballa_c');

$data = $objSCP->getRecordDetail($_REQUEST['id'] ?? null, $formSettings['moduleName'])->entry_list[0]->name_value_list;
$formSettings['fileName'] = basename(__FILE__, ".php"); //The page name, from the filename. Don't touch.


// If it's only detailview, disable fields
if ($_REQUEST['action'] == 'detail') {
    $fieldList = array_map(function($elem) {
        $elem['attributes'] = array('disabled' => 'disabled');
        $elem['required'] = false;
       return $elem;
    }, $fieldList);
}

$html .= makeForm($fieldList, $formSettings, $data, $formSettings['action']);
