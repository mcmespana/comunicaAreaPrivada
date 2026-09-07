<?php
/**
 * PAGO — ficha (detail) y alta/edición.
 * ----------------------------------------------------------------------------
 * `action=detail` ya NO es el formulario genérico con todos los campos
 * deshabilitados. En dinero eso era especialmente malo: un recibo devuelto se
 * leía igual que uno cobrado, "Estado: Devuelto" en texto negro y en la cuarta
 * caja gris. Ahora el importe manda, el estado es un chip con su color y, si el
 * recibo no se pudo cobrar, lo primero que se lee es por qué y qué hacer
 * (sticpa_payment_detail_html, en inc/stic-payments.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

// --- FICHA -----------------------------------------------------------------
if (($_REQUEST['action'] ?? '') === 'detail') {
    $paymentId = isset($_REQUEST['id']) ? sanitize_text_field($_REQUEST['id']) : '';

    if ($paymentId === '') {
        $html .= sticpa_record_empty_html(
            'card',
            __('No hemos encontrado el pago', 'sticpa'),
            __('Puede que el enlace esté incompleto. Vuelve a tus pagos y entra de nuevo.', 'sticpa'),
            array('label' => __('Ver mis pagos', 'sticpa'), 'url' => '?internalpage=list_stic_payments', 'primary' => true)
        );
        return;
    }

    $detail = $objSCP->getRecordDetail($paymentId, 'stic_Payments', sticpa_payment_detail_fields());
    $nvl = $detail->entry_list[0]->name_value_list ?? null;
    $payment = $nvl ? sticpa_payment_view_model($nvl) : null;

    if (!$payment) {
        $html .= sticpa_record_empty_html(
            'card',
            __('Este pago ya no está disponible', 'sticpa'),
            __('Consulta el resto de tus pagos.', 'sticpa'),
            array('label' => __('Ver mis pagos', 'sticpa'), 'url' => '?internalpage=list_stic_payments', 'primary' => true)
        );
        return;
    }

    $definition = sticpa_cached_field_definition($objSCP, 'stic_Payments', array(
        'status', 'payment_method', 'payment_type', 'sepa_rejected_reason', 'c19_rejected_reason',
    ));

    $html .= sticpa_payment_detail_html($payment, $definition);
    return;
}

#########################################################
# Form settings                                         #
#########################################################
switch (getDestinationModule()) {
    case 'Accounts':
        $relationshipField = 'stic_payments_accountsaccounts_ida';
        break;
    case 'Contacts':
        $relationshipField = 'stic_payments_contactscontacts_ida';
        break;
}
$formSettings['action'] = $_REQUEST['action'];
$formSettings['title'] = __('Payment', 'sticpa'); // form title
$formSettings['moduleName'] = 'stic_Payments'; // module name, case sensitive
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => __('The record has been successfully saved.', 'sticpa')); //messages that will be shown on the screen after processing the data

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
            'onclick' => "location.href='?internalpage=list_stic_payments';",
        );
        $formSettings['submitButton']['save'] = __('Save', 'sticpa'); // submit button title. If not defined, it will be a read-only view
        $formSettings['submitButtonActions']['save'] = array(
            'onclick' => 'return verifyFormIsValid(this)',
        );
        break;
    case 'detail':
        $formSettings['submitButton']['back'] = __('Back', 'sticpa');
        $formSettings['submitButtonType']['back'] = 'button';
        $formSettings['submitButtonActions']['back'] = array(
            'onclick' => "location.href='?internalpage=list_stic_payments';",
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
$data = $objSCP->getRecordDetail($_REQUEST['id'], $formSettings['moduleName'])->entry_list[0]->name_value_list;

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
if ($_REQUEST['action'] == 'detail') {
    $fieldList[] = array('name' => 'name');
    if (isset($_SESSION['scp_tutor_is_user']) && $_SESSION['scp_tutor_is_user']) {
        $params = array(
            'module_name' => 'stic_Payments',
            "module_id" => $data->id->value, //Do not touch
            "link_field_name" => 'stic_payments_stic_payment_commitments',
            // "related_module_query" => "(end_date is null OR end_date >curdate())", //sql where conditions
            "related_fields" => array('stic_payment_commitments_contacts_1_name'), //Do not touch
            "related_module_link_name_to_fields_array" => array(),
            "deleted" => 0, //show or not deleted elements (usually 0)
            "order_by" => "",
            "offset" => "",
            "limit" => 0,
        );
        $getRelatedPC = $objSCP->getRelatedElementsForLoggedUser($params);
        $fieldList[] = array(
            'name' => 'stic_payment_commitments_contacts_name',
            'type' => 'varchar',
            'label' => __('Recipient contact', 'sticpa'),
            'value' => $getRelatedPC[0]->name_value_list->stic_payment_commitments_contacts_1_name->value,
        );
    }
}
$fieldList[] = array('name' => 'status');
$fieldList[] = array('name' => 'payment_type');
$fieldList[] = array('name' => 'amount', 'format' => 'currency');
$fieldList[] = array(
    'name' => 'payment_method',
    'actions' => array(
        'onchange' => 'handlePaymentMethod',
    ),
);
$fieldList[] = array(
    'name' => 'bank_account',
    'actions' => array(
        'onchange' => 'verifyIban',
    ),
);

$formSettings['fileName'] = basename(__FILE__, ".php"); //The page name, from the filename. Don't touch.

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
$html.= '
<script>
document.addEventListener("DOMContentLoaded", function(event) { 
    handlePaymentMethod(this);
});
</script>';

