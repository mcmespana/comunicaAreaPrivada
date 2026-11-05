<?php
/**
 * FORMA DE PAGO (05/11/2026).
 * ----------------------------------------------------------------------------
 * Cualquiera que entre al área ve aquí cómo paga y puede cambiarlo: el medio
 * (domiciliación, transferencia o Bizum), el IBAN y el titular de la cuenta.
 *
 * Es de QUIEN PAGA. Un menor no tiene forma de pago propia: paga su familiar,
 * así que un familiar que mira a Lucía edita SU forma de pago, la que se usa
 * para las cosas de Lucía. La persona sale de la sesión, nunca de la petición
 * (sticpa_registration_payer()). Toda la lógica —y por qué solo se toca lo que
 * aún no se ha cobrado— está en inc/stic-forma-pago.php.
 *
 * El IBAN NO se pinta entero: el campo llega vacío y, si no se toca, se queda
 * el que había. Guardado: prefix_admin_single_stic_forma_pago (inc/stic-action.php).
 */

$objSCP = SugarRestApiCall::getObjSCP();

$payer = sticpa_registration_payer();
$payerId = (string) $payer['pagador'];
$menorNombre = function_exists('sticpa_viendo_a_nombre') ? sticpa_viendo_a_nombre() : '';

$ficha = sticpa_fp_read($objSCP, $payerId);
if (!$ficha['ok']) {
    // Sin poder leer lo guardado, un formulario vacío diría «todavía no has
    // indicado cómo pagas» y se guardaría a ciegas.
    $html .= "<div class='stic-entry-header'><h3>" . esc_html__('Forma de pago', 'sticpa') . "</h3></div>";
    $html .= sticpa_record_note_html(array('tone' => 'warn', 'text' => __('No hemos podido leer tu forma de pago ahora mismo. Vuelve a intentarlo en un momento.', 'sticpa')));
    return;
}
$methods = sticpa_fp_methods($objSCP);
$domiciliacion = sticpa_fp_direct_debit_key();

// El resumen de lo que hay guardado, con la cuenta tapada.
$formaActual = $ficha['forma'];
$resumen = '';
if ($formaActual !== '' && isset($methods[$formaActual])) {
    $partes = array('<strong>' . esc_html($methods[$formaActual]) . '</strong>');
    if ($formaActual === $domiciliacion && $ficha['iban'] !== '') {
        $partes[] = esc_html(sticpa_fp_mask($ficha['iban']));
        if ($ficha['titular'] !== '') {
            /* translators: %s = nombre del titular de la cuenta */
            $partes[] = esc_html(sprintf(__('titular: %s', 'sticpa'), $ficha['titular']));
        }
    }
    $resumen = sprintf(__('Ahora mismo: %s.', 'sticpa'), implode(' · ', $partes));
} else {
    $resumen = esc_html__('Todavía no has indicado cómo pagas.', 'sticpa');
}

// El mensaje de «guardado» dice también qué ha pasado con lo pendiente de cobro.
$fpc = (int) ($_GET['fpc'] ?? 0); // compromisos actualizados
$fpp = (int) ($_GET['fpp'] ?? 0); // pagos pendientes actualizados
$fpe = (int) ($_GET['fpe'] ?? 0); // compromisos con un recibo ya en curso: no se tocan
$fpm = (int) ($_GET['fpm'] ?? 0); // compromisos marcados para que tesorería los revise
$guardado = __('Listo: tu forma de pago está guardada.', 'sticpa');
if ($fpp > 0) {
    $guardado .= ' ' . sprintf(_n(
        'Hemos puesto la cuenta nueva en %d pago pendiente de cobro.',
        'Hemos puesto la cuenta nueva en %d pagos pendientes de cobro.',
        $fpp, 'sticpa'), $fpp);
}
if ($fpe > 0) {
    $guardado .= ' ' . __('Hay un recibo que ya está en curso (en remesa o devuelto): ese no se cambia, y tesorería lo revisará con vosotros.', 'sticpa');
}
if ($fpm > 0) {
    $guardado .= ' ' . __('Hemos avisado a tesorería de que ya no quieres domiciliar.', 'sticpa');
}

$formSettings['moduleName'] = 'Contacts';
$formSettings['title'] = __('Forma de pago', 'sticpa');
$formSettings['msg'][] = array('value' => 'true', 'type' => 'success', 'msg' => esc_html($guardado));
$formSettings['msg'][] = array('value' => 'forma', 'type' => 'error', 'msg' => __('Elige cómo quieres pagar.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'iban', 'type' => 'error', 'msg' => __('Ese IBAN no parece válido. Revisa que lo hayas copiado entero.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'titular', 'type' => 'error', 'msg' => __('Escribe el nombre del titular de la cuenta.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'error', 'type' => 'error', 'msg' => __('No hemos podido guardarlo. Inténtalo de nuevo en un momento.', 'sticpa'));
$formSettings['msg'][] = array('value' => 'parcial', 'type' => 'error', 'msg' => __('Tu forma de pago está guardada, pero no hemos podido cambiar la cuenta en algún pago pendiente. Avisa a tesorería de tu delegación para que lo revise.', 'sticpa'));
$formSettings['submitButton'] = __('Guardar', 'sticpa');
$formSettings['submitButtonActions'] = array();
$formSettings['fileName'] = basename(__FILE__, '.php');

if ($menorNombre !== '') {
    $contexto = sprintf(
        /* translators: %s = nombre de pila del participante */
        __('Es <strong>tu</strong> forma de pago, no la de %1$s: con ella se cobran las actividades de %1$s.', 'sticpa'),
        esc_html($menorNombre)
    );
} else {
    $contexto = __('Con ella se cobran tus cuotas y las actividades a las que te apuntes.', 'sticpa');
}

$fieldList[] = array('name' => 'contexto_nota', 'type' => 'note', 'classes' => 'stic-note-soft', 'html' => $contexto);
$fieldList[] = array('name' => 'resumen_nota', 'type' => 'note', 'html' => $resumen);

$fieldList[] = array(
    'name' => 'ajmcm_forma_pago_c', 'type' => 'radio', 'required' => true,
    'label' => __('¿Cómo quieres pagar?', 'sticpa'),
    'selectValues' => $methods,
    'defaultValue' => isset($methods[$formaActual]) ? $formaActual : '',
);
$fieldList[] = array(
    'name' => 'ajmcm_iban_c', 'type' => 'text', 'required' => false,
    'label' => __('IBAN', 'sticpa'),
    'placeholder' => $ficha['iban'] !== '' ? sticpa_fp_mask($ficha['iban']) : 'ES00 0000 0000 0000 0000 0000',
    'hint' => $ficha['iban'] !== ''
        ? __('Déjalo vacío si no quieres cambiar la cuenta.', 'sticpa')
        : __('La cuenta en la que se cargarán los recibos.', 'sticpa'),
    'defaultValue' => '',
    'attributes' => array(
        'autocomplete' => 'off', 'spellcheck' => 'false', 'inputmode' => 'text',
        'data-visible-when' => 'ajmcm_forma_pago_c:' . $domiciliacion,
    ),
    'actions' => array('onchange' => 'sticFpVerifyIban'),
);
$fieldList[] = array(
    'name' => 'ajmcm_iban_titular_c', 'type' => 'text', 'required' => false,
    'label' => __('Titular de la cuenta', 'sticpa'),
    'defaultValue' => $ficha['titular'] !== '' ? $ficha['titular'] : $ficha['nombre'],
    'attributes' => array(
        'autocomplete' => 'name',
        'data-visible-when' => 'ajmcm_forma_pago_c:' . $domiciliacion,
    ),
);
$fieldList[] = array(
    'name' => 'efecto_nota', 'type' => 'note',
    'html' => __('Si tienes recibos domiciliados que todavía no han pasado al banco, se cambiarán a la cuenta nueva. Lo que ya se ha cobrado, o está en una remesa, no se toca.', 'sticpa'),
);

$html .= makeForm($fieldList, $formSettings, null);

// Misma validación de IBAN que el resto del área (js/iban.js); el servidor
// vuelve a comprobarlo y es el que manda.
$html .= "
<script>
function sticFpVerifyIban(obj) {
    obj.value = obj.value.replace(/[^a-zA-Z0-9]/g, '').toUpperCase();
    if (!obj.value) { obj.removeAttribute('invalid'); return; }
    if (typeof IBAN !== 'undefined' && !IBAN.isValid(obj.value)) {
        alert((typeof stic_script_vars !== 'undefined' && stic_script_vars.wrongIban) ? stic_script_vars.wrongIban : 'El IBAN no parece válido.');
        obj.setAttribute('invalid', '');
    } else {
        obj.removeAttribute('invalid');
    }
}
</script>";
