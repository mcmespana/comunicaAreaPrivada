<?php

$relationshipField = 'stic_job_applications_contactscontacts_ida';
$formSettings['action'] = $_REQUEST['action'] ?? null;
$formSettings['title'] = __('Job application', 'sticpa'); // form title
$formSettings['moduleName'] = 'stic_Job_Applications'; // module name, case sensitive
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('The record has been successfully saved.', 'sticpa')); //messages that will be shown on the screen after processing the data

switch ($formSettings['action']) {
    case 'delete':
        $formSettings['submitButton'] = __('Delete', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonActions'] = array(
            'onclick' => 'confirmDelete',
        );
        break;
    case 'create':
    case 'edit':
        $formSettings['submitButton'] = __('Save', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonActions'] = array(
            'onclick' => 'verifyFormIsValid',
        );
        break;
    case 'detail':
        $formSettings['submitButton'] = __('Back', 'sticpa'); // submit button title. If not defined, it will be a read-only view
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
if ($formSettings['action'] == 'detail' || $formSettings['action'] == 'delete') {
    $fieldList[] = array('name' => 'name');
    $fieldList[] = array('name' => 'status');
    $fieldList[] = array('name' => 'start_date');
    $relatedDocuments = getDocuments('stic_Job_Applications', $_REQUEST['id'], 'stic_job_applications_documents');
    $count = 1;
    foreach($relatedDocuments as $key => $document) {
        $fieldList[] = array(
            'name' => $key,
            'defaultValue' => $document,
            'value' => $document,
            'label' => __('Document '.$count, 'sticpa'), # Optional if you want to change the label from the CRM
            'type' => 'text',
        );
        $count++;
    }
}
// $fieldList[] = array('name' => 'name');
$fieldList[] = array('name' => 'status', 'type' => 'hidden', 'defaultValue' => 'presented');
$fieldList[] = array('name' => 'start_date', 'type' => 'hidden');

if (isset($_REQUEST['from']) && $_REQUEST['from'] == 'stic_job_offers' && $formSettings['action'] != 'detail') {
    $offerId = $_REQUEST['id'];
    $_REQUEST['id'] = '';
} else {
    $offerId = $_REQUEST['offerId'] ?? '';
}

if ($offerId) {
    $offer = $objSCP->getRecordDetail($offerId, 'stic_Job_Offers')->entry_list[0]->name_value_list;
    $fieldList[] = array(
        'name' => 'stic_job_applications_stic_job_offers_name', 
        'type' => 'text', 
        'defaultValue' => $offer->name->value,
        'value' => $offer->name->value,
        'attributes' => array(    
            'disabled' => 'disabled',
        ),
    );
    $fieldList[] = array('name' => 'stic_job_applications_stic_job_offersstic_job_offers_ida', 'type' => 'hidden', 'value' => $offerId, 'defaultValue' => $offerId);
    // Optionally select a document to link it to the Job application
    $fieldList[] = array(
        'name' => 'selected_document', 
        'type' => 'select', 
        'label' => __('Select a Document', 'sticpa'),
        // Add a filter in last parameter. ex: (category_id = curriculumvitae)
        'selectValues' => getDocuments('Contacts', $_SESSION['scp_user_id'], 'documents', "")
    );
} else {
    if ($_REQUEST['action'] == 'detail' || $_REQUEST['action'] == 'delete') {
        $fieldList[] = array(
            'name' => 'stic_job_applications_stic_job_offers_name', 
            'type' => 'text',
        );
    } else {
        $fieldList[] = array(
            'name' => 'stic_job_applications_stic_job_offers_name', 
            'type' => 'select', 
            'selectValues' => getRelatedRecord($objSCP, 'stic_Job_Offers')
        );
    }
}

$data = $objSCP->getRecordDetail($_REQUEST['id'], $formSettings['moduleName'])->entry_list[0]->name_value_list;

$formSettings['fileName'] = basename(__FILE__, ".php"); //The page name, from the filename. Don't touch.

// If it's only detailview, disable fields
if ($formSettings['action'] == 'detail') {
    // $fieldList = array();
    $fieldList = array_map(function($elem) {
        $elem['attributes'] = array('disabled' => 'disabled');
        $elem['required'] = false;
       return $elem;
    }, $fieldList);
}

$html .= makeForm($fieldList, $formSettings, $data, $formSettings['action']);


$html.= '
<script>
document.addEventListener("DOMContentLoaded", function(event) { 
    $("#start_date").val(getCurrentDate());
});


</script>';

function getRelatedRecord($objSCP, $relatedModule) {
    $events = $objSCP->getRecordsModule($relatedModule);

    $listEvents = array();
    foreach ($events as $event) {
        $listEvents[$event->name_value_list->id->value] = $event->name_value_list->name->value;
    }
    return $listEvents;
}

function getDocuments($module, $recordId, $relationship, $filter = "") {
    $params = array(
        'module_name' => $module,
        "module_id" => $recordId, //Do not touch
        "link_field_name" => $relationship,
        "related_module_query" => $filter, //sql where conditions Attention, not all sql run ok
        "related_fields" => array('id', 'name'), //Do not touch
        "related_module_link_name_to_fields_array" => array(),
        "deleted" => 0, //show or not deleted elements (usually 0)
        "order_by" => "",
        "offset" => "",
        "limit" => 0,
    );
    $objSCP = SugarRestApiCall::getObjSCP();
    $getRelatedElements = $objSCP->getRelatedElementsForLoggedUser($params);
    $listDocuments = array('' => '');

    foreach($getRelatedElements as $elem) {
        $listDocuments[$elem->name_value_list->id->value] = $elem->name_value_list->name->value;
    }
    return $listDocuments;

}