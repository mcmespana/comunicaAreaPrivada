<?php
/**
 * INSCRIPCIÓN — ficha (detail) y alta/edición (create/edit).
 * ----------------------------------------------------------------------------
 * `action=detail` ya NO es el formulario genérico con todos los campos
 * deshabilitados. Eso eran cajas grises que no se pueden tocar, con las
 * etiquetas crudas del CRM: parecía un formulario roto, no la ficha de nada.
 * Ahora es una ficha de verdad, la misma que Eventos: cabecera, importe,
 * avisos, datos clave y las personas de contacto
 * (sticpa_registration_detail_html, en inc/stic-registrations.php).
 *
 * El alta y la edición siguen con el motor de formularios, que es lo suyo.
 */

if (!defined('ABSPATH')) {
    exit;
}

// ¿Es tuyo? Antes esta pantalla abría el registro de cualquier `?id=` que se
// le pasara (inc/stic-security.php, sticpa_record_denied_html).
$sticpaDenied = sticpa_record_denied_html($objSCP, 'stic_Registrations', (($_REQUEST['action'] ?? '') === 'create' ? '' : ($_REQUEST['id'] ?? '')), 'list_stic_registrations', __('Esta inscripción ya no está disponible', 'sticpa'));
if ($sticpaDenied !== '') {
    $html .= $sticpaDenied;
    return;
}

// --- FICHA -----------------------------------------------------------------
// Se resuelve antes que nada y se sale: no hace falta montar $fieldList ni
// llamar al motor para enseñar un registro que ya se sabe leer.
if (($_REQUEST['action'] ?? '') === 'detail') {
    $registrationId = isset($_REQUEST['id']) ? sanitize_text_field($_REQUEST['id']) : '';

    if ($registrationId === '') {
        $html .= sticpa_record_empty_html(
            'check',
            __('No hemos encontrado la inscripción', 'sticpa'),
            __('Puede que el enlace esté incompleto. Vuelve a tus inscripciones y entra de nuevo.', 'sticpa'),
            array('label' => __('Ver mis inscripciones', 'sticpa'), 'url' => '?internalpage=list_stic_registrations', 'primary' => true)
        );
        return;
    }

    // Definición cacheada 6h: de aquí salen las etiquetas traducidas de los
    // desplegables (nunca se le enseña a nadie la clave cruda del CRM) y qué
    // campos de respuesta existen (EV-6). Va ANTES de pedir la inscripción:
    // pedir un campo que no existe tumba la consulta entera.
    $definition = sticpa_registration_definition($objSCP);

    $detail = $objSCP->getRecordDetail($registrationId, 'stic_Registrations', array_merge(
        sticpa_registration_detail_fields(),
        sticpa_registration_existing_answer_fields($definition)
    ));
    $nvl = $detail->entry_list[0]->name_value_list ?? null;
    $registration = $nvl ? sticpa_registration_view_model($nvl, sticpa_registration_event_index()) : null;

    if (!$registration) {
        $html .= sticpa_record_empty_html(
            'check',
            __('Esta inscripción ya no está disponible', 'sticpa'),
            __('Es posible que se haya retirado. Consulta el resto de tus inscripciones.', 'sticpa'),
            array('label' => __('Ver mis inscripciones', 'sticpa'), 'url' => '?internalpage=list_stic_registrations', 'primary' => true)
        );
        return;
    }

    // EL EVENTO (EV-2 y EV-7): su plazo decide si se puede cambiar o cancelar,
    // y su precio, si hay un pago que enseñar. Una llamada, la misma forma que
    // la del formulario de inscripción.
    $eventNvl = null;
    if ($registration['event_id'] !== '') {
        $eventDetail = $objSCP->getRecordDetail($registration['event_id'], 'stic_Events', sticpa_event_fields_to_request($objSCP));
        $eventNvl = $eventDetail->entry_list[0]->name_value_list ?? null;
    }
    $derechos = sticpa_registration_manage_rights($registration['status'], $eventNvl, $definition);
    $precio = $eventNvl ? sticpa_event_price($eventNvl) : 0.0;

    // El pago, solo si la actividad cuesta algo: una llamada que no se paga
    // en las inscripciones gratis.
    $pagos = array();
    $metodos = array();
    if ($precio > 0) {
        $pagos = (array) sticpa_registration_commitments($objSCP, $registrationId);
        if (!empty($pagos)) {
            $metodos = sticpa_crm_enum_options(
                sticpa_cached_field_definition($objSCP, 'stic_Payment_Commitments', array('payment_method')),
                'payment_method'
            );
        }
    }

    $html .= sticpa_registration_detail_html($registration, $definition, array(
        'aviso'     => sticpa_registration_saved_note($_REQUEST['msg'] ?? ''),
        'pagos'     => $pagos,
        'metodos'   => $metodos,
        'precio'    => $precio,
        'pagar_url' => $precio > 0
            ? '?internalpage=single_stic_payment_form&amount=' . rawurlencode(number_format($precio, 2, '.', ''))
                . '&eventId=' . rawurlencode($registration['event_id']) . '&registrationId=' . rawurlencode($registrationId)
            : '',
        'derechos'  => $derechos,
        'gestion'   => sticpa_registration_manage_html($registrationId, $derechos),
    ));
    return;
}

// --- MODIFICAR (EV-2) -------------------------------------------------------
// Solo lo que es de la persona: sus respuestas y sus necesidades especiales.
// NO el evento ni el estado. Antes, `action=edit` pintaba un desplegable con
// TODOS los eventos del CRM: se podía mover una inscripción a otra actividad
// saltándose la audiencia y el plazo, que solo se miraban al crear.
if (($_REQUEST['action'] ?? '') === 'edit') {
    $registrationId = isset($_REQUEST['id']) ? sanitize_text_field($_REQUEST['id']) : '';
    $detailUrl = '?internalpage=single_stic_registrations&action=detail&id=' . rawurlencode($registrationId);
    $definition = sticpa_registration_definition($objSCP);
    $regNvl = null;
    if ($registrationId !== '') {
        $regDetail = $objSCP->getRecordDetail($registrationId, 'stic_Registrations', array_merge(
            array('id', 'name', 'status', 'special_needs', 'special_needs_description',
                'stic_registrations_stic_events_name', 'stic_registrations_stic_eventsstic_events_ida'),
            sticpa_registration_existing_answer_fields($definition)
        ));
        $regNvl = $regDetail->entry_list[0]->name_value_list ?? null;
    }
    if (!$regNvl) {
        $html .= sticpa_record_empty_html(
            'check',
            __('Esta inscripción ya no está disponible', 'sticpa'),
            __('Vuelve a tus inscripciones y entra de nuevo desde ahí.', 'sticpa'),
            array('label' => __('Ver mis inscripciones', 'sticpa'), 'url' => '?internalpage=list_stic_registrations', 'primary' => true)
        );
        return;
    }
    $editEventId = trim((string) ($regNvl->stic_registrations_stic_eventsstic_events_ida->value ?? ''));
    $eventNvl = null;
    if ($editEventId !== '') {
        $eventDetail = $objSCP->getRecordDetail($editEventId, 'stic_Events', sticpa_event_fields_to_request($objSCP));
        $eventNvl = $eventDetail->entry_list[0]->name_value_list ?? null;
    }
    $derechos = sticpa_registration_manage_rights((string) ($regNvl->status->value ?? ''), $eventNvl, $definition);
    if (empty($derechos['editar'])) {
        $html .= sticpa_record_empty_html(
            'check',
            __('Esta inscripción ya no se puede cambiar', 'sticpa'),
            $derechos['motivo'] !== '' ? $derechos['motivo'] : __('Si necesitas cambiar algo, habla con tu delegación.', 'sticpa'),
            array('label' => __('Ver mi inscripción', 'sticpa'), 'url' => $detailUrl, 'primary' => true)
        );
        return;
    }

    $formSettings = array(
        'action'     => 'edit',
        'title'      => __('Modificar mi inscripción', 'sticpa'),
        'moduleName' => 'stic_Registrations',
        'fileName'   => basename(__FILE__, '.php'),
        'msg'        => array(
            array('value' => 'error_respuestas', 'type' => 'error', 'msg' => __('Contesta a todas las preguntas de la actividad.', 'sticpa')),
            array('value' => 'error', 'type' => 'error', 'msg' => __('No hemos podido guardar el cambio. Inténtalo otra vez en un rato.', 'sticpa')),
        ),
        'submitButton' => array('back' => __('Volver', 'sticpa'), 'save' => __('Guardar cambios', 'sticpa')),
        'submitButtonType' => array('back' => 'button'),
        'submitButtonActions' => array(
            'back' => array('onclick' => "location.href='" . esc_js($detailUrl) . "';"),
            'save' => array('onclick' => 'return verifyFormIsValid(this)'),
        ),
    );
    $editFields = array(array('name' => 'id', 'type' => 'hidden'));
    $editFields[] = array(
        'name' => 'evento_info',
        'type' => 'html',
        'html' => sticpa_registration_event_card_html(
            __('Tu inscripción a', 'sticpa'),
            (string) ($regNvl->stic_registrations_stic_events_name->value ?? ''),
            $eventNvl ? sticpa_record_date_line(sticpa_event_view_model($eventNvl)['start_ts'] ?? null, sticpa_event_view_model($eventNvl)['end_ts'] ?? null) : '',
            '',
            $editEventId
        ),
    );
    $editFields = array_merge($editFields, sticpa_event_question_form_fields(sticpa_event_questions($eventNvl, $definition), $regNvl));
    $editFields[] = array('name' => 'special_needs', 'type' => 'enum', 'required' => 'false');
    $editFields[] = array('name' => 'special_needs_description', 'required' => 'false');

    $html .= makeForm($editFields, $formSettings, $regNvl, 'edit');
    $html .= sticpa_registration_special_needs_js();
    return;
}

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
// Las vueltas del guardado cuando algo no cuadra (EV-6 / EV-7): antes solo había
// «guardado» y el resto de errores volvía a un formulario sin decir nada.
$formSettings['msg'][] = array('value' => 'error_respuestas', 'type' => 'error', 'msg' => __('Contesta a todas las preguntas de la actividad.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'error_pago', 'type' => 'error', 'msg' => __('Elige cómo vas a pagar. Si es por domiciliación, revisa la cuenta: el IBAN no es válido.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'error', 'type' => 'error', 'msg' => __('No hemos podido guardar la inscripción. Inténtalo otra vez en un rato.', 'sticpa'));
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

// El evento se lee UNA SOLA VEZ: lo necesitan el guard de audiencia y la
// tarjeta de "Te inscribes a". Antes solo lo leía la tarjeta; ahora hay dos
// clientes, y dos `getRecordDetail` del MISMO evento en la misma pantalla son
// un viaje al CRM regalado en la webview de un sábado por la tarde.
$eventNvl = null;
if (!empty($eventId) && $_REQUEST['action'] !== 'edit' && $_REQUEST['action'] !== 'detail') {
    $eventFields = function_exists('sticpa_event_fields_to_request')
        ? sticpa_event_fields_to_request($objSCP)
        : null;
    $eventDetail = $objSCP->getRecordDetail($eventId, 'stic_Events', $eventFields);
    $eventNvl = $eventDetail->entry_list[0]->name_value_list ?? null;
}

// Mismo guard que el guardado server-side (inc/stic-action.php): evita ofrecer
// el formulario si ya hay una inscripción activa para este evento.
$alreadyRegistered = false;
if ($_REQUEST['action'] == 'create' && !empty($eventId) && function_exists('prefix_user_has_active_registration')) {
    $alreadyRegistered = prefix_user_has_active_registration($objSCP, $eventId);
}

// ¿ADMITE ESTA ACTIVIDAD SU INSCRIPCIÓN? (audiencia + fechas del plazo). El
// formulario se alcanza por URL (`?action=create&from=stic_events&id=…`), así
// que no vale con que el listado y la ficha no lo ofrezcan. El guardado lo
// comprueba otra vez (inc/stic-action.php): esto es solo para no enseñar un
// formulario que va a acabar en un rechazo.
$signupBlock = array('bloqueado' => false, 'titulo' => '', 'texto' => '');
if ($_REQUEST['action'] == 'create' && !empty($eventId) && !$alreadyRegistered
    && function_exists('sticpa_event_signup_block')) {
    $signupBlock = sticpa_event_signup_block($objSCP, $eventId, $eventNvl);
}

if ($eventId && $_REQUEST['action'] !== 'edit' && $_REQUEST['action'] !== 'detail') {
    // Si el CRM no devolvió el evento, la tarjeta sale vacía —como antes— pero
    // sin avisos de "propiedad de null": el campo oculto con el id se añade
    // igual al final del bloque, para no perder la relación al guardar.
    $event = $eventNvl ?: new stdClass();
    $evName  = $event->name->value ?? '';
    $evStart = !empty($event->start_date->value) ? formatValue($event->start_date->value, 'date') : '';
    $evEnd   = !empty($event->end_date->value) ? formatValue($event->end_date->value, 'date') : '';
    $evDesc  = $event->description->value ?? '';
    $dateLine = $evStart ? ($evStart . ($evEnd && $evEnd !== $evStart ? ' – ' . $evEnd : '')) : '';
    $calSvg = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>';
    // Tarjeta con la info del evento (sustituye a la pantalla "Ver"), con el
    // enlace a su ficha: el cartel, el cuerpo de la web y los documentos están
    // ahí, y aquí solo cabe el nombre y las fechas (EV-1).
    $fieldList[] = array(
        'name' => 'evento_info',
        'type' => 'html',
        'html' => sticpa_registration_event_card_html(__('Te inscribes a', 'sticpa'), $evName, $dateLine, $evDesc, $eventId, $calSvg),
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
    // Al CREAR se ofrecen solo los eventos de la ventana viva (-3 … +12 meses):
    // nadie se apunta a algo de hace tres años, y sin filtro esto descargaba el
    // histórico completo de eventos del CRM.
    // Al EDITAR se piden TODOS a propósito: si el evento ya elegido cayera fuera
    // de la ventana no estaría en el desplegable, y guardar perdería la relación.
    $isNewRegistration = (($_REQUEST['action'] ?? '') === 'create') || empty($data);
    $eventsQuery = ($isNewRegistration && function_exists('sticpa_events_window_filter'))
        ? sticpa_events_window_filter()
        : '';
    $fieldList[] = array(
        'name' => 'stic_registrations_stic_eventsstic_events_ida',
        'type' => 'select',
        'label' => __('Event', 'sticpa'), // this field can't return label from API cause _ida field doesn't have label
        // Al CREAR, el desplegable pasa por el filtro de audiencia: ofrecer un
        // evento de otra delegación en una lista es ofrecerlo igual.
        // Al EDITAR no se filtra, o el evento ya elegido podría desaparecer del
        // desplegable y guardar perdería la relación.
        'selectValues' => getRelatedRecord($objSCP, 'stic_Events', $eventsQuery, $isNewRegistration)
    );
}

// Tres razones distintas para NO ofrecer el formulario, y la misma pantalla:
// tarjeta del evento + aviso + "Volver". Antes solo existía la de "ya estás
// inscrito"; la audiencia (otra delegación, otro perfil, otro curso) y el
// plazo de inscripción entran por aquí en vez de duplicar el montaje.
//
// «Ya estás inscrito» va primero porque es un hecho sobre esta persona: a
// quien ya tiene su plaza no se le dice que llega tarde ni que no es para ella.
$blockedTitle = '';
$blockedText = '';
if ($alreadyRegistered) {
    $blockedTitle = __('Ya estás inscrito', 'sticpa');
    $blockedText = __('Ya cuentas con una inscripción activa para este evento. No es necesario que te vuelvas a inscribir.', 'sticpa');
} elseif (!empty($signupBlock['bloqueado'])) {
    $blockedTitle = $signupBlock['titulo'];
    $blockedText = $signupBlock['texto'];
}

if ($blockedText !== '') {
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
            <li class="stic-warning-card">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <div>
                    <strong>' . esc_html($blockedTitle) . '</strong>
                    <span>' . esc_html($blockedText) . '</span>
                </div>
            </li>',
    );
} else {
    // LAS PREGUNTAS DE LA ACTIVIDAD (EV-6) y EL PAGO (EV-7), si los tiene. Solo
    // al apuntarse desde un evento: es el único camino en el que sabemos a qué.
    if ($fromEvent && $eventNvl) {
        $regDefinition = sticpa_registration_definition($objSCP);
        $fieldList = array_merge($fieldList, sticpa_event_question_form_fields(sticpa_event_questions($eventNvl, $regDefinition)));
        $precio = sticpa_event_price($eventNvl);
        if ($precio > 0) {
            $fieldList = array_merge($fieldList, sticpa_registration_payment_form_fields($precio, sticpa_registration_payment_methods($objSCP)));
        }
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

/**
 * Opciones (id => nombre) de un módulo relacionado para un <select>.
 *
 * $query acota lo que se pide al CRM: sin ella se descarga el módulo ENTERO
 * (todo el histórico de eventos) para rellenar un desplegable.
 *
 * $filtrarAudiencia deja fuera los eventos que no son para quien mira. Solo se
 * pide con los campos de audiencia cuando hace falta: el desplegable normal se
 * conforma con id y nombre.
 */
function getRelatedRecord($objSCP, $relatedModule, $query = '', $filtrarAudiencia = false) {
    $filtrarAudiencia = $filtrarAudiencia
        && $relatedModule === 'stic_Events'
        && function_exists('sticpa_filter_events_for_viewer')
        && function_exists('sticpa_event_fields_to_request');

    $fields = $filtrarAudiencia ? sticpa_event_fields_to_request($objSCP) : array('id', 'name');
    $events = $objSCP->getRecordsModule($relatedModule, $query, $fields);
    if ($filtrarAudiencia && is_array($events)) {
        $events = sticpa_filter_events_for_viewer($objSCP, $events);
    }

    $listEvents = array('');
    if (is_array($events)) {
        foreach ($events as $event) {
            $listEvents[$event->name_value_list->id->value] = $event->name_value_list->name->value;
        }
    }
    return $listEvents;
}

$html .= sticpa_registration_special_needs_js();
