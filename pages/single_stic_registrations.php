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
$formSettings['title'] = __('Inscripción', 'sticpa'); // form title
$formSettings['moduleName'] = 'stic_Registrations'; // module name, case sensitive
// ¿Venimos de "Inscribirse" desde un evento? Entonces pantalla = info evento + inscripción.
$fromEvent = (isset($_REQUEST['from']) && $_REQUEST['from'] == 'stic_events');
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
        $formSettings['submitButton']['back'] = __('Back', 'sticpa');
        $formSettings['submitButtonType']['back'] = 'button';
        $formSettings['submitButtonActions']['back'] = array(
            'onclick' => "location.href='?internalpage=list_stic_registrations';",
            'class' => 'stic-back-button',
        );
        $formSettings['submitButton']['delete'] = __('Delete', 'sticpa');
        $formSettings['submitButtonType']['delete'] = 'button';
        $formSettings['submitButtonActions']['delete'] = array(
            'onclick' => 'if (confirmDelete(this)) { this.form.submit(); }',
            'class' => 'stic-back-button',
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


$statusField = array('name' => 'status', 'defaultValue' => 'confirmed');
if ($fromEvent) {
    $statusField['type'] = 'hidden'; // al inscribirse, el estado va fijo "confirmado"
}
$fieldList[] = $statusField;

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

$data = null;
if (!empty($_REQUEST['id']) && $_REQUEST['action'] !== 'create') {
    $data = $objSCP->getRecordDetail($_REQUEST['id'], $formSettings['moduleName'])->entry_list[0]->name_value_list;
    
    // Fetch related event ID using getRelatedElementsForLoggedUser (which returns the array directly)
    $eventParams = array(
        'module_name' => $formSettings['moduleName'],
        "module_id" => $_REQUEST['id'],
        "link_field_name" => 'stic_registrations_stic_events',
        "related_fields" => array('id', 'name'),
        "related_module_link_name_to_fields_array" => array(),
        "deleted" => 0,
        "order_by" => "",
        "offset" => "",
        "limit" => 1,
    );
    $relatedEvents = $objSCP->getRelatedElementsForLoggedUser($eventParams);
    if (!empty($relatedEvents)) {
        $eventId = $relatedEvents[0]->id;
        if (!isset($data->stic_registrations_stic_eventsstic_events_ida)) {
            $data->stic_registrations_stic_eventsstic_events_ida = new stdClass();
        }
        $data->stic_registrations_stic_eventsstic_events_ida->value = $eventId;
    }
}

// Mismo guard que el guardado server-side (inc/stic-action.php): evita ofrecer
// el formulario si ya hay una inscripción activa para este evento.
$alreadyRegistered = false;
if ($_REQUEST['action'] == 'create' && !empty($eventId) && function_exists('prefix_user_has_active_registration')) {
    $alreadyRegistered = prefix_user_has_active_registration($objSCP, $eventId);
}

if ($eventId && $_REQUEST['action'] !== 'edit' && $_REQUEST['action'] !== 'detail') {
    $event = $objSCP->getRecordDetail($eventId, 'stic_Events')->entry_list[0]->name_value_list;
    $evName  = $event->name->value ?? '';
    $evStart = !empty($event->start_date->value) ? formatValue($event->start_date->value, 'date') : '';
    $evEnd   = !empty($event->end_date->value) ? formatValue($event->end_date->value, 'date') : '';
    $evDesc  = $event->description->value ?? '';
    $dateLine = $evStart ? ($evStart . ($evEnd && $evEnd !== $evStart ? ' – ' . $evEnd : '')) : '';
    $calSvg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';
    // Tarjeta con la info del evento (sustituye a la pantalla "Ver").
    $kicker = __('Te inscribes a', 'sticpa');
    $fieldList[] = array(
        'name' => 'evento_info',
        'type' => 'html',
        'html' => '<li class="stic-event-card">'
            . '<span class="stic-event-card-kicker">' . esc_html($kicker) . '</span>'
            . '<div class="stic-event-card-title">' . esc_html($evName) . '</div>'
            . ($dateLine ? '<div class="stic-event-card-meta">' . $calSvg . '<span>' . esc_html($dateLine) . '</span></div>' : '')
            . ($evDesc ? '<p class="stic-event-card-desc">' . esc_html($evDesc) . '</p>' : '')
            . '</li>',
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

if ($alreadyRegistered) {
    // Show warning card and only a "Back" button
    $formSettings['submitButton'] = array('back' => __('Volver', 'sticpa'));
    $formSettings['submitButtonType'] = array('back' => 'button');
    $formSettings['submitButtonActions'] = array(
        'back' => array(
            'onclick' => "location.href='?internalpage=list_stic_registrations';",
        ),
    );
    
    // Dejamos solo la tarjeta del evento + el aviso (nada de formulario que permita guardar).
    $eventCard = null;
    foreach ($fieldList as $f) {
        if (($f['name'] ?? '') === 'evento_info') { $eventCard = $f; break; }
    }
    $fieldList = $eventCard ? array($eventCard) : array();
    $fieldList[] = array(
        'name' => 'already_registered_msg',
        'type' => 'html',
        'html' => '
            <li class="stic-warning-card" style="grid-column: 1 / -1; list-style: none; background: #fffbeb; border: 1.5px solid #fef3c7; border-left: 4px solid #d97706; border-radius: var(--radius-md); padding: 1.25rem 1.4rem; margin-top: 1rem; color: #92400e; display: flex; align-items: flex-start; gap: 0.75rem;">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink: 0; margin-top: 0.15rem;"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <div>
                    <strong style="display: block; font-size: 1rem; margin-bottom: 0.25rem;">' . __('Ya estás inscrito', 'sticpa') . '</strong>
                    <span>' . __('Ya cuentas con una inscripción activa para este evento. No es necesario que te vuelvas a inscribir.', 'sticpa') . '</span>
                </div>
            </li>',
    );
} else {
    $fieldList[] = array(
        'name' => 'special_needs',
        'type' => 'enum',
        'required' => 'false',
    );
    
    $fieldList[] = array(
        'name' => 'special_needs_description',
        'required' => 'false',
    );
}

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
    (function($) {
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
    })(jQuery);
});
</script>';