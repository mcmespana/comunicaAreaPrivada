<?php

#########################################################
# Custom page settings                                         #
#########################################################
$pageSettings['title'] = __('Payment form', 'sticpa'); // List title
#########################################################
$pageSettings['fileName'] = basename(__FILE__, ".php"); // List name, from the filename. Don't touch.

$html .= "<div class='stic-entry-header'>
<h4>".__('Payment form', 'sticpa')."</h4>";

/**
 * Pantalla de "aquí no puedes seguir" con su salida a otro sitio.
 *
 * Antes esto era un alert() bloqueante en medio del HTML seguido de un
 * window.location.href, y encima la página SEGUÍA ejecutándose: pintaba el
 * formulario completo (con sus llamadas al CRM) para luego rebotar. Un diálogo
 * modal nativo a media carga es justo lo que dentro de la WebView se lee como
 * "la app se ha colgado".
 *
 * No se puede usar wp_redirect: el shortcode se pinta cuando el tema ya ha
 * enviado las cabeceras. Así que se muestra el mismo lenguaje de error que
 * pages/single_stic_payment_error.php y el usuario decide.
 */
if (!function_exists('sticpa_payment_form_bounce')) {
function sticpa_payment_form_bounce($title, $sub, $link, $linkLabel)
{
    return "<div class='stic-empty-state stic-empty-state--error' role='alert'>"
        . "<span class='stic-empty-ico stic-empty-ico--error'>"
        . "<svg viewBox='0 0 24 24' width='30' height='30' fill='none' stroke='currentColor' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'><circle cx='12' cy='12' r='10'/><path d='M12 8v4M12 16h.01'/></svg>"
        . "</span>"
        . "<p class='stic-empty-title'>" . esc_html($title) . "</p>"
        . "<p class='stic-empty-sub'>" . esc_html($sub) . "</p>"
        . "<p class='stic-empty-actions'><a class='stic-button' href='" . esc_url($link) . "'>" . esc_html($linkLabel) . "</a></p>"
        . "</div>";
}
}

$eventId = !empty($_REQUEST['eventId']) ? (string) $_REQUEST['eventId'] : '';
$registrationId = !empty($_REQUEST['registrationId']) ? (string) $_REQUEST['registrationId'] : '';
// LO QUE SE DEBE (plan 041): el pago pendiente que se viene a saldar.
$paymentId = !empty($_REQUEST['paymentId']) ? (string) $_REQUEST['paymentId'] : '';

// SIN NADA QUE PAGAR NO HAY FORMULARIO. Antes, sin contexto, esto era una
// «aportación» suelta: un donativo con el importe que se escribiera. Desde el
// área no se hacen aportaciones (decisión del 01/10/2026), y por ahí salieron
// los compromisos de más del Foro. Guard sin llamadas al CRM.
if ($paymentId === '' && ($eventId === '' || $registrationId === '')) {
    $html .= sticpa_payment_form_bounce(
        __('No hay nada que pagar aquí', 'sticpa'),
        __('Lo que tengas pendiente de pagar está en Pagos.', 'sticpa'),
        '?internalpage=list_stic_payments',
        __('Ir a Pagos', 'sticpa')
    );
    return;
}

$objSCP = SugarRestApiCall::getObjSCP();

// Datos del pagador ANTES de montar nada: si le faltan campos obligatorios hay
// que mandarle a su perfil, y así no se paga la definición de campos ni la ficha
// del evento para luego tirarlas.
$userId = $_SESSION['scp_user_adult'] ? $_SESSION['scp_user_id'] : $_SESSION['scp_tutor_user_id'];
$userData = $objSCP->getRecordDetail($userId, 'Contacts', array(
    'id', 'email1', 'first_name', 'last_name', 'stic_identification_number_c',
))->entry_list[0]->name_value_list;
$email = $userData->email1->value ?? '';
$last_name = $userData->last_name->value ?? '';
$first_name = $userData->first_name->value ?? '';
$stic_identification_number_c = $userData->stic_identification_number_c->value ?? '';

if (!$email || !$last_name || !$first_name || !$stic_identification_number_c) {
    $html .= sticpa_payment_form_bounce(
        __('Te faltan datos para poder pagar', 'sticpa'),
        __('Necesitamos tu nombre, apellidos, correo y documento de identidad. Complétalos en tus datos y vuelve al pago.', 'sticpa'),
        '?internalpage=' . ($_SESSION['scp_user_adult'] ? 'single_stic_profile' : 'single_stic_tutor_profile'),
        __('Completar mis datos', 'sticpa')
    );
    return;
}

$noPagable = function () use (&$html) {
    $html .= sticpa_payment_form_bounce(
        __('Este pago no está pendiente', 'sticpa'),
        __('Puede que ya esté pagado o que se cobre por domiciliación. Míralo en Pagos.', 'sticpa'),
        '?internalpage=list_stic_payments',
        __('Ir a Pagos', 'sticpa')
    );
};

$plano = function ($texto) {
    $texto = trim(html_entity_decode((string) $texto, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    return function_exists('mb_substr') ? mb_substr($texto, 0, 140, 'UTF-8') : substr($texto, 0, 140);
};

// Lo que se manda al formulario de SinergiaCRM. Nunca un donativo: lo que se
// paga desde el área es siempre una actividad o una cuota (EV-9).
$paymentType = sticpa_registration_payment_type();
$bankingConcept = '';
$paymentDescription = '';
$suggestedAmount = '';
$assignedUserId = '';
$redirectOk = '';

if ($paymentId !== '') {
    // PAGAR LO QUE SE DEBE: el pago tiene que ser tuyo (el id viaja por la
    // URL) y estar pendiente. El importe, el tipo y el dueño son LOS DEL PAGO:
    // es lo que se debe, no el precio del evento.
    if (!sticpa_user_owns_record($objSCP, 'stic_Payments', $paymentId)) {
        $noPagable();
        return;
    }
    $ctx = sticpa_pay_context($objSCP, $paymentId);
    $marca = sticpa_pay_marker($paymentId);
    if (!$ctx || $marca === '' || (float) $ctx['amount'] <= 0 || !sticpa_pay_is_payable($ctx['status'], $ctx['method'])) {
        $noPagable();
        return;
    }
    $suggestedAmount = number_format((float) $ctx['amount'], 2, '.', '');
    if ($ctx['type'] !== '' && $ctx['type'] !== 'donation') {
        $paymentType = $ctx['type'];
    }
    $assignedUserId = $ctx['assigned'];
    $bankingConcept = $plano($ctx['concept']);
    $paymentDescription = sprintf('Pago con tarjeta de «%s», desde el área privada. Sustituye al pago %s. %s',
        $bankingConcept, $paymentId, $marca);
    // La vuelta, a la inscripción si la hay (es donde se mira «¿lo he
    // pagado?»), o a Pagos. Las dos reclaman el pago al entrar.
    $vuelta = !empty($ctx['registrations'])
        ? 'internalpage=single_stic_registrations&action=detail&id=' . rawurlencode($ctx['registrations'][0])
        : 'internalpage=list_stic_payments';
    $redirectOk = sticpa_area_absolute_url($vuelta . '&msg=pagado&pago=' . rawurlencode($paymentId));
    $html .= "<div class='stic-entry-header'><h5>" . esc_html($bankingConcept) . "</h5></div>";
} else {
    // UNA INSCRIPCIÓN SIN COMPROMISO (las de antes del 01/10/2026): se paga
    // por el precio del evento y el área la ata a la vuelta por su marca
    // (sticpa_registration_card_marker()). Solo si la inscripción es tuya.
    $eventData = $objSCP->getRecordDetail($eventId, 'stic_Events', array('id', 'name', 'price', 'assigned_user_id'))->entry_list[0]->name_value_list ?? null;
    $marca = sticpa_registration_card_marker($registrationId);
    $precio = $eventData ? sticpa_event_price($eventData) : 0.0;
    if (!$eventData || $marca === '' || $precio <= 0
        || !sticpa_user_owns_record($objSCP, 'stic_Registrations', $registrationId)) {
        $noPagable();
        return;
    }
    $eventName = (string) ($eventData->name->value ?? '');
    $bankingConcept = $plano($eventName);
    $suggestedAmount = number_format($precio, 2, '.', '');
    // De quien organiza el evento (plan 041, D-1).
    $assignedUserId = trim((string) ($eventData->assigned_user_id->value ?? ''));
    $paymentDescription = sprintf('Pago con tarjeta de la inscripción a «%s», desde el área privada. %s', $bankingConcept, $marca);
    $redirectOk = sticpa_area_absolute_url('internalpage=single_stic_registrations&action=detail&id=' . rawurlencode($registrationId) . '&msg=pagado');
    $html .= "<div class='stic-entry-header'><h5>" . esc_html($bankingConcept) . "</h5></div>";
}

// EL DUEÑO: el del pago o el del evento (quien organiza, plan 041 D-1). Si no
// se sabe, la delegación de quien paga; y solo como último recurso el
// «Administrador MCM».
if ($assignedUserId === '' && function_exists('sticpa_pl_delegation')) {
    $assignedUserId = (string) sticpa_pl_delegation($objSCP);
}
if ($assignedUserId === '') {
    $assignedUserId = '1';
}
$forInscripcion = true;

// Solo tarjeta: los demás medios se eligen al inscribirse y no pasan por aquí.
$paymentMethodDef = sticpa_cached_field_definition($objSCP, 'stic_Payment_Commitments', array('payment_method'));
$paymentMethodOptions = $paymentMethodDef['payment_method']['options'] ?? array();
$paymentMethodOptionsHtml = '';
foreach (array('card') as $elem) {
    $optionLabel = $paymentMethodOptions[$elem]['value'] ?? $elem;
    $paymentMethodOptionsHtml .= "<option value='".esc_attr($elem)."' label='".esc_attr($optionLabel)."' >".esc_html($optionLabel)."</option>";
}

$hostUrl = get_option('sticpa_scp_host_url');
$tutorIsUser = $_SESSION['scp_tutor_is_user'] ?? ($_SESSION['scp_user_adult'] ?? false);

$html .= '
<form action="'.$hostUrl.'/index.php?entryPoint=stic_Web_Forms_save" name="WebToLeadForm"
    method="POST" id="WebToLeadForm">
    <p><input type="hidden" id="campaign_id" name="campaign_id" value="'.esc_attr(sticpa_card_campaign_id()).'" />
      <input type="hidden" id="redirect_url" name="redirect_url"
        value="'.esc_attr($redirectOk).'" />
      <input type="hidden" id="redirect_ko_url" name="redirect_ko_url"
        value="'.esc_attr(sticpa_area_absolute_url('internalpage=single_stic_payment_error')).'" />
      <input type="hidden" id="validate_identification_number" name="validate_identification_number" value="0" />
      <input type="hidden" id="allow_card_recurring_payments" name="allow_card_recurring_payments" value="0" />
      <input type="hidden" id="allow_paypal_recurring_payments" name="allow_paypal_recurring_payments" value="0" />
      <input type="hidden" id="assigned_user_id" name="assigned_user_id" value="'.esc_attr($assignedUserId).'" />
      <input type="hidden" id="req_id" name="req_id"
        value="Contacts___first_name;Contacts___last_name;Contacts___email1;Contacts___stic_identification_number_c;stic_Payment_Commitments___amount;stic_Payment_Commitments___payment_method;stic_Payment_Commitments___periodicity;" />
      <input type="hidden" id="bool_id" name="bool_id" value="" />
      <input type="hidden" id="webFormClass" name="webFormClass" value="Donation" />
      <input type="hidden" id="stic_Payment_Commitments___payment_type" name="stic_Payment_Commitments___payment_type"
        value="'.esc_attr($paymentType).'" />'.($paymentDescription !== '' ? '
      <input type="hidden" id="stic_Payment_Commitments___description" name="stic_Payment_Commitments___description" value="'.esc_attr($paymentDescription).'" />' : '').'
      <input type="hidden" id="web_module" name="web_module" value="Contacts" />
      <input type="hidden" id="language" name="language" value="es_ES" />
      <input type="hidden" id="defParams" name="defParams"
        value="%7B%22version%22%3A%222%22%2C%22email_template_id%22%3A%22%22%2C%22relation_type%22%3A%22%22%7D" />
      <input type="hidden" id="timeZone" name="timeZone" value="" />
      <input type="hidden" id="stic_Payment_Commitments___periodicity" name="stic_Payment_Commitments___periodicity"
                value="punctual" />
      <input id="stic_Payment_Commitments___stic_payment_commitments_contacts_1contacts_ida" name="stic_Payment_Commitments___stic_payment_commitments_contacts_1contacts_ida"
                type="hidden" span="" sugar="slot" value="'.($_SESSION['scp_user_adult'] ? '' : $_SESSION['scp_user_id']).'"/>'.($bankingConcept !== '' ? '
      <input type="hidden" id="stic_Payment_Commitments___banking_concept" name="stic_Payment_Commitments___banking_concept" value="'.esc_attr($bankingConcept).'" />' : '').'
    </p>
    <table class="tableForm">
      <tbody>
      </tbody>
      <tbody id="Contacts" class="section">
        <tr>
          <td colspan="4">
            <h5>'.__('Payer data', 'sticpa').'</h5>
          </td>
        </tr>
        <tr>
          <td id="td_lbl_Contacts___first_name" class="column_25"><span><label id="lbl_Contacts___first_name"
                for="Contacts___first_name">'.__('Name', 'sticpa').':</label>
            </span></td>
          <td id="td_Contacts___first_name" class="column_25"><span>
              <input id="Contacts___first_name" name="Contacts___first_name" type="text" span="" sugar="slot" readonly class="stic-locked-field" value="'.$first_name.'" />
            </span></td>
        </tr>
        <tr>
          <td id="td_lbl_Contacts___last_name" class="column_25"><span><label id="lbl_Contacts___last_name"
                for="Contacts___last_name">'.__('Last name', 'sticpa').':</label>
            </span></td>
          <td id="td_Contacts___last_name" class="column_25"><span>
              <input id="Contacts___last_name" name="Contacts___last_name" type="text" span="" sugar="slot" readonly class="stic-locked-field" value="'.$last_name.'" />
            </span></td>
        </tr>
        <tr>
          <td id="td_lbl_Contacts___email1" class="column_25"><span><label id="lbl_Contacts___email1"
                for="Contacts___email1">'.__('Email', 'sticpa').':</label>
            </span></td>
          <td id="td_Contacts___email1" class="column_25"><span>
              <input id="Contacts___email1" name="Contacts___email1" type="text" span="" sugar="slot" readonly class="stic-locked-field" value="'.$email.'"/>
            </span></td>
        </tr>
        <tr>
          <td id="td_lbl_Contacts___stic_identification_number_c" class="column_25"><span>
              <label id="lbl_Contacts___stic_identification_number_c" for="Contacts___stic_identification_number_c">'.__('Identification number', 'sticpa').':</label>
            </span></td>
          <td id="td_Contacts___stic_identification_number_c" class="column_25"><span>
              <input id="Contacts___stic_identification_number_c" name="Contacts___stic_identification_number_c"
                type="text" span="" sugar="slot" readonly class="stic-locked-field" value="'.$stic_identification_number_c.'"/>
            </span></td>
        </tr>
      </tbody>'.(!$tutorIsUser ? '
      <tbody id="Contacts" class="section">
        <tr>
          <td colspan="4">
            <h5>'.__('Recipient contact data', 'sticpa').'</h5>
          </td>
        </tr>
        <tr>
          <td id="recipient_name" class="column_25"><span><label id="recipient_name"
                for="recipient_name">'.__('Full name', 'sticpa').':</label>
            </span></td>
          <td id="td_recipient_name" class="column_25"><span>
              <input id="recipient_name" name="recipient_name" type="text" span="" sugar="slot" readonly class="stic-locked-field" value="'.$_SESSION['scp_user_contact_name'].'" />
            </span></td>
        </tr>
        </tbody>' : '').
        '<tbody id="stic_Payment_Commitments" class="section">
        <tr>
          <td colspan="4">
            <h5>'.__('Payment data', 'sticpa').'</h5>
          </td>
        </tr>
        <tr>
          <td id="td_lbl_stic_Payment_Commitments___amount" class="column_25"><span>
              <label id="lbl_stic_Payment_Commitments___amount" for="stic_Payment_Commitments___amount">'.__('Amount', 'sticpa').': <span class="stic-required-mark" aria-hidden="true">*</span></label>
            </span></td>
          <td id="td_stic_Payment_Commitments___amount" class="column_25"><span>
              <input id="stic_Payment_Commitments___amount" name="stic_Payment_Commitments___amount" type="number" min="0"
                step="0.01" inputmode="decimal" span="" sugar="slot" required'.($forInscripcion ? ' readonly class="stic-locked-field"' : '').' value="'.esc_attr($suggestedAmount).'"/>
            </span></td>
        </tr>
        <tr>
          <td id="td_lbl_stic_Payment_Commitments___payment_method" class="column_25"><span>
              <label id="lbl_stic_Payment_Commitments___payment_method"
                for="stic_Payment_Commitments___payment_method">'.__('Payment method', 'sticpa').': <span class="stic-required-mark" aria-hidden="true">*</span></label>
              
          </td>
          <td id="td_stic_Payment_Commitments___payment_method" class="column_25"><span><select required
                id="stic_Payment_Commitments___payment_method" name="stic_Payment_Commitments___payment_method" onchange="adaptPaymentMethod(this)">
                '.$paymentMethodOptionsHtml.'
              </select></span></td>
        </tr>
        <tr>
          <td id="td_lbl_stic_Payment_Commitments___bank_account" class="column_25" style="display: none;"><span>
              <label id="lbl_stic_Payment_Commitments___bank_account" for="stic_Payment_Commitments___bank_account">'.__('Bank account', 'sticpa').':</label>
            </span></td>
          <td id="td_stic_Payment_Commitments___bank_account" class="column_25" style="display: none;"><span>
              <input id="stic_Payment_Commitments___bank_account" name="stic_Payment_Commitments___bank_account"
                type="text" onchange="validateIBAN(this)" span="" sugar="slot" />
            </span></td>
        </tr>
      </tbody>
      <tbody>
        <tr>
          <td> </td>
          <td><input class="stic-back-button" type="submit" name="Submit" value="'.__('Submit payment', 'sticpa').'" /></td>
        </tr>
      </tbody>
    </table>
  </form>
  <script>
  /**
   * Validate IBAN
   * @returns {Boolean}
   */
  function validateIBAN() {
    // v2018
    // If the payment method is not direct debit, the IBAN must not be validated
    if (document.getElementById("stic_Payment_Commitments___payment_method").value == "direct_debit") {
      var bankAccount = document.getElementById("stic_Payment_Commitments___bank_account");
      if (bankAccount == null) {
        // If there is no account number it will give error
        return false;
      } else {
        if (!IBAN.isValid(bankAccount.value)) {
          alert(stic_Payment_Commitments_LBL_IBAN_NOT_VALID);
          selectTextInput(bankAccount);
          return false;
        }
      }
    }
    return true;
  }

  // Set variables for manage recurring payment validations
  var oP = document.getElementById("allow_paypal_recurring_payments");
  var allowPaypalRecurringPayments = oP && oP.value == 1 ? 1 : 0;
  var oC = document.getElementById("allow_card_recurring_payments");
  var allowCardRecurringPayments = oC && oC.value == 1 ? 1 : 0;

  function adaptPaymentMethod() {

    var oPaymentMethod = document.getElementById("stic_Payment_Commitments___payment_method"); // Retrieve the html element of payment method
    var vPaymentMethod = oPaymentMethod.options[oPaymentMethod.selectedIndex].value;
    var oPeriodicity = document.getElementById("stic_Payment_Commitments___periodicity"); // Retrieve the html element of periodicity
    var vPeriodicity = oPeriodicity.value;

    // If the payment method has changed to card or bizum, check the periodicity
    if (((vPaymentMethod == "card" && allowCardRecurringPayments == 0)
      || (vPaymentMethod == "paypal" && allowPaypalRecurringPayments == 0)
      || vPaymentMethod == "bizum")
      && vPeriodicity && vPeriodicity != "punctual") {
      if (confirm(stic_Payment_Commitments_LBL_PERIODICITY_PUNCTUAL)) {
        // If you want to continue, punctual periodicity is indicated
        setSelectValue(oPeriodicity, "punctual");
      } else {
        setSelectValue(oPaymentMethod, oPaymentMethod.prev_value);
        return false;
      }
    }

    // If the payment method is a direct debit, it shows the account number field and marks it as required.
    if (vPaymentMethod == "direct_debit") {
      showField("stic_Payment_Commitments___bank_account");
      addRequired("stic_Payment_Commitments___bank_account");
    } else {
      hideField("stic_Payment_Commitments___bank_account");
      removeRequired("stic_Payment_Commitments___bank_account");
    }
    oPaymentMethod.prev_value = vPaymentMethod;
  }
  /**
   * Change the visibility of a field
   * @param field field to be changed
   * @param visibility visibility applied to the field
   */
     function changeVisibility(field, visibility) {
       var o_td = document.getElementById("td_" + field);
       var o_td_lbl = document.getElementById("td_lbl_" + field);
       if (o_td) {
         o_td.style.display = visibility;
       }
   
       if (o_td_lbl) {
         o_td_lbl.style.display = visibility;
       }
     }
   
     /**
      * Show a hidden field
      * @param field field to be shown
      */
     function showField(field) {
       changeVisibility(field, "table-cell");
     }
   
     /**
      * Hide a field
      * @param field field to be hidden
      */
     function hideField(field) {
       changeVisibility(field, "none");
     }
     /**
     * Delete a field as required
     * @param field field that will be set as no required
     */
    function removeRequired(field) {
    var reqs = document.getElementById("req_id").value;
    document.getElementById("req_id").value = reqs.replace(field + ";", "");
    var requiredLabel = document.getElementById("lbl_" + field + "_required");
    if (requiredLabel) {
        requiredLabel.parentNode.removeChild(requiredLabel);
    }
    }
    var formHasAlreadyBeenSent = false;
    /**
     * Prevent multiple form submissions
     *
     * @return void
     */
    function lockMultipleSubmissions() {
        if (formHasAlreadyBeenSent) {
            console.log("Form is locked because it has already been sent.");
            event.preventDefault();
        }
        formHasAlreadyBeenSent = true;
    }
    // Attach function to event
    document.getElementById("WebToLeadForm").addEventListener("submit", lockMultipleSubmissions);
  </script>
';

