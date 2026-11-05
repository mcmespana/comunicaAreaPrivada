<?php
/**
 * DATOS DEL FAMILIAR (la persona que accede y gestiona a los participantes).
 * ----------------------------------------------------------------------------
 * Aquí el familiar edita SUS PROPIOS datos (no los del participante activo):
 * básicos, contacto y dirección. Siempre trabaja sobre
 * $_SESSION['scp_tutor_user_id'] (o scp_user_id si aún no hay sesión de tutor).
 *
 * MEDIO DE PAGO: ya NO se edita aquí (05/11/2026). Esta pantalla tenía unos campos
 * provisionales (ajmcm_pago_*_c) que el CRM no tiene, así que el IBAN que se
 * metía se perdía en silencio. Ahora es una pantalla propia, «Forma de pago»
 * (pages/single_stic_forma_pago.php, lógica en inc/stic-forma-pago.php), con
 * los campos buenos de la ficha, y desde aquí solo se enlaza.
 *
 * Guardado: prefix_admin_single_stic_tutor_profile (inc/stic-action.php),
 * que hace set_entry con id = scp_tutor_user_id (nunca el participante).
 */

$formSettings['moduleName'] = 'Contacts';
$formSettings['title'] = __('Mis datos (familiar)', 'sticpa');
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('Tus datos se han guardado correctamente.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'error', 'type' => 'error', 'msg' => __('Error al guardar los datos.', 'sticpa'));
$formSettings['submitButton']['save'] = __('Guardar', 'sticpa');
$formSettings['submitButtonActions']['save'] = array('onclick' => 'return verifyFormIsValid(this)');
$formSettings['fileName'] = basename(__FILE__, ".php");

$tutorId = $_SESSION['scp_tutor_user_id'] ?? $_SESSION['scp_user_id'];
$data = $objSCP->getRecordDetail($tutorId, $formSettings['moduleName'])->entry_list[0]->name_value_list;

$fieldList[] = array('name' => 'id', 'type' => 'hidden', 'value' => $tutorId);

// ===== Datos básicos (el familiar SÍ puede editarlos) =====
$fieldList[] = array('name' => 'datos_basicos', 'type' => 'header', 'label' => __('Datos básicos', 'sticpa'));
$fieldList[] = array(
    'name' => 'datos_basicos_nota', 'type' => 'note', 'classes' => 'stic-note-soft',
    'html' => __('Estos son <strong>tus datos como familiar</strong>. Los datos de cada participante se editan entrando en su perfil desde el selector de la barra superior.', 'sticpa'),
);
$fieldList[] = array('name' => 'first_name', 'required' => false, 'attributes' => array('autocomplete' => 'given-name'));
$fieldList[] = array('name' => 'last_name', 'required' => false, 'attributes' => array('autocomplete' => 'family-name'));
$fieldList[] = array('name' => 'stic_identification_type_c', 'required' => false);
$fieldList[] = array(
    'name' => 'stic_identification_number_c', 'required' => false,
    'actions' => array('onchange' => 'validateId'),
);
$fieldList[] = array('name' => 'birthdate', 'required' => false, 'attributes' => array('autocomplete' => 'bday'));

// ===== Contacto =====
$fieldList[] = array('name' => 'contacto', 'type' => 'header', 'label' => __('Datos de contacto', 'sticpa'));
$fieldList[] = array(
    'name' => 'email1',
    'label' => __('Correo electrónico', 'sticpa'),
    'help' => __('A este correo llegarán las comunicaciones y el enlace de acceso al área privada.', 'sticpa'),
    'attributes' => array(
        'pattern' => "^[a-zA-Z0-9.!#$%&’*+/=?^_`{|}~-]+@[a-zA-Z0-9-]+(?:\.[a-zA-Z0-9-]+)+$",
        'autocomplete' => 'email',
        'inputmode' => 'email',
    ),
);
$fieldList[] = array('name' => 'phone_mobile', 'label' => __('Móvil', 'sticpa'), 'attributes' => array('autocomplete' => 'tel', 'inputmode' => 'tel'));

// ===== Dirección =====
$fieldList[] = array('name' => 'direccion', 'type' => 'header', 'label' => __('Dirección', 'sticpa'));
$fieldList[] = array('name' => 'primary_address_street', 'label' => __('Calle y número', 'sticpa'), 'attributes' => array('autocomplete' => 'street-address'));
$fieldList[] = array('name' => 'primary_address_city', 'label' => __('Población', 'sticpa'), 'attributes' => array('autocomplete' => 'address-level2'));
$fieldList[] = array('name' => 'primary_address_state', 'label' => __('Provincia', 'sticpa'));
$fieldList[] = array(
    'name' => 'primary_address_postalcode',
    'label' => __('Código postal', 'sticpa'),
    'attributes' => array('inputmode' => 'numeric', 'maxlength' => '5', 'autocomplete' => 'postal-code'),
);

// ===== Forma de pago: es otra pantalla =====
$fieldList[] = array('name' => 'pago', 'type' => 'header', 'label' => __('Forma de pago', 'sticpa'));
$fieldList[] = array(
    'name' => 'pago_nota', 'type' => 'note',
    'html' => __('Cómo pagas y tu IBAN se cambian en su propia pantalla.', 'sticpa')
        . " <a class='stic-fp-link' href='" . esc_url('?internalpage=single_stic_forma_pago') . "'>" . esc_html__('Cambiar forma de pago', 'sticpa') . '</a>',
);

$html .= makeForm($fieldList, $formSettings, $data);
