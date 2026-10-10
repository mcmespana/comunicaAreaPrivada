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
$listTitle = __('Pagos', 'sticpa');


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
    // LOS COMPROMISOS de quien paga (plan 041): para saber qué pago es un
    // intento de tarjeta y qué cuelga de algo ya cerrado, y para reclamar.
    $commitmentLink = $parentModule === 'Accounts' ? 'stic_payment_commitments_accounts' : 'stic_payment_commitments_contacts';
    // EN UNA TANDA (plan 042, VEL-4): tus compromisos y tus pagos solo
    // necesitan tu id, y Pagos no tiene caché (es dinero: se quiere ver al
    // momento), así que se pagaban en fila en CADA visita. 3 esperas → 2 (la
    // segunda, los pagos de cada compromiso vivo, sí depende de la primera).
    if (function_exists('sticpa_pl_prime')) {
        sticpa_pl_prime($objSCP, function () use ($objSCP, $parentModule, $commitmentLink, $params) {
            sticpa_payments_commitment_rows($objSCP, $parentModule, $commitmentLink);
            $objSCP->getRelatedElementsForLoggedUser($params);
        });
    }
    $commitmentRows = sticpa_payments_commitment_rows($objSCP, $parentModule, $commitmentLink);
    if (sticpa_payments_settle($objSCP, $commitmentRows)) {
        // Ha ESCRITO (al volver del TPV se reclama el pago con tarjeta): los
        // pagos traídos en la tanda son de antes. Fuera, y se relee todo.
        if (class_exists('SugarRestApiCall')) {
            SugarRestApiCall::forgetMemo();
        }
        $commitmentRows = sticpa_payments_commitment_rows($objSCP, $parentModule, $commitmentLink);
    }
    $availablePayments = $objSCP->getRelatedElementsForLoggedUser($params);
    // De qué compromiso es cada pago, los que no tienen persona y los que no
    // tienen nombre: ver la función.
    $completo = sticpa_payments_complete($objSCP, $commitmentRows,
        is_array($availablePayments) ? $availablePayments : array(), $fieldsToRetrieve,
        (string) ($_SESSION['scp_user_id'] ?? ''), $relationship);
    $availablePayments = $completo['payments'];
    $commitmentMap = $completo['map'];

} else {
    $params = array(
        'module_name' => $parentModule,
        "module_id" => $_SESSION['scp_user_id'], //Do not touch
        "link_field_name" => 'stic_payment_commitments_contacts_1',
        "related_fields" => sticpa_payments_commitment_fields(),
        "related_module_link_name_to_fields_array" => array(),
        "deleted" => 0, //show or not deleted elements (usually 0)
        "order_by" => "",
        "offset" => "",
        "limit" => 0,
    );

    $getRelatedElements = $objSCP->getRelatedElementsForLoggedUser($params);
    if (sticpa_payments_settle($objSCP, (array) $getRelatedElements)) {
        $getRelatedElements = $objSCP->getRelatedElementsForLoggedUser($params);
    }
    $commitmentRows = is_array($getRelatedElements) ? $getRelatedElements : array();

    // Los pagos de un participante menor cuelgan de SUS compromisos, y no hay
    // forma de pedirlos todos de una vez: una consulta por compromiso. Lo que
    // sí se puede (plan 011) es no esperarlas en fila: salen en una tanda
    // paralela (sticpa_pl_prime) y se recorren en el orden de siempre.
    $paymentsParams = function ($commitmentId) use ($fieldsToRetrieve) {
        return array(
            'module_name' => 'stic_Payment_Commitments',
            'module_id' => $commitmentId,
            'link_field_name' => 'stic_payments_stic_payment_commitments',
            'related_fields' => $fieldsToRetrieve,
            'related_module_link_name_to_fields_array' => array(),
            'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
        );
    };
    // is_array: el cliente del CRM devuelve null si la llamada falla o expira.
    $commitmentIds = array();
    foreach ((is_array($getRelatedElements) ? $getRelatedElements : array()) as $PC) {
        if (!empty($PC->id)) {
            $commitmentIds[] = $PC->id;
        }
    }
    if (function_exists('sticpa_pl_prime')) {
        sticpa_pl_prime($objSCP, function () use ($objSCP, $commitmentIds, $paymentsParams) {
            foreach ($commitmentIds as $commitmentId) {
                $objSCP->getRelatedElementsForLoggedUser($paymentsParams($commitmentId));
            }
        });
    }
    foreach ($commitmentIds as $commitmentId) {
        $getRelatedPayments = $objSCP->getRelatedElementsForLoggedUser($paymentsParams($commitmentId));
        if (is_array($getRelatedPayments)) {
            foreach ($getRelatedPayments as $payment) {
                $availablePayments[] = $payment;
            }
        }
    }
}

// Etiquetas traducidas de los desplegables (definición cacheada 6h).
$definition = sticpa_cached_field_definition($objSCP, 'stic_Payments', array('status', 'payment_method', 'payment_type'));

$html .= "<div class='stic-entry-header'><h3>" . esc_html($listTitle) . "</h3></div>";
if (($_REQUEST['msg'] ?? '') === 'pagado') {
    $html .= sticpa_record_note_html(array('tone' => 'ok', 'icon' => 'check', 'text' => __('Pago hecho. Si aún lo ves pendiente, el banco está terminando de confirmarlo.', 'sticpa')));
}
$html .= sticpa_payments_list_html($availablePayments, $definition, $commitmentMap ?? sticpa_payments_commitment_map($commitmentRows ?? array()));

// El certificado de donaciones, si el CRM tiene plantilla configurada, va al
// final y como acción secundaria: no es a lo que se entra.
if (!empty($listSettings['additionalButtons'])) {
    $html .= "<div class='stic-rec-cta-row'>";
    foreach ($listSettings['additionalButtons'] as $button) {
        $html .= sticpa_record_action_html(array('label' => $button['label'], 'url' => $button['link'], 'icon' => 'download'));
    }
    $html .= "</div>";
}
