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
        if ($_REQUEST['action'] === 'edit' && !empty($_REQUEST['id'])) {
            $formSettings['submitButton']['delete'] = __('Delete', 'sticpa');
            $formSettings['submitButtonType']['delete'] = 'button';
            $formSettings['submitButtonActions']['delete'] = array(
                'onclick' => 'if (confirmDelete(this)) { this.form.submit(); }',
                'class' => 'stic-back-button',
            );
        }
        $formSettings['submitButton']['save'] = __('Save', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonActions']['save'] = array(
            'onclick' => 'return verifyFormIsValid(this)',
        );
        $formSettings['attributes'] = 'enctype="multipart/form-data"';
        break;
    case 'detail':
        $formSettings['submitButton']['back'] = __('Back', 'sticpa');
        $formSettings['submitButtonType']['back'] = 'button';
        $formSettings['submitButtonActions']['back'] = array(
            'onclick' => "location.href='?internalpage=list_stic_documents';",
            'class' => 'stic-back-button',
        );
        $formSettings['submitButton']['delete'] = __('Delete', 'sticpa');
        $formSettings['submitButtonType']['delete'] = 'button';
        $formSettings['submitButtonActions']['delete'] = array(
            'onclick' => 'if (confirmDelete(this)) { this.form.submit(); }',
            'class' => 'stic-back-button',
        );
        $formSettings['submitButton']['download'] = __('Download', 'sticpa');
        $formSettings['submitButtonType']['download'] = 'submit';
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

// Al EDITAR: botón de DESCARGA DIRECTA del archivo subido (sin pasar por "Ver").
if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'edit' && !empty($_REQUEST['id'])) {
    $stic_dl_url = admin_url('admin-post.php')
        . '?action=single_stic_documents&download=true&id=' . urlencode($_REQUEST['id']);
    $stic_current_file = $data->filename->value ?? '';
    $fieldList[] = array(
        'name' => 'archivo_actual',
        'type' => 'html',
        'html' => '
            <li>
                <label>' . __('Archivo subido', 'sticpa') . '</label>
                ' . ($stic_current_file ? '<div style="font-size:12px;color:#6b7280;margin-bottom:6px">' . esc_html($stic_current_file) . '</div>' : '') . '
                <span><a class="stic-soft-btn" href="' . esc_url($stic_dl_url) . '">'
                    . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg> '
                    . __('Descargar archivo', 'sticpa') . '</a></span>
            </li>',
    );
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
if (isset($_REQUEST['action']) && ($_REQUEST['action'] == 'create' || $_REQUEST['action'] == 'edit')) {
    $html .= '
       <script>
            var formHasAlreadyBeenSent = false;
            var showMessage = false;
            
            /**
            * Prevent multiple form submissions
            *
            * @return void
            */
            function lockMultipleSubmissions(event) {
                var fileInput = document.getElementById("filename");
                // Only show "Uploading file..." and block duplicate submissions if there is actually a file selected
                if (fileInput && fileInput.files && fileInput.files.length > 0) { 
                    if (formHasAlreadyBeenSent) {
                        console.log("Form is locked because it has already been sent.");
                        event.preventDefault();
                        return;
                    }
                    formHasAlreadyBeenSent = true;

                    if (!showMessage) {
                        // Create a div element for upload message
                        const uploadMessage = document.createElement("div");
                        uploadMessage.textContent = "'.__('Uploading file, please wait...', 'sticpa').'";

                        var sendContainer = document.querySelector(".stic-send");
                        if (sendContainer) {
                            sendContainer.appendChild(uploadMessage);
                        }
                    }
                    showMessage = true;
                }
           }
           // Attach function to event
           var formEl = document.getElementById("stic-wp-pa");
           if (formEl) {
               formEl.addEventListener("submit", lockMultipleSubmissions);
           }
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
