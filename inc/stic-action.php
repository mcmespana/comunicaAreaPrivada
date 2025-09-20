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
    if (is_array($_REQUEST) && isset($_REQUEST['profile_selected_id']) && isset($_REQUEST['profile_selected_name'])) {
        // The $_REQUEST array will contain the ID and full name of the selected participant
        $requestUserId = $_REQUEST['profile_selected_id'];
        $requestUserName = $_REQUEST['profile_selected_name'];
        // This condition adds the option of using the Tutor as Participant
        $_SESSION['scp_tutor_is_user'] = false;
        $userId = $_SESSION['scp_tutor_user_id'] ?? $_REQUEST['scp_user_id'];
        if ($userId == $requestUserId) {
            $_SESSION['scp_tutor_is_user'] = true;
            $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=".$_REQUEST['default_page'];
            wp_redirect($redirectUrl);
        }

        // We set the current user ID and full name to the tutor, if it hasn't been assigned yet
        if (!isset($_SESSION['scp_tutor_user_id'])) {
            $_SESSION['scp_tutor_user_id'] = $_REQUEST['scp_user_id'];
        }
        if (!isset($_SESSION['scp_tutor_user_contact_name'])) {
            $_SESSION['scp_tutor_user_contact_name'] = $_REQUEST['scp_user_contact_name'];
        }
        // We assigned the selected participant ID and full name to the current user
        $_SESSION['scp_user_id'] = $requestUserId;
        $_SESSION['scp_user_contact_name'] = $requestUserName;
    }
    // When the session is expired, we redirect the user to the participant selection page. Otherwise default page
    if (!isset($_SESSION['scp_tutor_user_id'])) {
        $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=single_stic_profile_selection";
    } else {
        $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=".$_REQUEST['default_page'];
    }
    wp_redirect($redirectUrl);

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

    foreach ($_REQUEST as $key => $value) {
        $moduleData[$key] = is_array($value) ? '^' . implode('^,^', stripslashes_deep($value)) . '^' : stripslashes_deep($value);
    }
    $moduleData['id'] = $_SESSION['scp_tutor_user_id'];

    $isUpdate = $objSCP->set_entry($moduleName, $moduleData);
    if ($isUpdate) {
        $redirect_url = $_REQUEST['scp_current_url'] . '&msg=true';
    } else {
        $redirect_url = $_REQUEST['scp_current_url'] . '&msg=error';
    }
    wp_redirect($redirect_url);
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
        } else {
            $objSCP = SugarRestApiCall::getObjSCP();

            foreach ($_REQUEST as $key => $value) {
                $moduleData[$key] = is_array($value) ? '^' . implode('^,^', stripslashes_deep($value)) . '^' : stripslashes_deep($value);
            }
            $action = $moduleData['stic-action'];
            unset($moduleData['stic-action']); 
            if ($action === 'delete') {
                $moduleData['deleted'] = 1;
            }

            // Creating a Document Record
            $documentEntryId = $objSCP->set_entry('Documents', $moduleData);

            if ($documentEntryId != null) {

                if ($action === 'delete') {
                    $redirect_url = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_documents&msgDelete=true";
                    wp_redirect($redirect_url);
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
        }
    }
}

/**
 * Action that manages creating and modificating Registrations records
 */
add_action('admin_post_single_stic_registrations', 'prefix_admin_single_stic_registrations'); 
add_action('admin_post_nopriv_single_stic_registrations', 'prefix_admin_single_stic_registrations'); 
function prefix_admin_single_stic_registrations() 
{
    if ($_REQUEST['stic-action'] == 'detail') {
        $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_registrations";
        wp_redirect($redirectUrl);
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
        $isUpdate = $objSCP->set_entry($moduleName, $moduleData);
        if ($isUpdate != null) {
            if ($action === 'delete') {
                $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=list_stic_registrations&msgDelete=true";
            } elseif ($action == 'payment') {
                $redirectUrl = explode('?', $_REQUEST['scp_current_url'], 2)[0] . "?internalpage=single_stic_payments_form&registrationId=".$isUpdate."&eventId=".$moduleData['stic_registrations_stic_eventsstic_events_ida'];
            } else {
                $redirectUrl = $_REQUEST['scp_current_url'] . '&msg=true' . '&id=' . $isUpdate . ($action ? '&action=detail' : '');
            }
            wp_redirect($redirectUrl);
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

}

/**
 * Action used for managing the Password recoveryy functionality.
 */
add_action('admin_post_stic_forgot_password', 'prefix_admin_stic_forgot_password'); 
add_action('admin_post_nopriv_stic_forgot_password', 'prefix_admin_stic_forgot_password'); 
function prefix_admin_stic_forgot_password()
{
    $objSCP = SugarRestApiCall::getObjSCP();

    $checkUsername = stripslashes_deep($_REQUEST['forgot-password-username']);
    $checkEmailAddress = stripslashes_deep($_REQUEST['forgot-password-email-address']);

    $checkUserExists = $objSCP->getUserInformationByUsername($checkUsername);
    $username = $checkUserExists->entry_list[0]->name_value_list->stic_pa_username_c->value;
    $emailAddress = $checkUserExists->entry_list[0]->name_value_list->email1->value;
    $getAdminEmail = get_option('admin_email');

    if (($username == $checkUsername) && ($emailAddress == $checkEmailAddress)) {
        $password = $checkUserExists->entry_list[0]->name_value_list->stic_pa_password_c->value;
        $headers = "From: " . get_option('sticpa_scp_name') . " <$getAdminEmail>";
        $body = '';
        $body .= __('Your private area password is: ', 'sticpa') . ': ' . $password;
        $isSendEmail = wp_mail($emailAddress, __('Password recovery', 'sticpa'), $body, $headers);
        if ($isSendEmail == true) {
            $redirect_url = $_REQUEST['scp_current_url'] . '&success=true';
        } else {
            $redirect_url = $_REQUEST['scp_current_url'] . '&error=1';
        }
    } else if (($username == $checkUsername) && ($emailAddress != $checkEmailAddress)) {
        $redirect_url = $_REQUEST['scp_current_url'] . '&error=2';
    } else {
        $redirect_url = $_REQUEST['scp_current_url'] . '&error=3';
    }
    wp_redirect($redirect_url);

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
                    return  $_REQUEST['scp_current_url'] . '&msg=true';
                }
            }
            else {
                return $_REQUEST['scp_current_url'] . '&msg=error_type';
            }
        }
    }
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
    }
}
