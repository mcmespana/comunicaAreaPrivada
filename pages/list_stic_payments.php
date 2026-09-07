<?php

#########################################################
# List settings                                         #
#########################################################
switch (getDestinationModule()) {
    case 'Accounts':
        $relationship = 'stic_payments_accounts';
        $parentModule = 'Accounts';
        break;
    case 'Contacts':
        $relationship = 'stic_payments_contacts';
        $parentModule = 'Contacts';
        // Certificado de donaciones: el templateID es específico de cada CRM. En Comunica
        // no existe ese template (daba "invalid template"), así que el botón solo aparece si
        // se configura la opción `sticpa_donations_template_id` con un templateID válido.
        $templateId = get_option('sticpa_donations_template_id');
        if ($templateId) {
            $hostUrl = get_option('sticpa_scp_host_url');
            $listSettings['additionalButtons'][] = array('label' => __('Donations certificate', 'sticpa'), 'link' => $hostUrl.'/index.php?entryPoint=sticGeneratePdf&task=pdf&module=Contacts&uid='.(isset($_SESSION['scp_tutor_user_id']) ? $_SESSION['scp_tutor_user_id'] : $_SESSION['scp_user_id']).'&templateID='.$templateId);
        }
        break;
}
// NOTA: este listado ya NO usa makeList() ni DataTables. Un recibo no se lee
// como "ETIQUETA: valor" — y el importe, que es LA columna, quedaba en medio de
// la fila sin alinear. Se pinta con sticpa_payments_list_html()
// (inc/stic-payments.php). La acción principal era "Editar" un pago: fuera.
$listTitle = __('Mis pagos', 'sticpa');


// Los campos que se piden. La novedad es `payment_date`: el listado NO la pedía,
// así que era una lista de recibos que no decía cuándo te habían cobrado.
// `bank_account` sale del listado (en la tarjeta no cabe un IBAN, y entero no
// se enseña nunca); sigue en la ficha, enmascarado.
$fieldsToRetrieve = sticpa_payment_list_fields();


#########################################################
# Params for the API query to retrieve related beans
#########################################################
//set the params for the API query
$availablePayments = array();
if ((isset($_SESSION['scp_tutor_is_user']) && $_SESSION['scp_tutor_is_user']) || isset($_SESSION['scp_user_adult']) && $_SESSION['scp_user_adult']) {
    $params = array(
        'module_name' => $parentModule,
        "module_id" => $_SESSION['scp_user_id'], //Do not touch
        "link_field_name" => $relationship,
        // "related_module_query" => "(end_date is null OR end_date >curdate())", //sql where conditions
        "related_fields" => $fieldsToRetrieve, //Do not touch
        "related_module_link_name_to_fields_array" => array(),
        "deleted" => 0, //show or not deleted elements (usually 0)
        "order_by" => "",
        "offset" => "",
        "limit" => 0,
    );

    // RENDIMIENTO: aquí había un bucle que pedía al CRM, POR CADA PAGO, el
    // compromiso del que colgaba, solo para rellenar la columna "Contacto
    // destinatario". Con 50 pagos, 50 viajes. Esa columna ya no existe: en la
    // tarjeta manda el importe, la fecha y el estado, y de quién es el pago ya
    // lo dice la barra de identidad de arriba, que es de quien estás viendo.
    $availablePayments = $objSCP->getRelatedElementsForLoggedUser($params);

} else {
    $params = array(
        'module_name' => $parentModule,
        "module_id" => $_SESSION['scp_user_id'], //Do not touch
        "link_field_name" => 'stic_payment_commitments_contacts_1',
        "related_fields" => array('id'), //Do not touch
        "related_module_link_name_to_fields_array" => array(),
        "deleted" => 0, //show or not deleted elements (usually 0)
        "order_by" => "",
        "offset" => "",
        "limit" => 0,
    );

    $getRelatedElements = $objSCP->getRelatedElementsForLoggedUser($params);
    // is_array: el cliente del CRM devuelve null si la llamada falla o expira.
    foreach((is_array($getRelatedElements) ? $getRelatedElements : array()) as $key => $PC) {
        $params = array(
            'module_name' => 'stic_Payment_Commitments',
            "module_id" => $PC->id, //Do not touch
            "link_field_name" => 'stic_payments_stic_payment_commitments',
            // "related_module_query" => "(end_date is null OR end_date >curdate())", //sql where conditions
            "related_fields" => $fieldsToRetrieve, //Do not touch
            "related_module_link_name_to_fields_array" => array(),
            "deleted" => 0, //show or not deleted elements (usually 0)
            "order_by" => "",
            "offset" => "",
            "limit" => 0,
        );
        // 1+N que no se puede evitar con esta API: los pagos de un participante
        // menor cuelgan de SUS compromisos, y no hay forma de pedirlos todos de
        // una vez. Queda anotado en el plan 011; aquí no se empeora.
        $getRelatedPayments = $objSCP->getRelatedElementsForLoggedUser($params);

        if (is_array($getRelatedPayments)) {
            foreach($getRelatedPayments as $payment) {
                $availablePayments[] = $payment;
            }
        }
        
    }
}

// Etiquetas traducidas de los desplegables (definición cacheada 6h).
$definition = sticpa_cached_field_definition($objSCP, 'stic_Payments', array('status', 'payment_method', 'payment_type'));

$html .= "<div class='stic-entry-header'><h3>" . esc_html($listTitle) . "</h3></div>";
$html .= sticpa_payments_list_html($availablePayments, $definition);

// El certificado de donaciones, si el CRM tiene plantilla configurada, va al
// final y como acción secundaria: no es a lo que se entra.
if (!empty($listSettings['additionalButtons'])) {
    $html .= "<div class='stic-rec-cta-row'>";
    foreach ($listSettings['additionalButtons'] as $button) {
        $html .= sticpa_record_action_html(array('label' => $button['label'], 'url' => $button['link'], 'icon' => 'download'));
    }
    $html .= "</div>";
}
