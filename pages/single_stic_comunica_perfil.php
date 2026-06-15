<?php
/**
 * Comunica — Mis datos (común a todos los roles).
 * Datos personales + dirección + RGPD. Campos de identidad en solo lectura.
 * Guardado: prefix_comunica_save_contact (inc/stic-action.php).
 */

$formSettings['moduleName'] = 'Contacts';
$formSettings['title'] = __('Mis datos', 'sticpa');
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('Los datos se han guardado correctamente.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'error', 'type' => 'error', 'msg' => __('Error al guardar los datos.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'error_type', 'type' => 'error', 'msg' => __('El formato del archivo no es válido.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'error_size', 'type' => 'error', 'msg' => __('El archivo es demasiado grande.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'error_upload', 'type' => 'error', 'msg' => __('Error al subir la imagen.', 'sticpa'));
$formSettings['submitButton']['save'] = __('Guardar', 'sticpa');
$formSettings['submitButtonActions']['save'] = array('onclick' => 'return verifyFormIsValid(this)');
$formSettings['attributes'] = 'enctype="multipart/form-data"';

$id = $_SESSION['scp_user_id'];
$data = $objSCP->getRecordDetail($id, $formSettings['moduleName'])->entry_list[0]->name_value_list;

$fieldList[] = array('name' => 'id', 'type' => 'hidden');

// --- Identidad (solo lectura) ---
$fieldList[] = array('name' => 'datos_personales', 'type' => 'header', 'label' => __('Datos personales', 'sticpa'));
$fieldList[] = array('name' => 'first_name', 'required' => false, 'attributes' => array('disabled' => 'disabled'));
$fieldList[] = array('name' => 'last_name', 'required' => false, 'attributes' => array('disabled' => 'disabled'));
$fieldList[] = array('name' => 'stic_identification_type_c', 'required' => false, 'attributes' => array('disabled' => 'disabled'));
$fieldList[] = array('name' => 'stic_identification_number_c', 'required' => false, 'attributes' => array('disabled' => 'disabled'));
$fieldList[] = array('name' => 'birthdate', 'required' => false, 'attributes' => array('disabled' => 'disabled'));

// --- Contacto (editable) ---
$fieldList[] = array('name' => 'stic_gender_c', 'required' => false);
$fieldList[] = array(
    'name' => 'email1',
    'attributes' => array('pattern' => "^[a-zA-Z0-9.!#$%&’*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)+$"),
);
$fieldList[] = array('name' => 'phone_mobile');
$fieldList[] = array('name' => 'phone_other', 'label' => __('Contacto de emergencia', 'sticpa'));

// --- Foto ---
if ($filename = $data->photo->value) {
    $image = $objSCP->get_image(array('id' => $id, 'field' => 'photo'));
    $content = $image->image_data->data;
    $mime_type = $image->image_data->mime_type;
    $fieldList[] = array(
        'name' => 'photo', 'type' => 'html',
        'html' => '
            <li>
                <img class="stic-profile-picture" src="data:' . $mime_type . '/png;base64, ' . $content . '"/>
                <label style="padding-botton: 0px">' . __('Cambiar foto de perfil', 'sticpa') . ':</label>
                <div style="font-size: 10px">' . __('(Formato: jpg, jpeg, gif, png — Tamaño máximo: 6MB)', 'sticpa') . '</div>
                <span><input type="file" name="photo" id="photo" value="' . $filename . '"></span>
            </li>',
    );
} else {
    $fieldList[] = array(
        'name' => 'photo', 'type' => 'html',
        'html' => '
            <li>
                <img class="stic-profile-picture" src="' . plugins_url('../images/profile_picture.jpg', __FILE__) . '"/>
                <label style="padding-botton: 0px">' . __('Elige un archivo', 'sticpa') . ':</label>
                <div style="font-size: 10px">' . __('(Formato: jpg, jpeg, gif, png — Tamaño máximo: 6MB)', 'sticpa') . '</div>
                <span><input type="file" name="photo" id="photo" value=></span>
            </li>',
    );
}

// --- Dirección ---
$fieldList[] = array('name' => 'direccion', 'type' => 'header', 'label' => __('Dirección', 'sticpa'));
$fieldList[] = array('name' => 'primary_address_street');
$fieldList[] = array('name' => 'primary_address_city');
$fieldList[] = array('name' => 'primary_address_postalcode');
$fieldList[] = array('name' => 'primary_address_state');
$fieldList[] = array('name' => 'stic_primary_address_region_c', 'required' => false);

// --- RGPD ---
$siNo = array('' => ' ', '1' => __('Sí', 'sticpa'), '0' => __('No', 'sticpa'));
$fieldList[] = array('name' => 'rgpd', 'type' => 'header', 'label' => __('Protección de datos (RGPD)', 'sticpa'));
$fieldList[] = array('name' => 'ajmcm_acepta_lopd_c', 'type' => 'select', 'required' => false, 'selectValues' => $siNo);
$fieldList[] = array('name' => 'ajmcm_datossalud_c', 'type' => 'select', 'required' => false, 'selectValues' => $siNo);
$fieldList[] = array('name' => 'ajmcm_cesionimagenes_interne_c', 'type' => 'select', 'required' => false, 'selectValues' => $siNo);

$formSettings['fileName'] = basename(__FILE__, ".php");
$html .= makeForm($fieldList, $formSettings, $data);
