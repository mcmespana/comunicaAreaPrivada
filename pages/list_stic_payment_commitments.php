<?php
/**
 * MIS COMPROMISOS DE PAGO — listado.
 * ----------------------------------------------------------------------------
 * Ya NO usa makeList() ni DataTables. Un compromiso son dos datos que solo
 * significan algo juntos —cuánto y cada cuánto— y se pintaban en dos celdas
 * separadas por otras tres. Ahora el importe va a la derecha, en grande, con la
 * periodicidad debajo: "20,00 € / Mensual" se lee de un vistazo.
 *
 * Se quita también el botón "Nuevo compromiso de pago": llevaba al formulario
 * genérico del módulo, que no es por donde se domicilia nada. Quien quiera
 * aportar entra en un compromiso y usa "Hacer una aportación", o va al
 * formulario de pago.
 *
 * Se pinta con sticpa_commitments_list_html() (inc/stic-payments.php).
 */

if (!defined('ABSPATH')) {
    exit;
}

switch (getDestinationModule()) {
    case 'Accounts':
        $relationship = 'stic_payment_commitments_accounts';
        $parentModule = 'Accounts';
        break;
    case 'Contacts':
    default:
        // Persona adulta o familiar viéndose a sí mismo: sus propios
        // compromisos. Participante menor: aquellos en los que él es el
        // destinatario, aunque los pague otra persona.
        $relationship = (!empty($_SESSION['scp_user_adult']) || !empty($_SESSION['scp_tutor_is_user']))
            ? 'stic_payment_commitments_contacts'
            : 'stic_payment_commitments_contacts_1';
        $parentModule = 'Contacts';
        break;
}

$getRelatedElements = $objSCP->getRelatedElementsForLoggedUser(array(
    'module_name' => $parentModule,
    'module_id' => $_SESSION['scp_user_id'],
    'link_field_name' => $relationship,
    'related_fields' => sticpa_commitment_list_fields(),
    'related_module_link_name_to_fields_array' => array(),
    'deleted' => 0,
    'order_by' => '',
    'offset' => '',
    'limit' => 0,
));

// Etiquetas traducidas de los desplegables (definición cacheada 6h).
$definition = sticpa_cached_field_definition($objSCP, 'stic_Payment_Commitments', array(
    'periodicity', 'payment_method', 'payment_type',
));

$html .= "<div class='stic-entry-header'><h3>" . esc_html__('Mis compromisos de pago', 'sticpa') . "</h3></div>";
$html .= sticpa_commitments_list_html($getRelatedElements, $definition);
