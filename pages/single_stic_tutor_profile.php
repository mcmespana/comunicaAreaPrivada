<?php
#########################################################
# Form settings                                         #
#########################################################

switch (getDestinationModule()) {
    case 'Accounts':
        $formSettings['moduleName'] = 'Accounts'; // module name, case sensitive
        break;
    case 'Contacts':
        $formSettings['moduleName'] = 'Contacts'; // module name, case sensitive
        break;
}
$formSettings['title'] = __('User profile', 'sticpa'); // form title
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('The record has been successfully updated.', 'sticpa')); //Messages that will be shown on the screen after processing the data
$formSettings['msg'][] = array('value' => 'error', 'type' => 'error', 'msg' => __('Error saving the profile.', 'sticpa')); //Messages that will be shown on the screen after processing the data
$formSettings['submitButton']['save'] = __('Save', 'sticpa'); // submit button title. If not defined, it will be a read-only view
$formSettings['submitButtonActions']['save'] = array(
    'onclick' => 'return verifyProfileFormIsValid(this)',
);
$formSettings['fileName'] = basename(__FILE__, ".php"); //The list name, from the filename. Don't touch.

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

switch (getDestinationModule()) {
    case 'Accounts':
        $fieldList[] = array('name' => 'name',
            'actions' => array(
                'onchange' => 'alerta',
            ),
        );
        $fieldList[] = array(
            'name' => 'email1',
            'attributes' => array(
                'pattern' => "^[a-zA-Z0-9.!#$%&’*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)+$",
            ),
        );
        $fieldList[] = array(
            'name' => 'stic_identification_number_c',
            'actions' => array(
                'onchange' => 'validateId',
            ),
        );
        $fieldList[] = array('name' => 'phone_office');
        $fieldList[] = array('name' => 'website');

        break;
    case 'Contacts':
        $fieldList[] = array('name' => 'first_name',
        'required' => false,
        'actions' => array(
            'onchange' => 'alerta',
        ),
        'attributes' => array(     
            'disabled' => 'disabled',
         ),
    );
    $fieldList[] = array(
        'name' => 'last_name', 
        'required' => false,
        'attributes' => array(     
            'disabled' => 'disabled',
         ),
    );


    $fieldList[] = array(
        'name' => 'stic_identification_type_c',
    );
    $fieldList[] = array(
        'name' => 'stic_identification_number_c',
        'actions' => array(
            'onchange' => 'validateId',
        ),
    );

    $fieldList[] = array(
        'name' => 'stic_gender_c',
    );
    $fieldList[] = array(
        'name' => 'birthdate',
        'required' => false,
    );
    $fieldList[] = array(
        'name' => 'email1',
        'attributes' => array(
            'pattern' => "^[a-zA-Z0-9.!#$%&’*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)+$",
        ),
    );
    $fieldList[] = array(
        'name' => 'direccion',
        'type' => 'header',
        'label' => __('Address', 'sticpa'),
        'classes' => 'stic-subheader-profile',
    );
    $fieldList[] = array('name' => 'primary_address_street');
    $fieldList[] = array('name' => 'primary_address_city');
    $fieldList[] = array('name' => 'primary_address_state');
    $fieldList[] = array('name' => 'primary_address_postalcode');
    
    break;
}

$data = $objSCP->getRecordDetail($_SESSION['scp_tutor_user_id'], $formSettings['moduleName'])->entry_list[0]->name_value_list;

$html .= makeForm($fieldList, $formSettings, $data);
