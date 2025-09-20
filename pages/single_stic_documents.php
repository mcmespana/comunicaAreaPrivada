<?php
#########################################################
# Form settings                                         #
#########################################################
switch (getDestinationModule()) {
    case 'Accounts':
        $relationshipField = 'accounts';
        break;
    case 'Contacts':
        $relationshipField = 'contacts';
        break;
}

$formSettings['action'] = $_REQUEST['action'];
$formSettings['title'] = __('Document', 'sticpa'); // form title
$formSettings['moduleName'] = 'Documents'; // module name, case sensitive
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('The record has been successfully saved.', 'sticpa')); //messages that will be shown on the screen after processing the data

switch ($_REQUEST['action']) {
    case 'create':
    case 'edit':
        $formSettings['submitButton']['back'] = __('Back', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonType']['back'] = 'button';
        $formSettings['submitButtonActions']['back'] = array(
            'onclick' => "location.href='?internalpage=list_stic_documents';",
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
        $formSettings['submitButtonActions']['back'] = array(
            'onclick' => 'disableDownload(this)',
        );
        $formSettings['submitButton']['delete'] = __('Delete', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonActions']['delete'] = array(
            'onclick' => 'confirmDelete(this)',
        );
        $formSettings['submitButton']['download'] = __('Download', 'sticpa'); // submit button title
        $formSettings['submitButtonActions']['download'] = array(
            'onclick' => 'enableDownload(this)',
        );
        $fieldList[] = array('name' => 'download', 'type' => 'hidden', 'value' => false);
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
$data = $objSCP->getRecordDetail($_SESSION['scp_user_id'], 'Contacts')->entry_list[0]->name_value_list;

$fieldList[] = array('name' => 'id', 'type' => 'hidden');

$fieldList[] = array(
    'name' => $relationshipField,
    'type' => 'hidden',
    'defaultValue' => $_SESSION['scp_user_id'],
    'value' => $_SESSION['scp_user_id'],
);

$fieldList[] = array('name' => 'assigned_user_id', 'type' => 'hidden', 'defaultValue' => isset($data->assigned_user_id) ? $data->assigned_user_id->value : null);
$fieldList[] = array('name' => 'document_name');

if (isset($_REQUEST['action']) && ($_REQUEST['action'] == 'create'  || $_REQUEST['action'] == 'edit')) {
    $fieldList[] = array('name' => 'download', 'type' => 'hidden', 'value' => false);
    $fieldList[] = array(
        'name' => 'filename', 
        'type' => 'html', 
        'html' => '
            <li>
                <label>'.__('Choose a file', 'sticpa').'</label>
                <span><input type="file" name="filename" id="filename"></span>
            </li>'
    );
    $fieldList[] = array(
        'name' => 'status_id',
        'type' => 'hidden',
        'defaultValue' => 'Active',
    );
} else {
    $fieldList[] = array('name' => 'filename', 'type' => 'text');
}
$fieldList[] = array(
    'name' => 'status_id',
);
$fieldList[] = array(
    'name' => 'stic_shared_document_link_c', 
    'type' => 'text',
    'defaultValue' => 'https://',
);
$fieldList[] = array('name' => 'category_id');

$fieldList[] = array('name' => 'description', 'type' => 'textarea');


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
if (isset($_REQUEST['action']) && ($_REQUEST['action'] == 'create')) {
    $html .= '
       <script>
            var formHasAlreadyBeenSent = false;
            var showMessage = false;
            
            /**
            * Prevent multiple form submissions
            *
            * @return void
            */
            function lockMultipleSubmissions() {
               var fileInput = document.getElementById("filename");
               if ("files" in fileInput) { 
                    if (formHasAlreadyBeenSent) {
                        console.log("Form is locked because it has already been sent.");
                        event.preventDefault();
                    }
                    formHasAlreadyBeenSent = true;

                    if(!showMessage) {
                        // Create a div element for upload message
                        const uploadMessage = document.createElement("div");
                        uploadMessage.textContent = "'.__('Uploading file, please wait...', 'sticpa').'";

                        submitButton = document.querySelectorAll("[id=\'add-sign-up\']")[1];
                        // Add upload message just below submit button
                        submitButton.parentNode.insertBefore(uploadMessage, submitButton.nextSibling);
                    }
                    showMessage = true;
                }
           }
           // Attach function to event
           document.getElementById("stic-wp-pa").addEventListener("submit", lockMultipleSubmissions);
       </script>';
}

if (isset($_REQUEST['download']) && $_REQUEST['download']) {
    $html.= '
    <script>
    document.addEventListener("DOMContentLoaded", function(event) { 
        document.getElementById("add-sign-up").click();
    });
    </script>';
}
