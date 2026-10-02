<?php

#########################################################
# Custom page settings                                         #
#########################################################
$pageSettings['title'] = __('Payment form', 'sticpa'); // List title
#########################################################
$pageSettings['fileName'] = basename(__FILE__, ".php"); // List name, from the filename. Don't touch.

$html .= "<div class='stic-entry-header'>
<h4>".esc_html__('Pagar con tarjeta', 'sticpa')."</h4>";

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
    $volver = '?' . $vuelta;
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
    $volver = '?internalpage=single_stic_registrations&action=detail&id=' . rawurlencode($registrationId);
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
// Solo tarjeta: los demás medios se eligen al inscribirse y no pasan por aquí.
$hostUrl = get_option('sticpa_scp_host_url');
$tutorIsUser = $_SESSION['scp_tutor_is_user'] ?? ($_SESSION['scp_user_adult'] ?? false);
$importeTxt = function_exists('formatValue') ? formatValue($suggestedAmount, 'currency') : $suggestedAmount . ' €';

// NADA DE ESTO SE EDITA (02/10/2026). Antes eran cajas de texto con los datos
// de quien paga y el importe: de solo lectura, pero con pinta de formulario, y
// tocar el nombre o el importe aquí es crear un pago que no cuadra con nada.
// Ahora es un resumen, y lo que viaja al CRM va en campos ocultos.
//
// LA ÚNICA EXCEPCIÓN ES EL IMPORTE, y a propósito: hay descuentos, becas y
// arreglos que no están en el CRM. «Pagar otra cantidad» lo abre, y la
// cantidad nueva queda escrita en la descripción del compromiso, para que en
// tesorería se vea que la cambió la persona y desde cuánto.
$facts = array(
    array('icon' => 'user', 'label' => __('Paga', 'sticpa'), 'text' => trim($first_name . ' ' . $last_name) . ' · ' . $email),
);
if (!$tutorIsUser && !empty($_SESSION['scp_user_contact_name'])) {
    $facts[] = array('icon' => 'users', 'label' => __('Para', 'sticpa'), 'text' => (string) $_SESSION['scp_user_contact_name']);
}
$facts[] = array('icon' => 'card', 'label' => __('Cómo', 'sticpa'), 'text' => __('Con tarjeta, en la pasarela segura del banco', 'sticpa'));

$factsHtml = '';
foreach ($facts as $f) {
    $factsHtml .= "<li class='stic-rec-fact'><span class='stic-rec-fact-ico'>" . sticpa_record_icon($f['icon']) . "</span>"
        . "<span class='stic-rec-fact-body'><span class='stic-rec-fact-label'>" . esc_html($f['label']) . "</span>"
        . "<span class='stic-rec-fact-text'>" . esc_html($f['text']) . "</span></span></li>";
}

$hidden = function ($name, $value) {
    return '<input type="hidden" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '" />';
};

$html .= '
<form action="' . esc_url($hostUrl . '/index.php?entryPoint=stic_Web_Forms_save') . '" name="WebToLeadForm"
    method="POST" id="WebToLeadForm" class="stic-pay">'
    . $hidden('campaign_id', sticpa_card_campaign_id())
    . $hidden('redirect_url', $redirectOk)
    . $hidden('redirect_ko_url', sticpa_area_absolute_url('internalpage=single_stic_payment_error'))
    . $hidden('validate_identification_number', '0')
    . $hidden('allow_card_recurring_payments', '0')
    . $hidden('allow_paypal_recurring_payments', '0')
    . $hidden('assigned_user_id', $assignedUserId)
    . $hidden('req_id', 'Contacts___first_name;Contacts___last_name;Contacts___email1;Contacts___stic_identification_number_c;stic_Payment_Commitments___amount;stic_Payment_Commitments___payment_method;stic_Payment_Commitments___periodicity;')
    . $hidden('bool_id', '')
    . $hidden('webFormClass', 'Donation')
    . $hidden('stic_Payment_Commitments___payment_type', $paymentType)
    . $hidden('stic_Payment_Commitments___description', $paymentDescription)
    . $hidden('web_module', 'Contacts')
    . $hidden('language', 'es_ES')
    . '<input type="hidden" id="defParams" name="defParams" value="%7B%22version%22%3A%222%22%2C%22email_template_id%22%3A%22%22%2C%22relation_type%22%3A%22%22%7D" />'
    . $hidden('timeZone', '')
    . $hidden('stic_Payment_Commitments___periodicity', 'punctual')
    . $hidden('stic_Payment_Commitments___payment_method', 'card')
    . $hidden('stic_Payment_Commitments___stic_payment_commitments_contacts_1contacts_ida', $_SESSION['scp_user_adult'] ? '' : $_SESSION['scp_user_id'])
    . ($bankingConcept !== '' ? $hidden('stic_Payment_Commitments___banking_concept', $bankingConcept) : '')
    . $hidden('Contacts___first_name', $first_name)
    . $hidden('Contacts___last_name', $last_name)
    . $hidden('Contacts___email1', $email)
    . $hidden('Contacts___stic_identification_number_c', $stic_identification_number_c)
    . $hidden('stic_Payment_Commitments___amount', $suggestedAmount)
    . '
  <div class="stic-pay-card">
    <p class="stic-pay-concept">' . esc_html($bankingConcept) . '</p>
    <p class="stic-pay-amount"><span class="stic-pay-amount-label">' . esc_html__('Importe', 'sticpa') . '</span>
      <strong id="stic-pay-amount-txt" data-original="' . esc_attr($suggestedAmount) . '">' . esc_html($importeTxt) . '</strong></p>
    <div class="stic-pay-otro" id="stic-pay-otro" hidden>
      <label for="stic-pay-otro-importe">' . esc_html__('¿Cuánto vas a pagar?', 'sticpa') . '</label>
      <div class="stic-pay-otro-campo">
        <input type="number" id="stic-pay-otro-importe" min="1" step="0.01" inputmode="decimal" value="' . esc_attr($suggestedAmount) . '" />
        <span aria-hidden="true">€</span>
      </div>
      <p class="stic-pay-otro-nota" id="stic-pay-otro-nota">' . esc_html__('Solo si te han dicho que pagues otra cantidad: un descuento, una beca, un arreglo con tu delegación. Lo que pagues es lo que quedará como pagado.', 'sticpa') . '</p>
    </div>
    <button type="button" class="stic-pay-otro-toggle" id="stic-pay-otro-toggle" aria-expanded="false" aria-controls="stic-pay-otro"
      data-abrir="' . esc_attr__('Pagar otra cantidad', 'sticpa') . '"
      data-cerrar="' . esc_attr(sprintf(__('Pagar lo indicado (%s)', 'sticpa'), $importeTxt)) . '">' . esc_html__('Pagar otra cantidad', 'sticpa') . '</button>
  </div>
  <ul class="stic-rec-facts">' . $factsHtml . '</ul>
  <div class="stic-rec-cta-row stic-pay-cta">
    <button type="submit" class="stic-rec-btn stic-rec-btn--primary stic-rec-btn--lg stic-pay-submit" id="stic-pay-submit"
      data-plantilla="' . esc_attr__('Pagar %s', 'sticpa') . '">' . esc_html(sprintf(__('Pagar %s', 'sticpa'), $importeTxt)) . '</button>
    <a class="stic-rec-btn stic-rec-btn--ghost" href="' . esc_url($volver) . '">' . esc_html__('Ahora no', 'sticpa') . '</a>
    <p class="stic-rec-cta-note">' . esc_html__('Te llevamos a la pasarela del banco. Al terminar vuelves aquí y lo verás como pagado.', 'sticpa') . '</p>
  </div>
</form>
<script>
(function () {
  var form = document.getElementById("WebToLeadForm");
  var oculto = document.getElementById("stic_Payment_Commitments___amount");
  var desc = document.getElementById("stic_Payment_Commitments___description");
  var caja = document.getElementById("stic-pay-otro");
  var campo = document.getElementById("stic-pay-otro-importe");
  var toggle = document.getElementById("stic-pay-otro-toggle");
  var txt = document.getElementById("stic-pay-amount-txt");
  var original = txt.getAttribute("data-original");
  var descOriginal = desc.value;
  var fmt = function (n) {
    try { return new Intl.NumberFormat("es-ES", { style: "currency", currency: "EUR" }).format(n); }
    catch (e) { return n.toFixed(2).replace(".", ",") + " €"; }
  };
  var valor = function () {
    var n = parseFloat(String(campo.value).replace(",", "."));
    return isFinite(n) ? Math.round(n * 100) / 100 : NaN;
  };
  var boton = document.getElementById("stic-pay-submit");
  var pinta = function () {
    var n = caja.hidden ? parseFloat(original) : valor();
    var ok = isFinite(n) && n >= 1;
    txt.textContent = ok ? fmt(n) : "—";
    boton.textContent = boton.getAttribute("data-plantilla").replace("%s", ok ? fmt(n) : "");
    boton.disabled = !ok;
  };
  toggle.addEventListener("click", function () {
    var abrir = caja.hidden;
    caja.hidden = !abrir;
    toggle.setAttribute("aria-expanded", abrir ? "true" : "false");
    toggle.textContent = toggle.getAttribute(abrir ? "data-cerrar" : "data-abrir");
    if (abrir) { campo.focus(); campo.select && campo.select(); } else { campo.value = original; }
    pinta();
  });
  campo.addEventListener("input", pinta);

  var enviado = false;
  form.addEventListener("submit", function (ev) {
    if (enviado) { ev.preventDefault(); return; }
    var n = caja.hidden ? parseFloat(original) : valor();
    if (!isFinite(n) || n < 1) {
      ev.preventDefault();
      caja.hidden || campo.focus();
      return;
    }
    oculto.value = n.toFixed(2);
    desc.value = descOriginal;
    if (n.toFixed(2) !== parseFloat(original).toFixed(2)) {
      desc.value += " Importe cambiado por la persona al pagar: " + n.toFixed(2) + " (lo indicado era " + parseFloat(original).toFixed(2) + ").";
    }
    enviado = true;
  });
})();
</script>
';
