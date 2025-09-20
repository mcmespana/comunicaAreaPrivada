<?php

$formSettings['title'] = __('Sign Up', 'sticpa');
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('The record has been successfully updated.', 'sticpa')); //Messages that will be shown on the screen after processing the data
$formSettings['msg'][] = array('value' => 'userandemailexists', 'type' => 'error', 'msg' => __('Username and email address already exist.', 'sticpa')); //Messages that will be shown on the screen after processing the data
$formSettings['msg'][] = array('value' => 'emailexists', 'type' => 'error', 'msg' => __('Email address already exists.', 'sticpa')); //Messages that will be shown on the screen after processing the data
$formSettings['msg'][] = array('value' => 'userexists', 'type' => 'error', 'msg' => __('Username already exists.', 'sticpa')); //Messages that will be shown on the screen after processing the data
$formSettings['moduleName'] = getDestinationModule(); // module name, case sensitive
$formSettings['submitButton'] = __('Signup', 'sticpa'); // submit button title
$formSettings['fileName'] = basename(__FILE__, ".php"); //The list name, from the filename. Don't touch.
// $formSettings['attributes'] = "class=col2"; // the html attributtes of the form

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
if(getDestinationModule() == "Any") {
    if(isset($_GET['module'])) {
        $formSettings['moduleName'] = $_GET['module'];
        if($formSettings['moduleName'] === 'Accounts') {
            $selectedAccount = "selected";
        }
    } else {
        $formSettings['moduleName'] = 'Contacts';
    }
    $moduleSelectHTML = "
        <li class='required'>
            <label>" . __('Select your user type', 'sticpa') . ": </label>
            <select name='scp_module' id='stic-module'>
                <option value='Contacts' > " . __('Contact', 'sticpa') . " </option>
                <option value='Accounts' ".$selectedAccount."> " . __('Account', 'sticpa') . " </option>
            </select>
        </li>
        ";
    $fieldList[] = array('name' => 'stic-module', 
        'label' => __('Select your user type', 'sticpa'),
        'type' => 'html',
        'html' => $moduleSelectHTML,
    );
    $fieldList[] = array('name' => 'empty_space', 
        'type' => 'html',
        'html' => '<li></li>',
    );
} 
$fieldList[] = array('name' => 'stic_pa_username_c', 
    'label' => __('Username', 'sticpa'),
    'type' => 'text',
    'required' => true,
    'value' => '',
);
$fieldList[] = array('name' => 'stic_pa_password_c', 'label' => __('Password', 'sticpa'), 'type' => 'password', 'required' => true);
if ($formSettings['moduleName'] == 'Contacts') {
    $fieldList[] = array('name' => 'first_name', 'required' => true); 
    $fieldList[] = array('name' => 'last_name', 'required' => true);
    $fieldList[] = array('name' => 'email1', 'required' => true);
    $fieldList[] = array('name' => 'stic_gender_c');
    $fieldList[] = array('name' => 'birthdate');
    $fieldList[] = array('name' => 'phone_mobile');
} 
if ($formSettings['moduleName'] == 'Accounts' || $_GET['module'] === 'Accounts') {
    $fieldList[] = array('name' => 'name', 'required' => true); 
    $fieldList[] = array('name' => 'email1', 'required' => true);
    $fieldList[] = array('name' => 'phone_office');
}

#########################################################

$html .= makeForm($fieldList, $formSettings, $data ?? null);

if(getDestinationModule() == "Any") {
$html .= "
<script>
  const selectElement = document.getElementById('stic-module');

  selectElement.addEventListener('change', function() {
    if (selectElement.value === 'Contacts') {
      // remove module parametro from URL
      removeUrlParameter('module');
    } else if (selectElement.value === 'Accounts') {
      // Adds 'module' parameter with value 'Accounts' to URL
      addOrUpdateUrlParameter('module', 'Accounts');
    }
  });
  
  function addOrUpdateUrlParameter(key, value) {
    const url = new URL(window.location.href);
    url.searchParams.set(key, value);
    window.location.href = url.toString();
  }
  
  function removeUrlParameter(key) {
    const url = new URL(window.location.href);
    url.searchParams.delete(key);
    window.location.href = url.toString();
  }
</script>
";
};
