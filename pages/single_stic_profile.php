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
$formSettings['title'] = __('Profile', 'sticpa'); // form title
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('The record has been successfully updated.', 'sticpa')); //Messages that will be shown on the screen after processing the data
$formSettings['msg'][] = array('value' => 'error', 'type' => 'error', 'msg' => __('Error saving the profile.', 'sticpa')); //Messages that will be shown on the screen after processing the data
$formSettings['msg'][] = array('value' => 'error_type', 'type' => 'error', 'msg' => __("File format is not valid.", 'sticpa')); //Messages that will be shown on the screen after processing the data
$formSettings['msg'][] = array('value' => 'error_size', 'type' => 'error', 'msg' => __("File size is too large.", 'sticpa')); //Messages that will be shown on the screen after processing the data
$formSettings['msg'][] = array('value' => 'error_upload', 'type' => 'error', 'msg' => __('Error loading the picture.', 'sticpa')); //Messages that will be shown on the screen after processing the data
$formSettings['action'] = $_REQUEST['action'];
$moduleData['stic-action'] = $_REQUEST['action'];
if (isset($formSettings['action']) && $formSettings['action'] !== '') {

    switch ($_REQUEST['action']) {
        case 'edit':
            $formSettings['submitButton']['back'] = __('Back', 'sticpa'); // submit button title. If not defined, it will be a read-only view
            $formSettings['submitButtonType']['back'] = 'button';
            $formSettings['submitButtonActions']['back'] = array(
                'onclick' => "location.href='?internalpage=list_stic_member_organizations';",
            );
            $formSettings['submitButton']['save'] = __('Save', 'sticpa'); // submit button title. If not defined, it will be a read-only view
            $formSettings['submitButtonActions']['save'] = array(
                'onclick' => 'return verifyFormIsValid(this)',
            );
            break;
        case 'detail':
            $formSettings['submitButton']['back'] = __('Back', 'sticpa'); // submit button title. If not defined, it will be a read-only view
            $formSettings['submitButtonType']['back'] = 'button';
            $formSettings['submitButtonActions']['back'] = array(
                'onclick' => "location.href='?internalpage=list_stic_member_organizations';",
                'class' => "stic-back-button",
            );
            break;
    }
} else {
    $formSettings['submitButton']['save'] = __('Save', 'sticpa'); // submit button title. If not defined, it will be a read-only view
    $formSettings['submitButtonActions']['save'] = array(
        'onclick' => 'return verifyProfileFormIsValid(this)',
    );
}
$formSettings['attributes'] = 'enctype="multipart/form-data"';

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

#########################################################
# $data must have the data to populate the form
#########################################################
//To work with the List Members function
$id = $_REQUEST['id'] ?? $_SESSION['scp_user_id'];
$data = $objSCP->getRecordDetail($id, $formSettings['moduleName'])->entry_list[0]->name_value_list;
$fieldList[] = array('name' => 'id', 'type' => 'hidden');

switch (getDestinationModule()) {
    case 'Accounts':
        $fieldList[] = array(
            'name' => 'name',
            'attributes' => array(     
                'disabled' => 'disabled',
             ),
        );
        $fieldList[] = array(
            'name' => 'email1',
            'attributes' => array(
                'pattern' => "^[a-zA-Z0-9.!#$%&’*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)+$",
            ),
        );
        $fieldList[] = array(
            'name' => 'stic_identification_type_c',
            'type' => 'html',
            'value' => 'cif',
        );
        $fieldList[] = array(
          'name' => 'stic_identification_type_c', 
          'type' => 'html', 
          'html' => '<span><input type="hidden" name="stic_identification_type_c" id="stic_identification_type_c" value="cif"></span>'
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
            'name' => 'birthdate',
            'required' => false,
        );
        $fieldList[] = array(
            'name' => 'email1',
            'attributes' => array(
                'pattern' => "^[a-zA-Z0-9.!#$%&’*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)+$",
            ),
        );
        $fieldList[] = array('name' => 'phone_mobile');
        $fieldList[] = array(
            'name' => 'stic_gender_c',
            'required' => false,
        );
       
        if ($filename = $data->photo->value) {
            $image = $objSCP->get_image(array('id' => $_SESSION['scp_user_id'],'field' => 'photo'));
            $content = $image->image_data->data;
            $mime_type = $image->image_data->mime_type;
            $fieldList[] = array(
                'name' => 'photo', 
                'type' => 'html', 
                'required' => true,
                'html' => '
                    <li>
                        <img class="stic-profile-picture" src="data:'.$mime_type.'/png;base64, '.$content.'"/>
                        <label style="padding-botton: 0px">'.__('Change profile picture', 'sticpa').':</label>
                        <div style="font-size: 10px">'.__('(Format: jpg, jpeg, gif, png - Maximum size: 6MB)', 'sticpa').'</div>
                        <span><input type="file" name="photo" id="photo" value="'.$filename.'"></span>
                    </li>'
            );
        } else {
            $fieldList[] = array(
                'name' => 'photo', 
                'type' => 'html', 
                'required' => true,
                'html' => '
                    <li>
                        <img class="stic-profile-picture" src="'.plugins_url('../images/profile_picture.jpg', __FILE__).'"/>
                        <label style="padding-botton: 0px">'.__('Choose a file', 'sticpa').':</label>
                        <div style="font-size: 10px">'.__('(Format: jpg, jpeg, gif, png - Maximum size: 6MB)', 'sticpa').'</div>
                        <span><input type="file" name="photo" id="photo" value=></span>
                    </li>'
            );
        }
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
#########################################################


#########################################################
$formSettings['fileName'] = basename(__FILE__, ".php"); //The list name, from the filename. Don't touch.

// If it's only detailview, disable fields
if ($_REQUEST['action'] == 'detail') {
    $fieldList = array_map(function($elem) {
        $elem['attributes'] = array('disabled' => 'disabled');
        $elem['required'] = false;
       return $elem;
    }, $fieldList);
}
$html .= makeForm($fieldList, $formSettings, $data);
