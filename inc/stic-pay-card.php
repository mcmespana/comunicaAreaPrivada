<?php
/**
 * PAGAR CON TARJETA LO QUE SE DEBE (plan 041, 01/10/2026).
 * ----------------------------------------------------------------------------
 * Lo que se debe es un PAGO pendiente: el que el CRM genera solo al crear el
 * compromiso de una inscripción (`pending`, o `not_remitted` si es
 * domiciliación). Pagarlo con transferencia, Bizum o en mano lo marca la
 * delegación; con domiciliación, la remesa.
 *
 * CON TARJETA NO SE PUEDE SALDAR ESE PAGO: en SinergiaCRM el único camino al
 * TPV es su formulario web clásico, y ese formulario SIEMPRE crea un compromiso
 * nuevo (ver el plan 041 §1.1). Así que pagar con tarjeta es SUSTITUIR:
 *
 *   1. El formulario de tarjeta lleva en la descripción una marca firmada con
 *      el id del pago que viene a saldar: `[pago:<id>:<firma>]`.
 *   2. A la vuelta (sticpa_pay_claim()), si el pago con tarjeta está cobrado,
 *      el área le pasa lo del viejo —las inscripciones, el tipo y el nombre—
 *      y CIERRA el viejo: su compromiso con fecha de fin y una nota, y su pago
 *      sin cobrar, anulado. La marca se reescribe para no repetir el trabajo.
 *   3. Si el intento no se termina, a las 24 h se cierra él (decisión D-4 del
 *      plan): un intento abandonado no es una deuda, y dejarlo era ruido en las
 *      listas de la delegación y en «Pagos».
 *
 * Nada se borra si hay dinero cobrado. Lo único que puede borrarse es el pago
 * SIN COBRAR de un compromiso sustituido, y solo si el CRM no tiene un estado
 * de «anulado» al que pasarlo (sticpa_pay_cancel_status()).
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Horas que se espera a un intento de tarjeta antes de darlo por abandonado. */
function sticpa_pay_abandon_hours()
{
    return (int) apply_filters('sticpa_pay_abandon_hours', 24);
}

/**
 * La marca firmada de un pago: `[pago:<id>:<firma>]`. Firmada por lo mismo que
 * la de la inscripción: el formulario web del CRM está abierto a internet.
 */
function sticpa_pay_marker($paymentId)
{
    $paymentId = strtolower(trim((string) $paymentId));
    if (!preg_match('/^[0-9a-f-]{36}$/', $paymentId) || !function_exists('sticpa_form_secret')) {
        return '';
    }
    $firma = substr(hash_hmac('sha256', 'pago|' . $paymentId, sticpa_form_secret()), 0, 16);
    return '[pago:' . $paymentId . ':' . $firma . ']';
}

/**
 * ¿Se puede pagar con tarjeta este pago? Si está pendiente (y no es una
 * domiciliación, que la cobra la remesa) o si no se pudo cobrar (rechazado,
 * devuelto). Lo cobrado y lo que va a remesa, no.
 */
function sticpa_pay_is_payable($status, $method)
{
    $status = strtolower(trim((string) $status));
    $tone = function_exists('sticpa_record_status_tone') ? sticpa_record_status_tone($status) : '';
    if ($tone === 'danger') {
        return true;
    }
    return $status === 'pending' && strtolower(trim((string) $method)) !== 'direct_debit';
}

/** Campos que se leen de un pago para pagarlo. */
function sticpa_pay_fields()
{
    return array('id', 'name', 'amount', 'status', 'payment_method', 'payment_type', 'payment_date',
        'assigned_user_id', 'banking_concept', 'stic_paymebfe2itments_ida');
}

/**
 * El pago, su compromiso y las inscripciones del compromiso: todo lo que hace
 * falta para cobrarlo con tarjeta y, a la vuelta, para sustituirlo.
 *
 * @return array|null null si el pago no existe.
 */
function sticpa_pay_context($objSCP, $paymentId)
{
    $detail = $objSCP->getRecordDetail((string) $paymentId, 'stic_Payments', sticpa_pay_fields());
    $nvl = $detail->entry_list[0]->name_value_list ?? null;
    if (!$nvl) {
        return null;
    }
    $val = function ($o, $f) {
        return isset($o->$f->value) ? trim((string) $o->$f->value) : '';
    };
    $pcId = $val($nvl, 'stic_paymebfe2itments_ida');
    $pc = null;
    $regs = array();
    if ($pcId !== '') {
        $pcDetail = $objSCP->getRecordDetail($pcId, 'stic_Payment_Commitments',
            array('id', 'name', 'banking_concept', 'description', 'end_date', 'payment_type', 'assigned_user_id'));
        $pc = $pcDetail->entry_list[0]->name_value_list ?? null;
        $rows = $objSCP->getRelatedElementsForLoggedUser(array(
            'module_name' => 'stic_Payment_Commitments',
            'module_id' => $pcId,
            'link_field_name' => 'stic_payment_commitments_stic_registrations',
            'related_fields' => array('id', 'name'),
            'related_module_link_name_to_fields_array' => array(),
            'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
        ));
        foreach ((array) $rows as $row) {
            $rid = (string) ($row->id ?? ($row->name_value_list->id->value ?? ''));
            if ($rid !== '') {
                $regs[] = $rid;
            }
        }
    }
    $concepto = $pc ? $val($pc, 'banking_concept') : '';
    if ($concepto === '') {
        $concepto = $val($nvl, 'banking_concept');
    }
    if ($concepto === '' && function_exists('sticpa_payment_concept')) {
        $concepto = sticpa_payment_concept($val($nvl, 'name'))['title'];
    }
    return array(
        'id'            => (string) $paymentId,
        'amount'        => $val($nvl, 'amount'),
        'status'        => $val($nvl, 'status'),
        'method'        => $val($nvl, 'payment_method'),
        'type'          => $val($nvl, 'payment_type'),
        'assigned'      => $val($nvl, 'assigned_user_id') !== '' ? $val($nvl, 'assigned_user_id') : ($pc ? $val($pc, 'assigned_user_id') : ''),
        'concept'       => $concepto,
        'commitment_id' => $pcId,
        'commitment'    => $pc,
        'registrations' => $regs,
    );
}

/**
 * La clave de «anulado» del desplegable de estado de Pagos, si el CRM tiene
 * una. Se busca en la definición del CRM (no se inventa: la API no valida los
 * desplegables). '' si no hay ninguna.
 */
function sticpa_pay_cancel_status($objSCP)
{
    if (!function_exists('sticpa_cached_field_definition') || !function_exists('sticpa_crm_enum_options')) {
        return '';
    }
    $opts = sticpa_crm_enum_options(sticpa_cached_field_definition($objSCP, 'stic_Payments', array('status')), 'status');
    foreach ($opts as $key => $label) {
        if (preg_match('/cancel|anulad/i', $key . ' ' . $label)) {
            return (string) $key;
        }
    }
    return '';
}

/**
 * Cierra un compromiso: fecha de fin hoy, inactivo y una nota que dice por
 * qué. Sus pagos SIN COBRAR pasan a «anulado» (o, si el CRM no tiene ese
 * estado, se borran: no hay dinero en ellos). Los cobrados no se tocan.
 *
 * @param string $de,$a Si vienen, en la descripción se cambia la marca $de
 *                      por $a (para que no se vuelva a reclamar).
 */
function sticpa_pay_close_commitment($objSCP, $commitmentId, $nota, $de = '', $a = '')
{
    $detail = $objSCP->getRecordDetail((string) $commitmentId, 'stic_Payment_Commitments', array('id', 'description'));
    $desc = trim((string) ($detail->entry_list[0]->name_value_list->description->value ?? ''));
    if ($de !== '') {
        $desc = str_replace($de, $a, $desc);
    }
    $desc .= ($desc !== '' ? "\n" : '') . $nota;
    $objSCP->set_entry('stic_Payment_Commitments', array(
        'id' => (string) $commitmentId,
        'end_date' => date('Y-m-d'),
        'active' => 0,
        'description' => $desc,
    ));

    $anulado = sticpa_pay_cancel_status($objSCP);
    foreach (sticpa_commitment_payments($objSCP, $commitmentId) as $row) {
        $pid = (string) ($row->id ?? ($row->name_value_list->id->value ?? ''));
        $status = (string) ($row->name_value_list->status->value ?? '');
        // Solo lo que estaba ESPERANDO cobrarse. Lo cobrado no se toca, y un
        // devuelto o rechazado se queda como está: es un hecho del banco.
        if ($pid === '' || !in_array($status, array('pending', 'not_remitted'), true)) {
            continue;
        }
        $objSCP->set_entry('stic_Payments', $anulado !== ''
            ? array('id' => $pid, 'status' => $anulado)
            : array('id' => $pid, 'deleted' => 1));
    }
}

/** ¿Un intento de tarjeta lleva más de sticpa_pay_abandon_hours() sin cobrarse? */
function sticpa_pay_is_stale($dateEntered)
{
    $dateEntered = trim((string) $dateEntered);
    if ($dateEntered === '') {
        return false;
    }
    // La API v4.1 da las fechas en UTC y sin zona.
    $ts = strtotime($dateEntered . ' UTC');
    return $ts !== false && (time() - $ts) > sticpa_pay_abandon_hours() * 3600;
}

/**
 * A LA VUELTA DEL TPV: busca el pago con tarjeta que viene a saldar
 * $paymentId y, si está cobrado, hace la sustitución. Si es un intento
 * abandonado, lo cierra.
 *
 * @return string 'pagado' si se ha sustituido, 'abandonado' si se cerró un
 *                intento, '' si no había nada.
 */
function sticpa_pay_claim($objSCP, $paymentId)
{
    $marca = sticpa_pay_marker($paymentId);
    if ($objSCP === null || $marca === '') {
        return '';
    }
    // La marca solo lleva hexadecimales, guiones, dos puntos y corchetes:
    // nada que escapar en un LIKE.
    $rows = $objSCP->getRecordsModule('stic_Payment_Commitments',
        "stic_payment_commitments.description LIKE '%" . $marca . "%'",
        array('id', 'name', 'payment_type', 'date_entered'));
    $hecho = '';
    foreach ((array) $rows as $row) {
        $nvl = $row->name_value_list ?? null;
        $cid = (string) ($row->id ?? ($nvl->id->value ?? ''));
        if ($cid === '') {
            continue;
        }
        $pagos = sticpa_commitment_payments($objSCP, $cid);
        $cobrado = false;
        foreach ($pagos as $p) {
            $cobrado = $cobrado || (string) ($p->name_value_list->status->value ?? '') === 'paid';
        }

        if (!$cobrado) {
            if (sticpa_pay_is_stale($nvl->date_entered->value ?? '')) {
                sticpa_pay_close_commitment($objSCP, $cid,
                    sprintf('Intento de pago con tarjeta sin terminar: cerrado por el área privada el %s.', date('d/m/Y')),
                    $marca, '[abandonado:' . strtolower($paymentId) . ']');
                $hecho = $hecho ?: 'abandonado';
            }
            continue;
        }

        // COBRADO: el nuevo hereda lo del viejo y el viejo se cierra.
        $ctx = sticpa_pay_context($objSCP, $paymentId);
        foreach ((array) ($ctx['registrations'] ?? array()) as $regId) {
            $objSCP->set_relationship('stic_Payment_Commitments', $cid,
                'stic_payment_commitments_stic_registrations', array($regId));
        }
        $tipo = (string) ($ctx['type'] ?? '');
        sticpa_card_commitment_as_service($objSCP, $cid, (string) ($nvl->name->value ?? ''),
            (string) ($nvl->payment_type->value ?? ''), $pagos, $tipo !== '' && $tipo !== 'donation' ? $tipo : null);
        if (!empty($ctx['commitment_id']) && $ctx['commitment_id'] !== $cid) {
            sticpa_pay_close_commitment($objSCP, $ctx['commitment_id'],
                sprintf('Pagado con tarjeta desde el área privada el %s (compromiso %s).', date('d/m/Y'), $cid));
        }
        // La marca, reescrita: este ya no se vuelve a reclamar.
        $desc = $objSCP->getRecordDetail($cid, 'stic_Payment_Commitments', array('id', 'description'));
        $texto = (string) ($desc->entry_list[0]->name_value_list->description->value ?? '');
        $objSCP->set_entry('stic_Payment_Commitments', array(
            'id' => $cid,
            'description' => str_replace($marca, '[pagado:' . strtolower($paymentId) . ']', $texto),
        ));
        $hecho = 'pagado';
    }
    return $hecho;
}

/**
 * CÓMO ESTÁ EL PAGO DE UNA INSCRIPCIÓN, en una palabra y con lo que hace falta
 * para enseñarlo y para pagarlo. Lo usa la ficha de la inscripción.
 *
 *   'pendiente'      → hay que pagar (transferencia, Bizum… o tarjeta)
 *   'devuelto'       → no se pudo cobrar (domiciliación devuelta, tarjeta
 *                      rechazada): se puede pagar con tarjeta
 *   'pagado'         → cobrado
 *   'domiciliado'    → entra en la próxima remesa: no hay nada que hacer
 *   'sin_compromiso' → la actividad cuesta algo y no hay nada anotado (las
 *                      inscripciones de antes del 01/10/2026): tarjeta por el
 *                      precio del evento, como antes
 *   ''               → nada que enseñar (gratis)
 *
 * De paso RECLAMA: si se vuelve del TPV, el pago con tarjeta sustituye al
 * pendiente (sticpa_pay_claim()). Los compromisos ya cerrados (sustituidos,
 * cancelados) no cuentan, salvo por lo que se cobró en ellos.
 *
 * @return array estado, importe, metodo, fecha (Y-m-d), pago_id.
 */
function sticpa_registration_payment_state($objSCP, $regId, $precio, $reclamar = true)
{
    $vacio = array('estado' => '', 'importe' => '', 'metodo' => '', 'fecha' => '', 'pago_id' => '');
    if ($objSCP === null || trim((string) $regId) === '') {
        return $vacio;
    }
    $pcs = sticpa_registration_commitments($objSCP, $regId, array('id', 'amount', 'payment_method', 'end_date'));
    if (empty($pcs)) {
        if ($precio > 0 && sticpa_registration_claim_card_commitment($objSCP, $regId) > 0) {
            return sticpa_registration_payment_state($objSCP, $regId, $precio, false);
        }
        return $precio > 0
            ? array_merge($vacio, array('estado' => 'sin_compromiso', 'importe' => number_format((float) $precio, 2, '.', '')))
            : $vacio;
    }

    $porEstado = array();
    foreach ((array) $pcs as $pc) {
        $pcId = (string) ($pc->id ?? ($pc->name_value_list->id->value ?? ''));
        $cerrado = trim((string) ($pc->name_value_list->end_date->value ?? '')) !== '';
        if ($pcId === '') {
            continue;
        }
        foreach (sticpa_commitment_payments($objSCP, $pcId) as $row) {
            $n = $row->name_value_list ?? null;
            $status = (string) ($n->status->value ?? '');
            $method = (string) ($n->payment_method->value ?? '');
            $tone = sticpa_record_status_tone($status);
            if ($status === 'paid') {
                $estado = 'pagado';
            } elseif ($cerrado) {
                continue;
            } elseif ($tone === 'danger') {
                $estado = 'devuelto';
            } elseif ($status === 'not_remitted') {
                $estado = 'domiciliado';
            } elseif (sticpa_pay_is_payable($status, $method)) {
                $estado = 'pendiente';
            } else {
                continue;
            }
            $porEstado[$estado] = $porEstado[$estado] ?? array(
                'estado'  => $estado,
                'importe' => (string) ($n->amount->value ?? ''),
                'metodo'  => $method,
                'fecha'   => (string) ($n->payment_date->value ?? ''),
                'pago_id' => (string) ($row->id ?? ($n->id->value ?? '')),
            );
        }
    }

    // Lo que pide hacer algo va primero; luego lo hecho; luego lo que llegará.
    foreach (array('devuelto', 'pendiente', 'pagado', 'domiciliado') as $estado) {
        if (!isset($porEstado[$estado])) {
            continue;
        }
        $hay = $porEstado[$estado];
        if ($reclamar && in_array($estado, array('devuelto', 'pendiente'), true)
            && sticpa_pay_claim($objSCP, $hay['pago_id']) === 'pagado') {
            return sticpa_registration_payment_state($objSCP, $regId, $precio, false);
        }
        return $hay;
    }
    return $vacio;
}

/** A dónde lleva «Pagar con tarjeta» un pago pendiente. */
function sticpa_pay_url($paymentId)
{
    return '?internalpage=single_stic_payment_form&paymentId=' . rawurlencode((string) $paymentId);
}

/**
 * El estado del pago dicho en una línea, para la ficha de la inscripción: un
 * dato («110,00 € · Pendiente · Transferencia») y, si hace falta, un aviso.
 *
 * @param array $pago      sticpa_registration_payment_state().
 * @param array $metodos   clave => etiqueta de `payment_method`.
 * @param bool  $esperando Recién vuelto del TPV y el banco aún no ha contestado.
 * @return array fact (o null), note (o null).
 */
function sticpa_payment_state_ui($pago, $metodos = array(), $esperando = false)
{
    $out = array('fact' => null, 'note' => null);
    $estado = (string) ($pago['estado'] ?? '');
    if ($estado === '') {
        return $out;
    }
    $importe = (string) ($pago['importe'] ?? '');
    $importeTxt = $importe !== '' ? (string) formatValue($importe, 'currency') : '';
    $metodo = (string) ($metodos[$pago['metodo'] ?? ''] ?? '');
    $fechaTs = !empty($pago['fecha']) ? strtotime((string) $pago['fecha']) : null;
    $fecha = $fechaTs ? sticpa_record_date_line($fechaTs) : '';
    $recibo = !empty($pago['pago_id'])
        ? array('url' => '?internalpage=single_stic_payments&action=detail&id=' . rawurlencode((string) $pago['pago_id']), 'label' => __('Ver el pago', 'sticpa'))
        : null;

    switch ($estado) {
        case 'pagado':
            $texto = implode(' · ', array_filter(array($importeTxt, __('Pagado', 'sticpa'), $metodo, $fecha)));
            $out['fact'] = array('icon' => 'check', 'label' => __('Pago', 'sticpa'), 'text' => $texto, 'link' => $recibo);
            break;
        case 'domiciliado':
            $texto = implode(' · ', array_filter(array($importeTxt,
                /* translators: %s = fecha del cobro */
                $fecha !== '' ? sprintf(__('Se cobra por domiciliación el %s', 'sticpa'), $fecha) : __('Se cobra por domiciliación', 'sticpa'))));
            $out['fact'] = array('icon' => 'bank', 'label' => __('Pago', 'sticpa'), 'text' => $texto, 'link' => $recibo);
            break;
        case 'devuelto':
            $texto = implode(' · ', array_filter(array($importeTxt, __('No se pudo cobrar', 'sticpa'))));
            $out['fact'] = array('icon' => 'card', 'label' => __('Pago', 'sticpa'), 'text' => $texto, 'link' => $recibo);
            $out['note'] = array('tone' => 'danger', 'text' => __('Este pago no se pudo cobrar. Puedes pagarlo con tarjeta o hablar con tu delegación.', 'sticpa'));
            break;
        case 'pendiente':
        case 'sin_compromiso':
            $texto = implode(' · ', array_filter(array($importeTxt, __('Pendiente de pagar', 'sticpa'), $estado === 'pendiente' && ($pago['metodo'] ?? '') !== 'card' ? $metodo : '')));
            $out['fact'] = array('icon' => 'card', 'label' => __('Pago', 'sticpa'), 'text' => $texto);
            if ($esperando) {
                $out['note'] = array('tone' => 'info', 'icon' => 'info', 'text' => __('Estamos esperando la confirmación del banco. En unos minutos lo verás como pagado.', 'sticpa'));
            } elseif ($estado === 'pendiente' && in_array($pago['metodo'] ?? '', array('transfer', 'bizum', 'cash'), true)) {
                $out['note'] = array('tone' => 'info', 'icon' => 'info', 'text' => __('¿Ya lo has pagado? Tu delegación lo marcará en cuanto lo vea.', 'sticpa'));
            }
            break;
    }
    return $out;
}
