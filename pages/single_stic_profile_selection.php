<?php

$pageSettings['fileName'] = basename(__FILE__, ".php"); // List name, from the filename. Don't touch.

$parentModule = 'Contacts';
$relationship = 'stic_personal_environment_contacts_1';

$columnsList[] = array('name' => 'id');

$fieldsToRetrieve = array_column($columnsList, 'name');
$relationshipTypes = array();
if (defined('RELATIONSHIP_TUTOR_TYPES')) {
    $relationshipTypes = RELATIONSHIP_TUTOR_TYPES;
}
$query = "((stic_personal_environment.start_date <= DATE(NOW()) AND (stic_personal_environment.end_date >= DATE(NOW()) OR stic_personal_environment.end_date IS NULL)) AND stic_personal_environment.relationship_type in (";

foreach($relationshipTypes as $key => $type) {
    if ($key) {
        $query .= ',';
    }
    $query.= "'".$type ."'";
}
$query .= "))";
$params = array(
    'module_name' => $parentModule,
    "module_id" => isset($_SESSION['scp_tutor_user_id']) ? $_SESSION['scp_tutor_user_id'] : $_SESSION['scp_user_id'], //Do not touch
    "link_field_name" => $relationship,
    "related_module_query" => $query,
    "related_fields" => $fieldsToRetrieve, //Do not touch
    // 'link_name_to_fields_array' => array(
    "related_module_link_name_to_fields_array" => array(),
    "deleted" => 0, //show or not deleted elements (usually 0)
    "order_by" => "",
    "offset" => "",
    "limit" => 0,
);

$getRelatedElements = $objSCP->getRelatedElementsForLoggedUser($params);

$availableContacts = array();
if (is_array($getRelatedElements)) {
    foreach($getRelatedElements as $element) {
        $params = array(
            'module_name' => 'stic_Personal_Environment',
            "module_id" => $element->name_value_list->id->value, //Do not touch
            "link_field_name" => 'stic_personal_environment_contacts',
            "related_fields" => array('id', 'name'), //Do not touch
            // 'link_name_to_fields_array' => array(
            "related_module_link_name_to_fields_array" => array(),
            "deleted" => 0, //show or not deleted elements (usually 0)
            "order_by" => "",
            "offset" => "",
            "limit" => 0,
        );
        
        $getRelatedElements = $objSCP->getRelatedElementsForLoggedUser($params);
        $data = $getRelatedElements[0]->name_value_list;
        $availableContacts[] = array(
            'id' => $data->id->value,
            'name' => $data->name->value,
        );
    }
}


$listSettings['fileName'] = basename(__FILE__, ".php"); //The list name, from the filename. Don't touch.


$current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
$current_url = $current_url[0];

if (isset($_SESSION['scp_tutor_user_id']) && $_SESSION['scp_tutor_user_id']) {
    $currentUser = $_SESSION['scp_tutor_user_id'];
    $currentUserName = $_SESSION['scp_tutor_user_contact_name'];
} else {
    $currentUser = $_SESSION['scp_user_id'];
    $currentUserName = $_SESSION['scp_user_contact_name'];
}
$defaultPage = defaultMenuElement();
 
$html .= "<div class='stic-entry-header'>
<h4>".__('Profile selection', 'sticpa')."</h4>
<form id='profile_selection_form' action='" . site_url() . "/wp-admin/admin-post.php' method='post'>
<input type='hidden' name='action' value='single_stic_profile_selection'>
<input type='hidden' name='scp_current_url' value='{$current_url}'>
<input type='hidden' name='scp_user_id' value='{$_SESSION['scp_user_id']}'>
<input type='hidden' name='scp_user_contact_name' value='{$_SESSION['scp_user_contact_name']}'>
<input type='hidden' name='default_page' value='{$defaultPage}'>";

$html .= '<br><input class="stic-button" type="button" id="'.$currentUser.'" name="profile" value="'.$currentUserName.'" onclick="handleProfileSelection(this)"/><br>';
$html .= "<input type='hidden' id='profile_selected_id' name='profile_selected_id'>";
$html .= "<input type='hidden' id='profile_selected_name' name='profile_selected_name'>";

foreach($availableContacts as $key => $contact) {
    $name = $contact['name'];
    $id = $contact['id'];

    $html .= '<br><input class="stic-button" type="button" id="'.$id.'" name="profile" value="'.$name.'" onclick="handleProfileSelection(this)" /><br>';
}
if (!isset($contact['id'])) {

    $html .= '<br><h6>'.__("This user has no people assigned.", "sticpa").'</h6><br>';
}
$html .="
</form>";

$html.= "
<script>
function handleProfileSelection(elem) {
    var id = $(elem).attr('id');
    var name = $(elem).val();
    $('#profile_selected_id').val(id);
    $('#profile_selected_name').val(name);
    $('#profile_selection_form').submit();
}
</script>";
