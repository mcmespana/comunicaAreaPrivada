<?php
/**
 * DATOS DEL FAMILIAR (la persona que accede y gestiona a los participantes).
 * ----------------------------------------------------------------------------
 * Aquí el familiar edita SUS PROPIOS datos (no los del participante activo):
 * básicos, contacto, dirección y medio de pago. Siempre trabaja sobre
 * $_SESSION['scp_tutor_user_id'] (o scp_user_id si aún no hay sesión de tutor).
 *
 * MEDIO DE PAGO — FRONT ADELANTADO (importante para futuros agentes):
 * En SinergiaCRM/Comunica aún NO está definido dónde viven estos datos. Los
 * campos usan nombres provisionales (ajmcm_pago_*_c). El guardado es inocuo:
 * la API set_entry ignora los campos que no existen en el CRM. Cuando se creen
 * los campos reales en Studio (o se decida usar stic_Payment_Commitments),
 * basta con renombrar aquí los 'name' — el resto (validación IBAN incluida)
 * ya funciona. Ver docs/design-system.md §Perfiles de familia.
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

// ===== Medio de pago (front adelantado — ver nota de cabecera) =====
$fieldList[] = array('name' => 'pago', 'type' => 'header', 'label' => __('Medio de pago', 'sticpa'));
$fieldList[] = array(
    'name' => 'pago_nota', 'type' => 'note',
    'html' => __('Con la <strong>domiciliación bancaria</strong> las cuotas y actividades se cargan en tu cuenta sin que tengas que hacer nada. ⚙️ <em>Esta sección se está conectando con el sistema de gestión: es posible que los cambios aún no queden guardados.</em>', 'sticpa'),
);
$fieldList[] = array(
    'name' => 'ajmcm_pago_metodo_c', 'type' => 'select', 'required' => false,
    'label' => __('Método de pago preferido', 'sticpa'),
    'selectValues' => array(
        '' => ' ',
        'direct_debit' => __('Domiciliación bancaria (recomendado)', 'sticpa'),
        'transfer' => __('Transferencia', 'sticpa'),
        'cash' => __('Efectivo / otro', 'sticpa'),
    ),
    'actions' => array('onchange' => 'sticTutorPaymentMethod'),
);
$fieldList[] = array(
    'name' => 'ajmcm_pago_iban_c', 'required' => false,
    'label' => __('IBAN', 'sticpa'),
    'help' => __('Cuenta en la que se domiciliarán los pagos. Solo se usará para los cargos que autorices.', 'sticpa'),
    'placeholder' => 'ES00 0000 0000 0000 0000 0000',
    'hint' => __('Solo necesario si eliges domiciliación bancaria.', 'sticpa'),
    'attributes' => array('autocomplete' => 'off', 'data-visible-when' => 'ajmcm_pago_metodo_c:direct_debit'),
    'actions' => array('onchange' => 'sticTutorVerifyIban'),
);
$fieldList[] = array(
    'name' => 'ajmcm_pago_titular_c', 'required' => false,
    'label' => __('Titular de la cuenta', 'sticpa'),
    'attributes' => array('autocomplete' => 'name', 'data-visible-when' => 'ajmcm_pago_metodo_c:direct_debit'),
);

$html .= makeForm($fieldList, $formSettings, $data);

// Validación de IBAN (usa js/iban.js, ya cargado por el plugin) solo cuando el
// método es domiciliación. La visibilidad la gestiona data-visible-when.
$html .= "
<script>
function sticTutorPaymentMethod() { /* la visibilidad la resuelve data-visible-when */ }
function sticTutorVerifyIban(obj) {
    var metodo = document.getElementById('ajmcm_pago_metodo_c');
    if (!metodo || metodo.value !== 'direct_debit' || !obj.value) { obj.removeAttribute('invalid'); return; }
    obj.value = obj.value.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
    if (typeof IBAN !== 'undefined' && !IBAN.isValid(obj.value)) {
        alert((typeof stic_script_vars !== 'undefined' && stic_script_vars.wrongIban) ? stic_script_vars.wrongIban : 'El IBAN no parece válido.');
        obj.setAttribute('invalid', '');
    } else {
        obj.removeAttribute('invalid');
    }
}
</script>";
