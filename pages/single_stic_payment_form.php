<?php

#########################################################
# Custom page settings                                         #
#########################################################
$pageSettings['title'] = __('Payment form', 'sticpa'); // List title
#########################################################
$pageSettings['fileName'] = basename(__FILE__, ".php"); // List name, from the filename. Don't touch.

$html .= "<div class='stic-entry-header'>
<h4>".__('Payment form', 'sticpa')."</h4>";

if (isset($_REQUEST['eventId']) && $eventId = $_REQUEST['eventId']) {
  if (!$registrationId = $_REQUEST['registrationId']) {
    $html .= '<script>
    alert("'.__("There is no available registration.", 'sticpa').'");
    window.location.href = "?internalpage=list_stic_events";
    </script>';
  } 
  $eventData = $objSCP->getRecordDetail($eventId, 'stic_Events')->entry_list[0]->name_value_list;

  $eventName = $eventData->name->value;

  $html .= "<div class='stic-entry-header'>
  <h5>".__('Event', 'sticpa') .": ".$eventName."</h5>";
}

$objSCP = SugarRestApiCall::getObjSCP();

$fieldsDefinitionResults = $objSCP->getFieldDefinition('stic_Payment_Commitments', array('payment_method'));
$paymentMethodOptions = $fieldsDefinitionResults->module_fields->payment_method->options;
$paymentMethodOptionsHtml = "<option value='' label='' ></option>";
$paymentMethod = array('card', 'cash', 'direct_debit', 'bizum');
foreach($paymentMethod as $elem) {
    $paymentMethodOptionsHtml .= "<option value='".$elem."' label='{$paymentMethodOptions->$elem->value}' >{$paymentMethodOptions->$elem->value}</option>";
}


$userId = $_SESSION['scp_user_adult'] ? $_SESSION['scp_user_id'] : $_SESSION['scp_tutor_user_id'];
$userData = $objSCP->getRecordDetail($userId, 'Contacts')->entry_list[0]->name_value_list;
$email = $userData->email1->value;
$last_name = $userData->last_name->value;
$first_name = $userData->first_name->value;
$stic_identification_number_c = $userData->stic_identification_number_c->value;
$assignedUserId = '1';

if (!$email || !$last_name || !$first_name || !$stic_identification_number_c) {
    $html .= '<script>
    alert("'.__('There are missing fields to perform the payment. Please, fill the required fields in the next page.', 'sticpa').'");
    window.location.href = "?internalpage='.($_SESSION['scp_user_adult'] ? 'single_stic_profile' : 'single_stic_tutor_profile').'";
    </script>';
}
$hostUrl = get_option('sticpa_scp_host_url');
$tutorIsUser = $_SESSION['scp_tutor_is_user'] ?? ($_SESSION['scp_user_adult'] ?? false);

$html .= '
<form action="'.$hostUrl.'/index.php?entryPoint=stic_Web_Forms_save" name="WebToLeadForm"
    method="POST" id="WebToLeadForm">
    <p><input type="hidden" id="campaign_id" name="campaign_id" value="ab11ebc9-de54-9306-3eaf-6267c96fee96" />
      <input type="hidden" id="redirect_url" name="redirect_url"
        value="'.home_url().'/?internalpage=list_stic_payments" />
      <input type="hidden" id="redirect_ko_url" name="redirect_ko_url"
        value="'.home_url().'/?internalpage=single_stic_payment_error" />
      <input type="hidden" id="validate_identification_number" name="validate_identification_number" value="0" />
      <input type="hidden" id="allow_card_recurring_payments" name="allow_card_recurring_payments" value="0" />
      <input type="hidden" id="allow_paypal_recurring_payments" name="allow_paypal_recurring_payments" value="0" />
      <input type="hidden" id="assigned_user_id" name="assigned_user_id" value="'.$assignedUserId.'" />
      <input type="hidden" id="req_id" name="req_id"
        value="Contacts___first_name;Contacts___last_name;Contacts___email1;Contacts___stic_identification_number_c;stic_Payment_Commitments___amount;stic_Payment_Commitments___payment_method;stic_Payment_Commitments___periodicity;" />
      <input type="hidden" id="bool_id" name="bool_id" value="" />
      <input type="hidden" id="webFormClass" name="webFormClass" value="Donation" />
      <input type="hidden" id="stic_Payment_Commitments___payment_type" name="stic_Payment_Commitments___payment_type"
        value="donation" />
      <input type="hidden" id="web_module" name="web_module" value="Contacts" />
      <input type="hidden" id="language" name="language" value="es_ES" />
      <input type="hidden" id="defParams" name="defParams"
        value="%7B%22version%22%3A%222%22%2C%22email_template_id%22%3A%22%22%2C%22relation_type%22%3A%22%22%7D" />
      <input type="hidden" id="timeZone" name="timeZone" value="" />
      <input type="hidden" id="stic_Payment_Commitments___periodicity" name="stic_Payment_Commitments___periodicity"
                value="punctual" />
      <input id="stic_Payment_Commitments___stic_payment_commitments_contacts_1contacts_ida" name="stic_Payment_Commitments___stic_payment_commitments_contacts_1contacts_ida"
                type="hidden" span="" sugar="slot" value="'.($_SESSION['scp_user_adult'] ? '' : $_SESSION['scp_user_id']).'"/>
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
              <input id="Contacts___first_name" name="Contacts___first_name" type="text" span="" sugar="slot" readonly style="background: #fdf8c8 !important" value="'.$first_name.'" />
            </span></td>
        </tr>
        <tr>
          <td id="td_lbl_Contacts___last_name" class="column_25"><span><label id="lbl_Contacts___last_name"
                for="Contacts___last_name">'.__('Last name', 'sticpa').':</label>
            </span></td>
          <td id="td_Contacts___last_name" class="column_25"><span>
              <input id="Contacts___last_name" name="Contacts___last_name" type="text" span="" sugar="slot" readonly style="background: #fdf8c8 !important" value="'.$last_name.'" />
            </span></td>
        </tr>
        <tr>
          <td id="td_lbl_Contacts___email1" class="column_25"><span><label id="lbl_Contacts___email1"
                for="Contacts___email1">'.__('Email', 'sticpa').':</label>
            </span></td>
          <td id="td_Contacts___email1" class="column_25"><span>
              <input id="Contacts___email1" name="Contacts___email1" type="text" span="" sugar="slot" readonly style="background: #fdf8c8 !important" value="'.$email.'"/>
            </span></td>
        </tr>
        <tr>
          <td id="td_lbl_Contacts___stic_identification_number_c" class="column_25"><span>
              <label id="lbl_Contacts___stic_identification_number_c" for="Contacts___stic_identification_number_c">'.__('Identification number', 'sticpa').':</label>
            </span></td>
          <td id="td_Contacts___stic_identification_number_c" class="column_25"><span>
              <input id="Contacts___stic_identification_number_c" name="Contacts___stic_identification_number_c"
                type="text" span="" sugar="slot" readonly style="background: #fdf8c8 !important" value="'.$stic_identification_number_c.'"/>
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
              <input id="recipient_name" name="recipient_name" type="text" span="" sugar="slot" readonly style="background: #fdf8c8 !important" value="'.$_SESSION['scp_user_contact_name'].'" />
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
              <label id="lbl_stic_Payment_Commitments___amount" for="stic_Payment_Commitments___amount">'.__('Amount', 'sticpa').': <span id="lbl_stic_Payment_Commitments___payment_method_required" style="color: red;">*</span></label>
            </span></td>
          <td id="td_stic_Payment_Commitments___amount" class="column_25"><span>
              <input id="stic_Payment_Commitments___amount" name="stic_Payment_Commitments___amount" type="number" min="0"
                step="0.01" span="" sugar="slot" required/>
            </span></td>
        </tr>
        <tr>
          <td id="td_lbl_stic_Payment_Commitments___payment_method" class="column_25"><span>
              <label id="lbl_stic_Payment_Commitments___payment_method"
                for="stic_Payment_Commitments___payment_method">'.__('Payment method', 'sticpa').': <span id="lbl_stic_Payment_Commitments___payment_method_required" style="color: red;">*</span></label>
              
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

