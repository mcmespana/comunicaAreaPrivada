<?php

switch (getDestinationModule()) {
    case 'Accounts':
        $relationshipField = 'stic_payment_commitments_accountsaccounts_ida';
        break;
    case 'Contacts':
        $relationshipField = 'stic_payment_commitments_contactscontacts_ida';
        break;
}

$formSettings['action'] = $_REQUEST['action'];
$formSettings['title'] = __('Payment commitments', 'sticpa'); 
// $formSettings['title'] = $_SESSION['scp_tutor_is_user'] ? __('My payment commitment', 'sticpa') : __('Payment Commitments from', 'sticpa').' '.$_SESSION['scp_user_contact_name']; 
$tutorIsUser = isset($_SESSION['scp_tutor_is_user']) ?? false;
$formSettings['moduleName'] = 'stic_Payment_Commitments'; // module name, case sensitive
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('The record has been successfully saved.', 'sticpa')); //messages that will be shown on the screen after processing the data

switch ($_REQUEST['action']) {
    case 'delete':
        $formSettings['submitButton'] = __('Delete', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonActions'] = array(
            'onclick' => 'confirmDelete',
        );
        break;
    case 'create':
    case 'edit':
        $formSettings['submitButton']['back'] = __('Back', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonType']['back'] = 'button';
        $formSettings['submitButtonActions']['back'] = array(
            'onclick' => "location.href='?internalpage=list_stic_payment_commitments';",
        );
        $formSettings['submitButton']['save'] = __('Save', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonActions']['save'] = array(
            'onclick' => 'return verifyFormIsValid(this)',
        );
        break;
    case 'detail':
        $formSettings['submitButton']['back'] = __('Back', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        // $formSettings['submitButton']['delete'] = __('Delete', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        // $formSettings['submitButtonActions']['delete'] = array(
        //     'onclick' => 'confirmDelete(this)',
        // );
        break;
        break;
    default:
        $formSettings['submitButton'] = __('Submit', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonActions'] = array(
            'onclick' => 'verifyFormIsValid',
        );
        break;
}
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
$fieldList[] = array('name' => 'id', 'type' => 'hidden');
if (isset($_SESSION['scp_tutor_is_user'])) {
    $fieldList[] = array(
        'name' => $relationshipField,
        'type' => 'hidden',
        'defaultValue' => $_SESSION['scp_tutor_user_id'],
        'value' => $_SESSION['scp_tutor_user_id'],
    );
    $fieldList[] = array(
        'name' => 'stic_payment_commitments_contacts_1contacts_ida',
        'type' => 'hidden',
        'defaultValue' => $_SESSION['scp_user_id'],
        'value' => $_SESSION['scp_user_id'],
    );
} else {
    $fieldList[] = array(
        'name' => $relationshipField,
        'type' => 'hidden',
        'defaultValue' => $_SESSION['scp_user_id'],
        'value' => $_SESSION['scp_user_id'],
    );
}


if ($_REQUEST['action'] == 'detail') {
    $fieldList[] = array('name' => 'name');
    if (isset($_SESSION['scp_tutor_is_user']) && $_SESSION['scp_tutor_is_user']) {
        $fieldList[] = array(
            'name' => 'stic_payment_commitments_contacts_1_name',
            'type' => 'varchar',
        );
    }

}
$fieldList[] = array('name' => 'payment_type');
$fieldList[] = array('name' => 'amount', 'format' => 'currency', 'defaultValue' => '');
$fieldList[] = array('name' => 'periodicity');
$fieldList[] = array(
    'name' => 'payment_method',
    'actions' => array(
        'onchange' => 'handlePaymentMethod',
    ),
);
$fieldList[] = array(
    'name' => 'bank_account',
    'actions' => array(
        'onchange' => 'verifyIban',
    ),
);

$data = $objSCP->getRecordDetail($_REQUEST['id'] ?? null, $formSettings['moduleName'])->entry_list[0]->name_value_list;

$formSettings['fileName'] = basename(__FILE__, ".php"); //The page name, from the filename. Don't touch.

// If it's only detailview, disable fields
if ($_REQUEST['action'] == 'detail') {
    // $fieldList = array();
    $fieldList = array_map(function($elem) {
        $elem['attributes'] = array('disabled' => 'disabled');
        $elem['required'] = false;
       return $elem;
    }, $fieldList);
}


$html .= makeForm($fieldList, $formSettings, $data, $formSettings['action']);

$html.= '
<script>
document.addEventListener("DOMContentLoaded", function(event) { 
    handlePaymentMethod(this);
});
</script>';
