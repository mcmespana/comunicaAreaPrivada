<?php
#########################################################
# Form settings                                         #
#########################################################
$formSettings['title'] = __('Unsubscribe', 'sticpa'); // Form title
$formSettings['success'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('Successfully unsubscribed from private area.', 'sticpa')); // Messages that will be shown on the screen after processing the data
$formSettings['msg'][] = array('value' => 'error', 'type' => 'error', 'msg' => __('There was a problem unsubscribing.', 'sticpa')); //Messages that will be shown on the screen after processing the data
$formSettings['moduleName'] = 'Contacts'; // Module name, case sensitive
$formSettings['submitButton'] = __('Unsubscribe', 'sticpa'); // Submit button title
$formSettings['fileName'] = basename(__FILE__, ".php"); //The list name, from the filename. Don't touch.

$fieldList[] = array('name' => 'id', 'label' => __('ID', 'sticpa'), 'type' => 'hidden', 'required' => true);

$html .= __('By clicking on send you will unsubscribe your user account and you will no longer be able to access this private area.', 'sticpa');
$html .= makeForm($fieldList, $formSettings, null);

$html.= '
<script>
document.addEventListener("DOMContentLoaded", function(event) { 
    $("form").on("submit", function(){
        var msg = "'.__('Are you sure you want to unsubscribe?', 'sticpa').'"
        if (confirm(msg) === true) {
            return true;
        } else {
            return false;
        }
    })
});
</script>';