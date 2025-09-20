<?php
#########################################################
# Form settings                                         #
#########################################################
switch (getDestinationModule()) {
    case 'Accounts':
        $relationshipField = 'stic_registrations_accountsaccounts_ida';
        break;
    case 'Contacts':
        $relationshipField = 'stic_registrations_contactscontacts_ida';
        break;
}

$formSettings['action'] = $_REQUEST['action'];
$formSettings['title'] = __('Registration', 'sticpa'); // form title
$formSettings['moduleName'] = 'stic_Registrations'; // module name, case sensitive
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('The record has been successfully saved.', 'sticpa')); //messages that will be shown on the screen after processing the data
$formSettings['fileName'] = basename(__FILE__, ".php"); //The page name, from the filename. Don't touch.

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
            'onclick' => "location.href='?internalpage=list_stic_registrations';",
        );
        $formSettings['submitButton']['save'] = __('Register', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonActions']['save'] = array(
            'onclick' => 'return verifyFormIsValid(this)',
        );
        break;
    case 'detail':
        $formSettings['submitButton']['back'] = __('Back', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButton']['delete'] = __('Delete', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonActions']['delete'] = array(
            'onclick' => 'confirmDelete(this)',
        );
        break;
    default:
        $formSettings['submitButton'] = __('Submit', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonActions'] = array(
            'onclick' => 'verifyFormIsValid',
        );
        break;
}

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
$fieldList[] = array(
    'name' => $relationshipField,
    'type' => 'hidden',
    'defaultValue' => $_SESSION['scp_user_id'],
    'value' => $_SESSION['scp_user_id'],
);
$fieldList[] = array(
    'name' => 'registration_date',
    'type' => 'hidden',
);


$fieldList[] = array(
    'name' => 'status',
    'defaultValue' => 'confirmed',
);

$fieldList[] = array(
    'name' => 'participation_type',
    'type' => 'hidden',
    'defaultValue' => 'attendant',
);
$fieldList[] = array(
    'name' => 'attendees', 
    'type' => 'hidden', 
    'defaultValue' => 1
);

if (isset($_REQUEST['from']) && $_REQUEST['from'] == 'stic_events') {
    $eventId = $_REQUEST['id'];
    $_REQUEST['id'] = '';
} else {
    $eventId = $_REQUEST['eventId'] ?? null;
}
if ($eventId) {
    $event = $objSCP->getRecordDetail($eventId, 'stic_Events')->entry_list[0]->name_value_list;
    $fieldList[] = array(
        'name' => 'stic_registrations_stic_events_name', 
        'type' => 'text', 
        'defaultValue' => $event->name->value,
        'attributes' => array(    
            'disabled' => 'disabled',
        ),
    );
    $fieldList[] = array('name' => 'stic_registrations_stic_eventsstic_events_ida', 'type' => 'hidden', 'defaultValue' => $eventId);

} else if($_REQUEST['action'] == 'detail') {
    $fieldList[] = array(
        'name' => 'stic_registrations_stic_events_name', 
        'type' => 'text', 
    );
    $fieldList[] = array(
        'name' => 'attended_hours', 
        'type' => 'decimal', 
    );
    $fieldList[] = array(
        'name' => 'attendance_percentage', 
        'type' => 'decimal', 
    );
    
} else {
    $fieldList[] = array(
        'name' => 'stic_registrations_stic_eventsstic_events_ida', 
        'type' => 'select', 
        'label' => __('Event', 'sticpa'), // this field can't return label from API cause _ida field doesn't have label
        'selectValues' => getRelatedRecord($objSCP, 'stic_Events')
    );
}
$fieldList[] = array(
    'name' => 'special_needs',
    'type' => 'enum',
    'required' => 'false',
);

$fieldList[] = array(
    'name' => 'special_needs_description',
    'required' => 'false',
);
$data = $objSCP->getRecordDetail($_REQUEST['id'] ?? null, $formSettings['moduleName'])->entry_list[0]->name_value_list;

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

function getRelatedRecord($objSCP, $relatedModule) {
    $events = $objSCP->getRecordsModule($relatedModule);

    $listEvents = array('');
    foreach ($events as $event) {
        $listEvents[$event->name_value_list->id->value] = $event->name_value_list->name->value;
    }
    return $listEvents;
}

$html.= '
<script>
document.addEventListener("DOMContentLoaded", function(event) { 
    $("#registration_date").val(getCurrentDateTime());
    if ($("#special_needs").val() == "1"){
        $("#special_needs_description").parent().parent().show();
    }else{
        $("#special_needs_description").parent().parent().hide();
    }
    $("#special_needs").change(function(){
        if ($("#special_needs").val() == "1"){
          $("#special_needs_description").parent().parent().show();
        }else{
          $("#special_needs_description").parent().parent().hide();
        }
      });
});
</script>';