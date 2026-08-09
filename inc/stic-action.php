<?php

/**
 * This action is used for managing tutor-user functionality (Parents-Children)
 * 
 * When the connected contact has a Personal Environment relationship where he/she is tutor/father/mother of some other
 * contact, it will be displayed as a selectable participant. The connected contact could have more than selectable participant.
 */

add_action('admin_post_single_stic_profile_selection', 'prefix_admin_single_stic_profile_selection'); 
add_action('admin_post_nopriv_single_stic_profile_selection', 'prefix_admin_single_stic_profile_selection'); 
function prefix_admin_single_stic_profile_selection()
{
    // Cambio de participante SOLO con sesión abierta (las URLs de cambio llegan
    // por GET desde el selector rápido: sin sesión no hay nada que cambiar).
    if (!isset($_SESSION['scp_user_id'])) {
        wp_redirect(home_url());
        exit;
    }

    if (is_array($_REQUEST) && isset($_REQUEST['profile_selected_id']) && isset($_REQUEST['profile_selected_name'])) {
        $requestUserId = sanitize_text_field($_REQUEST['profile_selected_id']);
        $requestUserName = sanitize_text_field(rawurldecode(stripslashes_deep($_REQUEST['profile_selected_name'])));

        // El FAMILIAR (quien inició sesión) queda fijado la primera vez y ya no cambia.
        if (!isset($_SESSION['scp_tutor_user_id'])) {
            $_SESSION['scp_tutor_user_id'] = sanitize_text_field($_REQUEST['scp_user_id']);
        }
        if (!isset($_SESSION['scp_tutor_user_contact_name'])) {
            $_SESSION['scp_tutor_user_contact_name'] = sanitize_text_field(rawurldecode(stripslashes_deep($_REQUEST['scp_user_contact_name'])));
        }

        // scp_user_* pasa a ser el PARTICIPANTE activo: es lo que leen todas las
        // páginas. Si el familiar se elige a sí mismo, scp_tutor_is_user = true.
        $_SESSION['scp_tutor_is_user'] = ($_SESSION['scp_tutor_user_id'] == $requestUserId);
        $_SESSION['scp_user_id'] = $requestUserId;
        $_SESSION['scp_user_contact_name'] = $requestUserName;
    }

    // Sin sesión de familiar montada → de vuelta a la selección; si no, SIEMPRE a
    // la home. Antes se saltaba a `default_page` (= 'list_stic_events'), así que
    // cambiar de participante te dejaba en Eventos, con el contexto perdido:
    // acabas de decir "quiero ver a X" y lo primero que quieres es el panel de X,
    // no una sección cualquiera. El parámetro `default_page` ya no se usa (se
    // acepta en la URL por compatibilidad con enlaces antiguos, pero se ignora).
    if (!isset($_SESSION['scp_tutor_user_id'])) {
        $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=single_stic_profile_selection";
    } else {
        $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=single_stic_home";
    }
    wp_redirect($redirectUrl);
    exit;
}

/**
 * This action is used for managing user profile modifications.
 * 
 * It includes uploading pictures to the user Contact record.
 */
add_action('admin_post_single_stic_profile', 'prefix_admin_single_stic_profile'); 
add_action('admin_post_nopriv_single_stic_profile', 'prefix_admin_single_stic_profile'); 
function prefix_admin_single_stic_profile() 

{
    $moduleName = getDestinationModule(); 

    $objSCP = SugarRestApiCall::getObjSCP();

    foreach ($_REQUEST as $key => $value) {
        $moduleData[$key] = is_array($value) ? '^' . implode('^,^', stripslashes_deep($value)) . '^' : stripslashes_deep($value);
    }
    
    // Check if there is an id of organization member to edit their profile or edit own profile
    $id = $_REQUEST['id'] ?? $_SESSION['scp_user_id'];
    $moduleData['id'] = $id;

    $isUpdate = $objSCP->set_entry($moduleName, $moduleData);
    // If there is a picture attached, we upload it first
    if ($isUpdate && isset($_FILES['photo']) && !empty($_FILES['photo']['name'])) {
        $redirect_url = upload_file_to_record('photo', 'Contacts', $isUpdate);
    } else if ($isUpdate) {
        $redirect_url = $_REQUEST['scp_current_url'] . '&action=detail&id=' . $id . '&msg=true'; 
    } else {
        $redirect_url = $_REQUEST['scp_current_url'] . '&action=detail&id=' . $id . '&msg=error'; 
    }
    wp_redirect($redirect_url);
    exit;
}

/**
 * This action is used for managing tutor's user profile modifications.
 */
add_action('admin_post_single_stic_tutor_profile', 'prefix_admin_single_stic_tutor_profile'); 
add_action('admin_post_nopriv_single_stic_tutor_profile', 'prefix_admin_single_stic_tutor_profile'); 
function prefix_admin_single_stic_tutor_profile()
{
    $moduleName = getDestinationModule();

    $objSCP = SugarRestApiCall::getObjSCP();

    // El familiar solo puede editar SU ficha: id desde la sesión, nunca del request.
    $tutorId = $_SESSION['scp_tutor_user_id'] ?? ($_SESSION['scp_user_id'] ?? '');
    if (!$tutorId) {
        wp_redirect(home_url());
        exit;
    }

    // Claves que no son campos del CRM (no enviar a set_entry).
    $skip = array('action', 'scp_current_url', 'stic-action', 'save', 'back', 'id', 'stic_year_only_fields');
    $moduleData = array();
    foreach ($_REQUEST as $key => $value) {
        if (in_array($key, $skip, true)) {
            continue;
        }
        $moduleData[$key] = is_array($value) ? '^' . implode('^,^', stripslashes_deep($value)) . '^' : stripslashes_deep($value);
    }
    if (function_exists('sticpa_apply_year_only_fields')) {
        sticpa_apply_year_only_fields($moduleData);
    }
    $moduleData['id'] = $tutorId;

    $isUpdate = $objSCP->set_entry($moduleName, $moduleData);
    if ($isUpdate) {
        // El nombre puede haber cambiado: refrescamos la identidad de la sesión.
        $first = $moduleData['first_name'] ?? '';
        $last = $moduleData['last_name'] ?? '';
        if ($first || $last) {
            $_SESSION['scp_tutor_user_contact_name'] = trim($last . ', ' . $first, ', ');
            if (!empty($_SESSION['scp_tutor_is_user'])) {
                $_SESSION['scp_user_contact_name'] = $_SESSION['scp_tutor_user_contact_name'];
            }
        }
        $redirect_url = $_REQUEST['scp_current_url'] . '&msg=true';
    } else {
        $redirect_url = $_REQUEST['scp_current_url'] . '&msg=error';
    }
    wp_redirect($redirect_url);
    exit;
}

/**
 * Action to manage uploading and downloading documents related to the current user
 * 1- Create a Document record
 * 2- Relate the Document to the Contact record of the current user
 * 3- Upload the file using a DocumentRevision and relating it to the parent Document
 */
add_action('admin_post_single_stic_documents', 'prefix_admin_single_stic_documents'); 
add_action('admin_post_nopriv_single_stic_documents', 'prefix_admin_single_stic_documents'); 
function prefix_admin_single_stic_documents() 
{
    if (isset($_REQUEST['download']) && $_REQUEST['download'] == "true") {
        download_document($_REQUEST['id']);
    } else {
        if ($_REQUEST['stic-action'] == 'detail') {
            $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_documents";
            wp_redirect($redirectUrl);
            exit;
        } else {
            $objSCP = SugarRestApiCall::getObjSCP();

            foreach ($_REQUEST as $key => $value) {
                $moduleData[$key] = is_array($value) ? '^' . implode('^,^', stripslashes_deep($value)) . '^' : stripslashes_deep($value);
            }
            $action = $moduleData['stic-action'];
            unset($moduleData['stic-action']); 
            unset($moduleData['filename']); // Prevent request artifacts like 'admin-post.php' from being saved as filename
            if ($action === 'delete') {
                $moduleData['deleted'] = 1;
                // Fetch the existing document name to satisfy CRM required fields validation
                $existing = $objSCP->getRecordDetail($moduleData['id'], 'Documents');
                if (!empty($existing->entry_list)) {
                    $doc_name = $existing->entry_list[0]->name_value_list->document_name->value ?? '';
                    if ($doc_name) {
                        $moduleData['document_name'] = $doc_name;
                    }
                }
            }

            // Creating a Document Record
            $documentEntryId = $objSCP->set_entry('Documents', $moduleData);

            if ($documentEntryId != null) {

                if ($action === 'delete') {
                    $redirect_url = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_documents&msgDelete=true";
                    wp_redirect($redirect_url);
                    exit;
                } else {
                    // If no file has been uploaded, we return to the detailview
                    
                    switch (getDestinationModule()) {
                        case 'Accounts':
                            $relationship = 'accounts';
                            break;
                        case 'Contacts':
                            $relationship = 'contacts';
                            break;
                    }
                    $relatedId = $_REQUEST[$relationship];
                    // Relating the Document to the Contact record
                    $resultRelationship = $objSCP->set_relationship('Documents', $documentEntryId, $relationship, array($relatedId));
            
                    if ($resultRelationship != null) {
                        if ($_FILES['filename']['error'] == 4) {
                            $redirect_url = $_REQUEST['scp_current_url'] . '&msg=true' . '&id=' . $documentEntryId . ($action ? '&action=detail' : '');
                            wp_redirect($redirect_url);
                            exit;
                        } else{
                            $fileName = $_FILES['filename']['name'];
                            $tmpName  = $_FILES['filename']['tmp_name'];
                            $contents = file_get_contents ($tmpName);
                    
                            $documentRevisionData = array(
                                'id' => $documentEntryId,
                                'file' => base64_encode($contents),
                                'filename' => $fileName,
                            );
                            // Uploading the file in a DocumentRevision record that is related to the Document.
                            $documentRevisionResult = $objSCP->set_document_revision($documentRevisionData);
                    
                            if ($documentRevisionResult != null) {
                                $redirect_url = $_REQUEST['scp_current_url'] . '&msg=true' . '&id=' . $documentEntryId . ($action ? '&action=detail' : '');
                                wp_redirect($redirect_url);
                                exit;
                            }
                        }
                    }
                }
            }
        }
    }
}

/**
 * Action that manages creating and modificating Contacts/Accounts Relationships records
 */
add_action('admin_post_single_stic_relationships', 'prefix_admin_single_stic_relationships'); 
add_action('admin_post_nopriv_single_stic_relationships', 'prefix_admin_single_stic_relationships'); 
function prefix_admin_single_stic_relationships()
{
    if ($_REQUEST['stic-action'] == 'detail') {
        $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_relationships";
        wp_redirect($redirectUrl);
        exit;
    } else {
        switch (getDestinationModule()) {
            case 'Accounts':
                $moduleName = 'stic_Accounts_Relationships'; 
                break;
            case 'Contacts':
                $moduleName = 'stic_Contacts_Relationships'; 
                break;
        }

        $objSCP = SugarRestApiCall::getObjSCP();

        foreach ($_REQUEST as $key => $value) {
            $moduleData[$key] = stripslashes_deep($value);
        }

        $action = $moduleData['stic-action'];
        unset($moduleData['stic-action']); 
        if ($action === 'delete') {
            $moduleData['deleted'] = 1;
        }
        $isUpdate = $objSCP->set_entry($moduleName, $moduleData);
        if ($isUpdate != null) {

            if ($action === 'delete') {
                $redirect_url = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_relationships&msgDelete=true";
            } else {
                $redirect_url = $_REQUEST['scp_current_url'] . '&msg=true' . '&id=' . $isUpdate . ($action ? '&action=detail' : '');
            }
            wp_redirect($redirect_url);
            exit;
        }
    }
}

/**
 * Action that manages creating and modificating Payment Commitments records
 */
add_action('admin_post_single_stic_payment_commitments', 'prefix_admin_single_stic_payment_commitments'); 
add_action('admin_post_nopriv_single_stic_payment_commitments', 'prefix_admin_single_stic_payment_commitments'); 
function prefix_admin_single_stic_payment_commitments() 
{
    if ($_REQUEST['stic-action'] == 'detail') {
        $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_payment_commitments";
        wp_redirect($redirectUrl);
        exit;
    } else {
        $moduleName = 'stic_Payment_Commitments'; 

        $objSCP = SugarRestApiCall::getObjSCP();

        foreach ($_REQUEST as $key => $value) {
            $moduleData[$key] = is_array($value) ? '^' . implode('^,^', stripslashes_deep($value)) . '^' : stripslashes_deep($value);
        }

        $action = $moduleData['stic-action'];
        unset($moduleData['stic-action']); 
        if ($action === 'delete') {
            $moduleData['deleted'] = 1;
        }
        $isUpdate = $objSCP->set_entry($moduleName, $moduleData);
        if ($isUpdate != null) {
            if ($action === 'delete') {
                $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_payment_commitments&msgDelete=true";
            } else {
                $redirectUrl = $_REQUEST['scp_current_url'] . '&msg=true' . '&id=' . $isUpdate . ($action ? '&action=detail' : '');
            }
            wp_redirect($redirectUrl);
            exit;
        }
    }
}

/**
 * Action that manages creating and modificating Payments records
 */
add_action('admin_post_single_stic_payments', 'prefix_admin_single_stic_payments'); 
add_action('admin_post_nopriv_single_stic_payments', 'prefix_admin_single_stic_payments'); 
function prefix_admin_single_stic_payments() 
{
    if ($_REQUEST['stic-action'] == 'detail') {
        $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_payments";
        wp_redirect($redirectUrl);
        exit;
    } else {
        $moduleName = 'stic_Payments'; 

        $objSCP = SugarRestApiCall::getObjSCP();

        foreach ($_REQUEST as $key => $value) {
            $moduleData[$key] = is_array($value) ? '^' . implode('^,^', stripslashes_deep($value)) . '^' : stripslashes_deep($value);
        }
        $action = $moduleData['stic-action'];

        unset($moduleData['stic-action']); 
        if ($action === 'delete') {
            $moduleData['deleted'] = 1;
        }
        $isUpdate = $objSCP->set_entry($moduleName, $moduleData);
        if ($isUpdate != null) {
            if ($action === 'delete') {
                $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_payments&msgDelete=true";
            } else {
                $redirectUrl = $_REQUEST['scp_current_url'] . '&msg=true' . '&id=' . $isUpdate . ($action ? '&action=detail' : '');
            }
            wp_redirect($redirectUrl);
            exit;
        }
    }
}

/**
 * Action that manages creating and modificating Registrations records
 */
add_action('admin_post_single_stic_registrations', 'prefix_admin_single_stic_registrations');
add_action('admin_post_nopriv_single_stic_registrations', 'prefix_admin_single_stic_registrations');

/**
 * ¿El usuario logueado ya tiene una inscripción ACTIVA (no cancelada) para el
 * evento indicado? Se usa tanto en la vista (deshabilitar el formulario) como en
 * el guardado (rechazar duplicados aunque la vista se salte: doble submit, POST
 * directo o consulta de la vista fallida).
 */
/**
 * IDs de los eventos en los que el usuario logueado tiene una inscripción ACTIVA
 * (no cancelada). Devuelve un array de strings (puede estar vacío).
 */
function prefix_user_active_event_ids($objSCP)
{
    $module = getDestinationModule();
    $relationship = ($module === 'Accounts') ? 'stic_registrations_accounts' : 'stic_registrations_contacts';

    $ids = array();
    $myRegs = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => $module,
        'module_id' => $_SESSION['scp_user_id'],
        'link_field_name' => $relationship,
        'related_fields' => array('id', 'status'),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
    ));
    if (!is_array($myRegs)) {
        return $ids;
    }
    foreach ($myRegs as $reg) {
        $regStatus = $reg->name_value_list->status->value ?? null;
        if ($regStatus === 'cancelled') {
            continue;
        }
        $regEvents = $objSCP->getRelatedElementsForLoggedUser(array(
            'module_name' => 'stic_Registrations',
            'module_id' => $reg->id,
            'link_field_name' => 'stic_registrations_stic_events',
            'related_fields' => array('id'),
            'related_module_link_name_to_fields_array' => array(),
            'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 1,
        ));
        if (is_array($regEvents)) {
            foreach ($regEvents as $re) {
                if (!empty($re->id)) {
                    $ids[] = $re->id;
                }
            }
        }
    }
    return array_values(array_unique($ids));
}

function prefix_user_has_active_registration($objSCP, $eventId)
{
    if (empty($eventId)) {
        return false;
    }
    return in_array($eventId, prefix_user_active_event_ids($objSCP), true);
}

function prefix_admin_single_stic_registrations()
{
    if ($_REQUEST['stic-action'] == 'detail') {
        $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_registrations";
        wp_redirect($redirectUrl);
        exit;
    } else {
        $moduleName = 'stic_Registrations';

        $objSCP = SugarRestApiCall::getObjSCP();

        foreach ($_REQUEST as $key => $value) {
            $moduleData[$key] = is_array($value) ? '^' . implode('^,^', stripslashes_deep($value)) . '^' : stripslashes_deep($value);
        }
        $action = $moduleData['stic-action'];

        unset($moduleData['stic-action']);
        if ($action === 'delete') {
            $moduleData['deleted'] = 1;
        }

        // GUARD anti-duplicado: al CREAR una inscripción (sin id) a un evento, si
        // ya existe una inscripción activa del usuario para ese evento, NO se crea
        // otra; se redirige a la pantalla de inscripción que mostrará el aviso
        // "Ya estás inscrito".
        if ($action !== 'delete' && empty($moduleData['id'])) {
            $eventId = $moduleData['stic_registrations_stic_eventsstic_events_ida'] ?? '';
            if (prefix_user_has_active_registration($objSCP, $eventId)) {
                $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0]
                    . "?internalpage=single_stic_registrations&action=create&from=stic_events&id=" . urlencode($eventId);
                wp_redirect($redirectUrl);
                exit;
            }
        }

        $isUpdate = $objSCP->set_entry($moduleName, $moduleData);
        if ($isUpdate != null) {
            // Inscripción creada/editada/borrada → invalida la caché del calendario
            // para que la home y el calendario reflejen el cambio al instante.
            if (function_exists('sticpa_calendar_flush_cache')) {
                sticpa_calendar_flush_cache();
            }
            if ($action === 'delete') {
                $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_registrations&msgDelete=true";
            } elseif ($action == 'payment') {
                $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=single_stic_payments_form&registrationId=".$isUpdate."&eventId=".$moduleData['stic_registrations_stic_eventsstic_events_ida'];
            } else {
                $redirectUrl = $_REQUEST['scp_current_url'] . '&msg=true' . '&id=' . $isUpdate . ($action ? '&action=detail' : '');
            }
            wp_redirect($redirectUrl);
            exit;
        }
    }
}

/**
 * Action that manages creating and modificating Job Applications records.
 * 
 * (Optional) If a Document ID is provided, the action will automatically related the Job Application to the Document record.
 * This is used to select an available Curriculum Vitae of the user when registering to a Job Offer.
 */
add_action('admin_post_single_stic_job_applications', 'prefix_admin_single_stic_job_applications'); 
add_action('admin_post_nopriv_single_stic_job_applications', 'prefix_admin_single_stic_job_applications'); 
function prefix_admin_single_stic_job_applications() 
{
    if ($_REQUEST['stic-action'] == 'detail') {
        $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_job_applications";
        wp_redirect($redirectUrl);
        exit;
    } else {
        $moduleName = 'stic_Job_Applications'; 

        $objSCP = SugarRestApiCall::getObjSCP();

        foreach ($_REQUEST as $key => $value) {
            $moduleData[$key] = is_array($value) ? '^' . implode('^,^', stripslashes_deep($value)) . '^' : stripslashes_deep($value);
        }
        $action = $moduleData['stic-action'];
        unset($moduleData['stic-action']); // to avoid passing the value to the API
        if ($action === 'delete') {
            $moduleData['deleted'] = 1;
        }
        $isUpdate = $objSCP->set_entry($moduleName, $moduleData);
        if ($isUpdate != null) {
            if ($documentID = $_REQUEST['selected_document']) {
                $resultRelationship = $objSCP->set_relationship('Documents', $documentID, 'stic_job_applications_documents', array($isUpdate));
            }


            if ($action === 'delete') {
                $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_job_applications&msgDelete=true";
            } else {
                $redirectUrl = $_REQUEST['scp_current_url'] . '&msg=true' . '&id=' . $isUpdate .  '&action=detail';
            }
            wp_redirect($redirectUrl);
            exit;
        }
    }
}

/**
 * Action used for redirecting to the registration page of the provided Event ID
 */
add_action('admin_post_single_stic_events', 'prefix_admin_single_stic_events'); 
add_action('admin_post_nopriv_single_stic_events', 'prefix_admin_single_stic_events'); 
function prefix_admin_single_stic_events() 
{
    if ($_REQUEST['id']) {
        $redirect_url = explode('?', $_REQUEST['scp_current_url'], 2)[0] .'/?internalpage=single_stic_registrations&action=create&eventId='.$_REQUEST['id'];
    } else {
        $redirect_url = explode('?', $_REQUEST['scp_current_url'], 2)[0] .'/?internalpage=list_stic_registrations';
    }
    wp_redirect($redirect_url);
    exit;
}

/**
 * Action used for redirecting to the job applications page of the provided Job Offer ID
 */
add_action('admin_post_single_stic_job_offers', 'prefix_admin_single_stic_job_offers'); 
add_action('admin_post_nopriv_single_stic_job_offers', 'prefix_admin_single_stic_job_offers'); 
function prefix_admin_single_stic_job_offers() 
{
    if ($_REQUEST['id']) {
        $redirect_url = explode('?', $_REQUEST['scp_current_url'], 2)[0] .'/?internalpage=single_stic_job_applications&action=create&offerId='.$_REQUEST['id'];
    } else {
        $redirect_url = explode('?', $_REQUEST['scp_current_url'], 2)[0] .'/?internalpage=list_stic_job_offers';
    }
    wp_redirect($redirect_url);
    exit;
}

/**
 * Action used for managing the update of the user's password.
 */
add_action('admin_post_single_stic_password_change', 'prefix_admin_single_stic_password_change'); 
add_action('admin_post_nopriv_single_stic_password_change', 'prefix_admin_single_stic_password_change'); 
function prefix_admin_single_stic_password_change() 
{ 
    $objSCP = SugarRestApiCall::getObjSCP();

    $userId = $_SESSION['scp_user_adult'] ? $_SESSION['scp_user_id'] : $_SESSION['scp_tutor_user_id'];
    $getContactInfo = $objSCP->getUserInformation($userId)->entry_list[0]->name_value_list;
    $password = $getContactInfo->stic_pa_password_c->value;

    if ($password == stripslashes_deep($_REQUEST['add-profile-old-password'])) {
        if (stripslashes_deep($_REQUEST['add-profile-new-password']) == stripslashes_deep($_REQUEST['add-profile-confirm-password'])) {

            $new_password = stripslashes_deep($_REQUEST['add-profile-new-password']);
            $updateUserInfo = array(
                'id' => $userId,
                'stic_pa_password_c' => $new_password,
            );

            $isChangePassword = $objSCP->set_entry(getDestinationModule(), $updateUserInfo);

            if ($isChangePassword != null) {
                $redirect_url = $_REQUEST['scp_current_url'] . '&success=true';
            } else {
                $redirect_url = $_REQUEST['scp_current_url'] . '&error=1';
            }
        } else {
            $redirect_url = $_REQUEST['scp_current_url'] . '&error=1';
        }
    } else {
        $redirect_url = $_REQUEST['scp_current_url'] . '&error=2';
    }
    wp_redirect($redirect_url);
    exit;

}

/**
 * Nombre "seguro" para el correo: nombre de pila + inicial del primer apellido
 * ("Juan Pérez García" -> "Juan P."). El correo puede acabar reenviado, quedar
 * en un buzón compartido o abrirse en una pantalla ajena, así que enseña lo
 * justo para que la persona se reconozca sin exponer el apellido completo.
 *
 * Si solo hay una palabra (habitual en Cuentas, que son organizaciones y no
 * personas) se devuelve tal cual: no hay apellido que enmascarar.
 */
function sticpa_mask_display_name($name)
{
    $name = trim((string) $name);
    if ($name === '') {
        return '';
    }
    $parts = preg_split('/\s+/', $name);
    if (count($parts) < 2) {
        return $parts[0];
    }
    return $parts[0] . ' ' . mb_strtoupper(mb_substr($parts[1], 0, 1)) . '.';
}

/**
 * Email HTML (branded, mobile-first) con las DOS formas de entrar: el botón del
 * enlace mágico primero (es lo más fácil para casi todo el mundo) y, debajo,
 * el código de 6 cifras para quien prefiera teclearlo o vaya a entrar desde la
 * app MCM (ver `inc/stic-otp.php` para el porqué del código).
 *
 * Colores de marca MCM: azul #1c6fb3, magenta #9d1e74. Estilos inline porque
 * los clientes de correo no respetan <style> ni CSS externo.
 */
function sticpa_magic_email_html($name, $link, $portalName, $code = '')
{
    $name = esc_html(sticpa_mask_display_name($name));
    $portalName = esc_html($portalName);
    $linkAttr = esc_url($link);
    $minutos = (int) round(sticpa_otp_ttl() / MINUTE_IN_SECONDS);
    $saludo = $name !== '' ? sprintf(__('Hola %s,', 'sticpa'), $name) : __('Hola,', 'sticpa');
    $intro = __('Has solicitado entrar en el área privada de MCM Comunica.', 'sticpa');
    $btn = __('Acceder a mi área privada', 'sticpa');
    $otroModo = __('Si te resulta más fácil, o si vas a entrar desde la app, usa este código:', 'sticpa');
    $expira = sprintf(__('Por seguridad, el código y el enlace caducan en %d minutos. Si caducan, pídelos de nuevo desde la web.', 'sticpa'), $minutos);
    $fallback = __('¿El botón no funciona? Copia y pega esta dirección en tu navegador:', 'sticpa');
    $ignore = __('Si no has solicitado este acceso, puedes ignorar este correo. Nadie puede entrar en tu cuenta sin este mensaje.', 'sticpa');

    // Bloque del código. Se dibuja con una tabla y tamaños en px porque es lo
    // único que respetan Outlook y Gmail; el espacio de «123 456» es literal
    // (letter-spacing solo, sin el espacio, se pierde en varios clientes).
    $codeBlock = '';
    if ($code !== '') {
        $codeBlock = '
          <p style="margin:0 0 10px;text-align:center;color:#9ca3af;font-size:13px;">' . esc_html($otroModo) . '</p>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
            <tr><td align="center" style="background:#f4f6fb;border:1px solid #e3e8f2;border-radius:14px;padding:18px 12px;">
              <div style="font-size:13px;color:#6b7280;letter-spacing:.04em;text-transform:uppercase;margin-bottom:8px;">'
                . esc_html__('Tu código de acceso', 'sticpa') . '</div>
              <div style="font-size:38px;line-height:1.1;font-weight:bold;color:#1c6fb3;letter-spacing:.12em;font-family:Consolas,Menlo,Monaco,monospace;">'
                . esc_html(sticpa_otp_format_code($code)) . '</div>
              <div style="font-size:13px;color:#6b7280;margin-top:10px;">'
                . esc_html__('Escríbelo en la pantalla de acceso.', 'sticpa') . '</div>
            </td></tr>
          </table>';
    }

    // Insignia "MCM" del pie. Es un distintivo dibujado con HTML/CSS (círculo +
    // degradado de marca), no una imagen: un email no puede tirar de un archivo
    // del repo, necesita una URL https ya publicada, y no había ninguna URL de
    // logo configurada. Si en algún momento se sube el logo real a un sitio
    // público, esta tabla se sustituye por un <img src="...">.
    $footerBadge = '
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 10px;">
            <tr><td width="34" height="34" align="center" valign="middle" style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#1c6fb3,#9d1e74);color:#ffffff;font-size:11px;font-weight:bold;letter-spacing:.02em;font-family:Arial,Helvetica,sans-serif;">MCM</td></tr>
          </table>';

    return '
<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f6fb;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 6px 24px rgba(21,36,71,.08);font-family:Arial,Helvetica,sans-serif;">
        <tr><td style="background:linear-gradient(135deg,#1c6fb3 0%,#6c4b9e 52%,#9d1e74 100%);padding:28px 28px;color:#ffffff;">
          <div style="font-size:13px;letter-spacing:.08em;text-transform:uppercase;opacity:.9;">' . $portalName . '</div>
          <div style="font-size:22px;font-weight:bold;margin-top:6px;">' . esc_html__('Tu acceso', 'sticpa') . '</div>
        </td></tr>
        <tr><td style="padding:28px 28px 8px;color:#1f2937;font-size:16px;line-height:1.55;">
          <p style="margin:0 0 14px;">' . $saludo . '</p>
          <p style="margin:0 0 22px;color:#4b5563;">' . esc_html($intro) . '</p>
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 22px;"><tr><td align="center" style="border-radius:12px;background:linear-gradient(135deg,#1c6fb3,#9d1e74);">
            <a href="' . $linkAttr . '" style="display:inline-block;padding:14px 28px;color:#ffffff;text-decoration:none;font-weight:bold;font-size:16px;border-radius:12px;">' . esc_html($btn) . '</a>
          </td></tr></table>
          ' . $codeBlock . '
          <p style="margin:0 0 18px;color:#6b7280;font-size:13px;line-height:1.5;">' . esc_html($expira) . '</p>
          <p style="margin:0 0 6px;color:#6b7280;font-size:13px;">' . esc_html($fallback) . '</p>
          <p style="margin:0 0 22px;word-break:break-all;"><a href="' . $linkAttr . '" style="color:#1c6fb3;font-size:13px;">' . $linkAttr . '</a></p>
        </td></tr>
        <tr><td style="padding:18px 28px 26px;border-top:1px solid #eef0f5;color:#9ca3af;font-size:12px;line-height:1.5;">' . esc_html($ignore) . '</td></tr>
        <tr><td align="center" style="padding:4px 28px 26px;">'
          . $footerBadge . '
          <div style="color:#9ca3af;font-size:12px;">comunica@movimientoconsolacion.com</div>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body></html>';
}

/**
 * Pide acceso: la persona escribe su email y le mandamos UN correo con las dos
 * formas de entrar (código de 6 cifras + enlace mágico). Ver `inc/stic-otp.php`
 * para por qué hacen falta las dos.
 *
 * Sustituye al antiguo "he olvidado mi contraseña": ya no hay recuperación de
 * contraseña por correo. Se entra por aquí y, una vez dentro, quien quiera
 * contraseña se la pone en su perfil (`single_stic_password_change`).
 *
 * `stic_forgot_password` se mantiene registrado como alias del nombre nuevo
 * para que un formulario ya pintado en una pestaña abierta no se rompa al
 * desplegar. Se puede quitar dentro de unas semanas.
 */
add_action('admin_post_sticpa_send_access', 'sticpa_handle_send_access');
add_action('admin_post_nopriv_sticpa_send_access', 'sticpa_handle_send_access');
add_action('admin_post_stic_forgot_password', 'sticpa_handle_send_access');
add_action('admin_post_nopriv_stic_forgot_password', 'sticpa_handle_send_access');
function sticpa_handle_send_access()
{
    $email = sanitize_email(stripslashes_deep($_REQUEST['forgot-password-email-address'] ?? ''));
    $returnUrl = sticpa_auth_return_url();

    // Primero se pregunta y DESPUÉS se apunta: al revés, la petición que hace
    // de tope se contaría a sí misma y solo saldrían 4 de los 5 envíos.
    // Se apunta siempre, exista o no el email: si solo contáramos los envíos
    // reales, mirar qué direcciones gastan cupo sería una forma de enumerar el CRM.
    $allowed = sticpa_otp_send_allowed($email);
    sticpa_otp_note_send($email);

    // Base ABSOLUTA para el enlace (REQUEST_URI es solo la ruta, sin dominio:
    // daría un enlace relativo que el cliente de correo no puede abrir).
    $areaUrl = get_option('sticpa_scp_area_url');
    if (empty($areaUrl)) {
        $areaUrl = home_url(strtok($returnUrl, '?'));
    }

    if ($allowed && is_email($email)) {
        $objSCP = SugarRestApiCall::getObjSCP();
        foreach (sticpa_modules_to_try() as $module) {
            $contact = $objSCP->getContactByEmail($email, $module);
            if ($contact) {
                // El enlace del correo pasa por la ruta puente `/app/acceso`:
                // si quien lo pulsa tiene la app MCM instalada, se abre ahí; si
                // no, WordPress redirige al área privada de siempre. Ver
                // `inc/stic-app-links.php`.
                //
                // Se firma con la caducidad del código para que el correo pueda
                // dar UNA fecha de caducidad y sea verdad para las dos vías.
                $link = sticpa_app_link_url(
                    sticpa_generate_magic_link($areaUrl, $module, $contact->id, sticpa_otp_ttl())
                );
                $code = sticpa_otp_issue($email, $module, $contact->id);
                $name = $contact->name_value_list->name->value ?? '';

                $portalName = get_option('sticpa_scp_name') ?: __('Tu área privada', 'sticpa');
                $fromEmail = get_option('admin_email');
                $headers = array(
                    'Content-Type: text/html; charset=UTF-8',
                    'From: ' . $portalName . ' <' . $fromEmail . '>',
                    'Reply-To: ' . $portalName . ' <' . $fromEmail . '>',
                );
                $subject = sprintf(__('Tu acceso a %s', 'sticpa'), $portalName);
                $body = sticpa_magic_email_html($name, $link, $portalName, $code);

                wp_mail($email, $subject, $body, $headers);
                break; // found in this module; stop
            }
        }
    }

    // Recordamos a quién se lo hemos mandado SOLO para poder pre-rellenar la
    // pantalla del código y enseñar el correo enmascarado. No autoriza nada.
    if (is_email($email)) {
        $_SESSION['sticpa_otp_email'] = $email;
    }

    // Redirección genérica pase lo que pase: nunca se revela si el email existe
    // en el CRM. `wp_safe_redirect` porque `scp_current_url` viene del cliente
    // y con `wp_redirect` se podía saltar a un host externo (plan 005).
    $args = array('sticpa_code' => '1');
    if (!$allowed) {
        $args['otp_error'] = 'throttled';
    }
    wp_safe_redirect(add_query_arg($args, $returnUrl));
    exit;
}

/**
 * Verifica el código de 6 cifras y, si cuadra, abre sesión igual que haría el
 * enlace mágico (misma sesión, misma duración: no hay accesos de primera y de
 * segunda según por dónde entres).
 */
add_action('admin_post_sticpa_verify_code', 'sticpa_handle_verify_code');
add_action('admin_post_nopriv_sticpa_verify_code', 'sticpa_handle_verify_code');
function sticpa_handle_verify_code()
{
    $returnUrl = sticpa_auth_return_url();

    // El email puede venir del formulario (campo oculto o visible). Es lo que
    // permite pedir el código en el ordenador y teclearlo en la app; no da
    // ninguna ventaja a quien lo manipule, porque el código está guardado por
    // email y el contador de fallos también.
    $email = sanitize_email(stripslashes_deep($_REQUEST['sticpa_otp_email'] ?? ''));
    if (!is_email($email) && !empty($_SESSION['sticpa_otp_email'])) {
        $email = (string) $_SESSION['sticpa_otp_email'];
    }
    $code = sticpa_otp_normalize_code(stripslashes_deep($_REQUEST['sticpa_otp_code'] ?? ''));

    $locked = sticpa_otp_is_locked($email);
    $valid = $locked ? false : sticpa_otp_verify($email, $code);

    if ($valid) {
        list($module, $contactId) = $valid;
        $result = SugarRestApiCall::getObjSCP()->getRecordDetail(
            $contactId,
            $module,
            sticpa_session_select_fields($module)
        );
        if (isset($result->entry_list[0]) && $result->entry_list[0] != null) {
            sticpa_establish_session($result->entry_list[0], $module);
            unset($_SESSION['sticpa_otp_email']);

            $areaUrl = get_option('sticpa_scp_area_url');
            wp_safe_redirect($areaUrl ? $areaUrl : strtok($returnUrl, '?'));
            exit;
        }
        // Código correcto pero el CRM no devuelve la ficha: es un fallo nuestro,
        // no de quien lo teclea. El código ya se ha consumido, así que hay que
        // pedir otro.
        wp_safe_redirect(add_query_arg(array('sticpa_code' => '1', 'otp_error' => 'crm'), $returnUrl));
        exit;
    }

    // Mismo mensaje para código incorrecto, caducado o email que no existe: si
    // se distinguieran, el formulario diría qué correos están registrados.
    wp_safe_redirect(add_query_arg(array(
        'sticpa_code' => '1',
        'otp_error' => $locked || sticpa_otp_is_locked($email) ? 'locked' : 'bad',
    ), $returnUrl));
    exit;
}

/**
 * URL de la pantalla de acceso a la que vuelven los handlers. Sale del campo
 * `scp_current_url` del formulario, así que se limpia: solo se conserva la
 * RUTA. Cualquier host, esquema o query que venga del cliente se descarta, que
 * es justo por donde se colaba el open redirect.
 */
function sticpa_auth_return_url()
{
    $raw = (string) ($_REQUEST['scp_current_url'] ?? '');
    $path = parse_url(stripslashes($raw), PHP_URL_PATH);
    if (!is_string($path) || $path === '' || $path[0] !== '/') {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    }
    if (!is_string($path) || $path === '' || $path[0] !== '/') {
        $path = '/';
    }
    return add_query_arg('stic_auth', '1', $path);
}

/**
 * Action for managing the unsubscriptions of users.
 */
add_action('admin_post_single_stic_unsubscribe', 'prefix_admin_single_stic_unsubscribe'); 
add_action('admin_post_nopriv_single_stic_unsubscribe', 'prefix_admin_single_stic_unsubscribe'); 
function prefix_admin_single_stic_unsubscribe() 

{
    ##### customizable data ########################
    $moduleName = getDestinationModule(); // module name where to save/retrieve data
    ################################################

    $objSCP = SugarRestApiCall::getObjSCP();

    $moduleData['id'] = $_SESSION['scp_tutor_user_id'] ? $_SESSION['scp_tutor_user_id'] : $_SESSION['scp_user_id'];
    $moduleData['stic_pa_username_c'] = ''; 
    $moduleData['stic_pa_password_c'] = '';

    $isUpdate = $objSCP->set_entry($moduleName, $moduleData);

    if ($isUpdate != null) {
        $redirect_url = $_REQUEST['scp_current_url'] . '&logout=true';
    } else {
        $redirect_url = $_REQUEST['scp_current_url'] . '&msg=error';
    }
    wp_redirect($redirect_url);
    exit;
}


/**
 * Action for managing the registration of new users
 */
add_action('admin_post_single_stic_signup', 'prefix_admin_single_stic_signup'); 
add_action('admin_post_nopriv_single_stic_signup', 'prefix_admin_single_stic_signup'); 
function prefix_admin_single_stic_signup() 
{
    $objSCP = SugarRestApiCall::getObjSCP();

    foreach ($_REQUEST as $key => $value) {
        if (!empty($value)) {
            $fields[$key] = stripslashes_deep($_REQUEST[$key]);
            $addSignUp[$key] = $fields[$key];
        } 
    }

    $checkUserExists = $objSCP->getUserExists($fields['stic_pa_username_c']);
    $getAllEmails = $objSCP->getAllEmail();

    if (($checkUserExists == true) && (in_array($fields['email1'], $getAllEmails) == true)) {
        $redirect_url = $_REQUEST['scp_current_url'] . '&msg=userandemailexists';
    } else if ($checkUserExists == true) {
        $redirect_url = $_REQUEST['scp_current_url'] . '&msg=userexists';
    } else if (in_array($fields['email1'], $getAllEmails) == true) {
        $redirect_url = $_REQUEST['scp_current_url'] . '&msg=emailexists';
    } else {
        $isSignUp = $objSCP->set_entry(getDestinationModule(), $addSignUp);
        if ($isSignUp != null) {
            $redirect_url = explode('?', $_REQUEST['scp_current_url'], 2)[0] .'/?msg=true';
        }
    }
    wp_redirect($redirect_url);
    exit;
}

/**
 * Helper function for downloading attached files from the Documents module
 *
 * @param String $documentId
 * @return void
 */
function download_document($documentId) {
    $objSCP = SugarRestApiCall::getObjSCP();

    $resultDocument = $objSCP->getRecordDetail($documentId, 'Documents', array('document_revision_id'));

    $documentRevisionId = $resultDocument->entry_list[0]->name_value_list->document_revision_id->value;

    $document = $objSCP->getDocumentRevision($documentRevisionId);
    $fileData = $document->document_revision->file;
    $filename = $document->document_revision->filename;
    // $fileMime = $resultMime->entry_list[0]->name_value_list->file_mime_type->value;

    if (ob_get_level()) {
        ob_end_clean();
    }

    $decodedFileData = base64_decode($fileData);

    header('Expires: 0');
    header('Pragma: public');
    header('Cache-Control: must-revalidate');
    header('Content-Length: ' . strlen($decodedFileData));
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $decodedFileData;
}

/**
 * Ruta en disco de la miniatura cacheada de la foto de perfil de un contacto.
 * La clave es md5(id) para no exponer el id del CRM en el nombre del archivo.
 * El directorio photo-cache/ puede vaciarse sin riesgo: se regenera solo.
 *
 * @param String $userId
 * @return String
 */
function sticpa_profile_photo_cache_path($userId)
{
    $upload = wp_upload_dir();
    return $upload['basedir'] . '/stic-uploads/photo-cache/' . md5((string) $userId) . '.jpg';
}

/**
 * Endpoint que sirve la foto de perfil como miniatura JPEG cacheada en disco,
 * en lugar de incrustarla como data URI base64 en el HTML de las páginas.
 *
 * Sirve SIEMPRE la foto del usuario EN SESIÓN ($_SESSION['scp_user_id']):
 * el id nunca viene del request.
 */
add_action('admin_post_stic_profile_photo', 'prefix_admin_stic_profile_photo');
add_action('admin_post_nopriv_stic_profile_photo', 'prefix_admin_stic_profile_photo');
function prefix_admin_stic_profile_photo()
{
    if (empty($_SESSION['scp_user_id'])) {
        status_header(403);
        exit;
    }
    $userId = $_SESSION['scp_user_id'];
    $cachePath = sticpa_profile_photo_cache_path($userId);

    // Miniatura cacheada y fresca (< 24h) → se sirve directamente, sin CRM.
    if (!is_file($cachePath) || (time() - (int) filemtime($cachePath)) >= DAY_IN_SECONDS) {
        $objSCP = SugarRestApiCall::getObjSCP();
        $image = $objSCP->get_image(array('id' => $userId, 'field' => 'photo'));
        $binary = !empty($image->image_data->data) ? base64_decode($image->image_data->data) : false;
        if (empty($binary)) {
            // Sin foto en el CRM: el <img> mostrará el placeholder vía onerror.
            status_header(404);
            exit;
        }

        $cacheDir = dirname($cachePath);
        if (!is_dir($cacheDir)) {
            wp_mkdir_p($cacheDir);
        }

        // wp_get_image_editor() necesita un archivo de origen en disco.
        $tmpPath = $cachePath . '.tmp';
        if (file_put_contents($tmpPath, $binary) === false) {
            status_header(500);
            exit;
        }

        $resized = false;
        $editor = wp_get_image_editor($tmpPath);
        if (!is_wp_error($editor)) {
            $editor->resize(400, 400, true);
            $editor->set_quality(82);
            $saved = $editor->save($cachePath, 'image/jpeg');
            $resized = !is_wp_error($saved);
        }
        if ($resized) {
            @unlink($tmpPath);
        } else {
            // Fallback integrado (STOP del plan resuelto por diseño): si el
            // hosting no tiene GD/Imagick (WP_Error) o el guardado falla, se
            // cachea y sirve el binario ORIGINAL sin redimensionar. Se pierde
            // la reducción a 400×400, pero la página deja igualmente de
            // incrustar base64 y las siguientes peticiones salen de disco.
            if (!@rename($tmpPath, $cachePath)) {
                @copy($tmpPath, $cachePath);
                @unlink($tmpPath);
            }
        }
        if (!is_file($cachePath)) {
            status_header(500);
            exit;
        }
    }

    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($cachePath));
    readfile($cachePath);
    exit;
}

/**
 * This helper function is used to upload files to a file field of a record
 *
 * @param String $fieldName
 * @param String $moduleName
 * @param String $id
 * @return void
 */
function upload_file_to_record($fieldName, $moduleName, $id) {
    if(isset($_FILES[$fieldName]['name']) && !empty($_FILES[$fieldName]['name'])) {
        define('KB', 1024);
        define('MB', 1048576);
        define('GB', 1073741824);
        define('TB', 1099511627776);
        if ($_FILES[$fieldName]['size'] > 6*MB) {
            return $_REQUEST['scp_current_url'] . '&msg=error_size';
        } else {
            $fileName = $_FILES[$fieldName]['name'];
            $tmpName  = $_FILES[$fieldName]['tmp_name'];
            $contents = file_get_contents($tmpName);
            $supported_types = array('image/gif', 'image/png', 'image/jpg', 'image/jpeg', 'image/svg');
            $arr_file_type = wp_check_filetype(basename($fileName));
            $uploaded_type = $arr_file_type['type'];

            if(in_array($uploaded_type, $supported_types)) {
                $contactData = array(
                    'id' => $id,
                    'module' => $moduleName,
                    'field' => $fieldName,
                    'file' => base64_encode($contents),
                    'filename' => $fileName,
                );
                $objSCP = SugarRestApiCall::getObjSCP();
                if (!$objSCP->set_image($contactData)) {
                    return $_REQUEST['scp_current_url'] . '&msg=error_upload';
                } else {
                    // Foto nueva subida: invalidar la miniatura cacheada del
                    // endpoint stic_profile_photo para que la próxima petición
                    // la regenere. Se invalida la del registro modificado y la
                    // del usuario en sesión (coinciden salvo al editar la ficha
                    // de otro miembro de la organización).
                    if ($fieldName === 'photo') {
                        $staleIds = array_unique(array_filter(array(
                            (string) $id,
                            (string) ($_SESSION['scp_user_id'] ?? ''),
                        )));
                        foreach ($staleIds as $staleId) {
                            @unlink(sticpa_profile_photo_cache_path($staleId));
                        }
                    }
                    return  $_REQUEST['scp_current_url'] . '&msg=true';
                }
            }
            else {
                return $_REQUEST['scp_current_url'] . '&msg=error_type';
            }
        }
    }
}

/* ============================================================================
 *  COMUNICA — Guardado de las páginas de edición por rol (perfil/laico/monitor)
 * ----------------------------------------------------------------------------
 *  Las tres páginas escriben campos del Contacto logueado. La de monitor además
 *  sube certificados (MAT/DAT/Delitos Sexuales/otros) como Documentos del CRM.
 *  Un único handler sirve a las tres: la foto y los certificados solo se
 *  procesan si vienen en la petición.
 * ========================================================================== */
add_action('admin_post_single_stic_comunica_perfil', 'prefix_comunica_save_contact');
add_action('admin_post_nopriv_single_stic_comunica_perfil', 'prefix_comunica_save_contact');
add_action('admin_post_single_stic_comunica_laico', 'prefix_comunica_save_contact');
add_action('admin_post_nopriv_single_stic_comunica_laico', 'prefix_comunica_save_contact');
add_action('admin_post_single_stic_comunica_monitor', 'prefix_comunica_save_contact');
add_action('admin_post_nopriv_single_stic_comunica_monitor', 'prefix_comunica_save_contact');

/**
 * Convierte los campos "solo año" (marcados por el motor de formularios con el
 * hidden stic_year_only_fields[]) de 'AAAA' a la fecha interna 'AAAA-01-01'
 * que espera el CRM. El 1 de enero es un convenio interno: al usuario nunca
 * se le muestra (el motor le enseña solo el año — clave 'yearOnly').
 */
function sticpa_apply_year_only_fields(&$moduleData)
{
    $yearFields = isset($_REQUEST['stic_year_only_fields']) ? (array) $_REQUEST['stic_year_only_fields'] : array();
    foreach ($yearFields as $fieldName) {
        $fieldName = sanitize_key($fieldName);
        if (!isset($moduleData[$fieldName])) {
            continue;
        }
        $year = trim((string) $moduleData[$fieldName]);
        if (preg_match('/^\d{4}$/', $year)) {
            $moduleData[$fieldName] = $year . '-01-01';
        }
    }
    unset($moduleData['stic_year_only_fields']);
}

function prefix_comunica_save_contact()
{
    $objSCP = SugarRestApiCall::getObjSCP();

    // El usuario solo puede editar SU propia ficha: id desde la sesión, nunca del request.
    $id = $_SESSION['scp_user_id'] ?? '';
    if (!$id) {
        wp_redirect(home_url());
        exit;
    }

    // Claves que no son campos del CRM (no enviar a set_entry).
    $skip = array('action', 'scp_current_url', 'stic-action', 'save', 'back', 'id', 'stic_year_only_fields', 'ds_option');
    $moduleData = array();
    foreach ($_REQUEST as $key => $value) {
        if (in_array($key, $skip, true)) {
            continue;
        }
        $moduleData[$key] = is_array($value)
            ? '^' . implode('^,^', stripslashes_deep($value)) . '^'
            : stripslashes_deep($value);
    }
    sticpa_apply_year_only_fields($moduleData);
    $moduleData['id'] = $id;

    $isUpdate = $objSCP->set_entry('Contacts', $moduleData);
    $msg = $isUpdate ? 'true' : 'error';

    if ($isUpdate) {
        // Foto de perfil (si se ha subido).
        if (isset($_FILES['photo']) && !empty($_FILES['photo']['name'])) {
            upload_file_to_record('photo', 'Contacts', $id);
        }
        // Certificados de monitor (cada uno opcional).
        $certs = array(
            'mat_file'  => array('label' => 'Título MAT',                  'category' => 'formacion_titulo_mat',   'flag' => 'ajmcm_mat_file_c'),
            'dat_file'  => array('label' => 'Título DAT',                  'category' => 'formacion_titulo_fa',    'flag' => 'ajmcm_dat_file_c'),
            'ds_file'   => array('label' => 'Certificado Delitos Sexuales', 'category' => 'legal_cert_delitos',     'flag' => 'ajmcm_cert_del_sex_c'),
            'form_file' => array('label' => 'Otros certificados',          'category' => 'formacion_titulo_otros', 'flag' => 'ajmcm_cert_files_c'),
        );
        foreach ($certs as $field => $meta) {
            if (isset($_FILES[$field]) && !empty($_FILES[$field]['name'])) {
                comunica_upload_certificate($objSCP, $id, $field, $meta);
            }
        }
    }

    $current = $_REQUEST['scp_current_url'] ?? home_url();
    wp_redirect($current . '&msg=' . $msg);
    exit;
}

/**
 * Sube un certificado como Documento del CRM, lo vincula al contacto y marca el
 * flag correspondiente. Modela el patrón de prefix_admin_single_stic_documents.
 */
function comunica_upload_certificate($objSCP, $contactId, $field, $meta)
{
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    if ($_FILES[$field]['size'] > 6 * 1048576) {
        return false;
    }
    $contents = file_get_contents($_FILES[$field]['tmp_name']);
    if ($contents === false) {
        return false;
    }
    $fileName = $_FILES[$field]['name'];

    // 1) Crear el Documento.
    $docId = $objSCP->set_entry('Documents', array(
        'document_name' => $meta['label'] . ' · ' . $fileName,
        'revision'      => '1',
        'status_id'     => 'Active',
        'date_input'    => date('Y-m-d'),
        'category_id'   => $meta['category'],
    ));
    if (!$docId) {
        return false;
    }

    // 2) Vincularlo al contacto.
    $objSCP->set_relationship('Documents', $docId, 'contacts', array($contactId));

    // 3) Subir el contenido como revisión.
    $objSCP->set_document_revision(array(
        'id'       => $docId,
        'file'     => base64_encode($contents),
        'filename' => $fileName,
    ));

    // 4) Marcar el flag del contacto (archivo subido = 1).
    $objSCP->set_entry('Contacts', array('id' => $contactId, $meta['flag'] => '1'));

    return $docId;
}

/**
 * Action that manages creating and modificating Contacts records.
 */
add_action('admin_post_single_stic_contacts', 'prefix_admin_single_stic_contacts');
add_action('admin_post_nopriv_single_stic_contacts', 'prefix_admin_single_stic_contacts'); 
function prefix_admin_single_stic_contacts() 
{
    if ($_REQUEST['stic-action'] == 'detail') {
        $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_contacts";
        wp_redirect($redirectUrl);
        exit;
    } else {
        $moduleName = 'Contacts'; 

        $objSCP = SugarRestApiCall::getObjSCP();

        foreach ($_REQUEST as $key => $value) {
            $moduleData[$key] = is_array($value) ? '^' . implode('^,^', stripslashes_deep($value)) . '^' : stripslashes_deep($value);
        }
        $action = $moduleData['stic-action'];

        unset($moduleData['stic-action']); // to avoid passing the value to the API

        $isUpdate = $objSCP->set_entry($moduleName, $moduleData);
        if ($isUpdate) {
            switch (getDestinationModule()) {
                case 'Accounts':
                    $relationship = 'accounts';
                    break;
                case 'Contacts':
                    $relationship = 'contacts';
                    break;
            }
            $relatedId = $_REQUEST[$relationship];
            // Relating the Document to the Contact record
            $resultRelationship = $objSCP->set_relationship('Contacts', $isUpdate, $relationship, array($relatedId));

            $redirect_url = $_REQUEST['scp_current_url'] . '&msg=true' . '&id=' . $isUpdate . ($action ? '&action=detail' : '');
        } else {
            $redirect_url = $_REQUEST['scp_current_url'] . '&msg=error' . '&id=' . $isUpdate . ($action ? '&action=detail' : '');
        }
        wp_redirect($redirect_url);
        exit;
    }
}
