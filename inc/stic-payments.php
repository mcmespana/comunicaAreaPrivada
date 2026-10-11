<?php
/**
 * PAGOS Y COMPROMISOS DE PAGO — listados y fichas.
 * ----------------------------------------------------------------------------
 * Son dos módulos hermanos y comparten fichero porque comparten todo lo que
 * importa: importe, forma de pago, cuenta y estado. La diferencia es el tiempo.
 *
 *   · Un PAGO es un hecho: el 14 de marzo se cobraron 120 €, y salió bien o
 *     salió mal. Lo que se necesita saber es cuánto, cuándo y si está cobrado.
 *   · Un COMPROMISO es una promesa: 20 € al mes desde enero. Lo que se necesita
 *     saber es cuánto y cada cuánto, si sigue activo y qué queda por pagar.
 *
 * DE DÓNDE VIENE ESTO. Los dos se pintaban con el renderizador genérico, y en
 * dinero eso duele especialmente:
 *
 *   1. El listado de Pagos NO pedía `payment_date`. Una lista de recibos sin
 *      fecha. Estaban el estado, el tipo, el método y hasta el número de
 *      cuenta, pero no el día en que te cobraron.
 *   2. El importe era una celda más, en medio de la fila y sin alinear. En una
 *      lista de recibos el importe es LA columna: ahora va a la derecha, en
 *      grande y con cifras tabulares, para poder recorrerla de un vistazo.
 *   3. La acción principal de cada fila era EDITAR un pago. Nadie edita un
 *      recibo desde el área privada, y ofrecerlo asusta.
 *   4. Un recibo devuelto se leía igual que uno cobrado: "Estado: Devuelto",
 *      texto negro sobre blanco, en la cuarta línea. Ahora es un chip rojo y,
 *      en la ficha, un aviso que dice qué hacer.
 *   5. Los títulos eran "Payments" y "Payment commitments", en inglés.
 *
 * RENDIMIENTO. No se añade ni una llamada al CRM. Los dos listados siguen
 * usando exactamente las consultas que ya hacían las páginas; lo único que
 * cambia es qué campos se piden (se quita `bank_account` del listado, que no
 * se enseña entero, y se añade la fecha, que sí) y cómo se pinta.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Pagar con tarjeta lo que se debe (plan 041): los bloques de Pagos lo usan.
require_once __DIR__ . '/stic-pay-card.php';

/* ==========================================================================
   1. PAGOS
   ========================================================================== */

/**
 * Campos del LISTADO de pagos. `payment_date` es la novedad: sin ella la lista
 * de recibos no decía cuándo te cobraron.
 */
function sticpa_payment_list_fields()
{
    return array(
        'id',
        'name',
        'amount',
        'payment_date',
        'status',
        'payment_type',
        'payment_method',
        // De qué es (plan 041): el concepto, y el compromiso del que cuelga
        // para saber si es un intento de tarjeta o algo ya sustituido.
        'banking_concept',
        'stic_paymebfe2itments_ida',
    );
}

/**
 * DE QUÉ ES UN PAGO, dicho como lo diría una familia: «Convivencia Inicial ·
 * Buñol» y, si es de un hijo, para quién. El nombre que pone el CRM es una
 * ristra técnica («Sandra Roy - Maria Lorenz Roy - COM | Convivencia… -
 * Domiciliación - 60 - 2026-10-16»): el evento es el trozo con «|», lo de
 * antes son personas y lo de después, forma de pago, importe y fecha.
 *
 * @param string $name     Nombre del pago o del compromiso.
 * @param string $concepto `banking_concept`, si lo hay: manda sobre el nombre.
 * @return array title, para ('' si no se sabe o es la misma persona).
 */
function sticpa_payment_concept($name, $concepto = '')
{
    $name = trim((string) $name);
    $concepto = trim((string) $concepto);
    // «Tarjeta (vía Redsys)» no es un concepto: es el medio (lo que queda
    // cuando el FWA no pone concepto). Se ignora y se busca el evento.
    if (function_exists('sticpa_concept_is_blank') && sticpa_concept_is_blank($concepto)) {
        $concepto = '';
    }
    $trozos = array_values(array_filter(array_map('trim', explode(' - ', $name)), 'strlen'));
    $evento = -1;
    foreach ($trozos as $i => $t) {
        if (strpos($t, '|') !== false) {
            $evento = $i;
            break;
        }
    }
    $para = '';
    if ($evento >= 1) {
        // «Tutor - Participante - Evento…»: el participante es el de justo antes.
        // «Participante - Evento» (los del área): el primero.
        $para = $trozos[$evento - 1];
    }
    if ($concepto !== '') {
        $title = $concepto;
    } elseif ($evento >= 0) {
        $title = $trozos[$evento];
    } else {
        // Sin evento: lo que no sea fecha, importe ni nombre de persona del
        // principio. Mejor corto que una ristra.
        $resto = array_filter(array_slice($trozos, 1), function ($t) {
            return !preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $t) && !preg_match('/^[\d.,]+( ?€)?$/', $t);
        });
        $title = !empty($resto) ? implode(' · ', $resto) : ($trozos[0] ?? __('Pago', 'sticpa'));
    }
    $quien = trim((string) ($_SESSION['scp_user_contact_name'] ?? ''));
    if ($para !== '' && $quien !== '' && stripos($quien, $para) !== false) {
        $para = '';
    }
    return array('title' => $title, 'para' => $para);
}

/**
 * ¿Es un intento de tarjeta (el compromiso que crea el formulario del TPV) y
 * no algo que se debe? Lo son los que llevan la marca del área o los de
 * tarjeta por la web. Mientras no se cobran no son una deuda: lo que se debe
 * sigue en su compromiso de siempre.
 */
function sticpa_commitment_is_card_attempt($pc)
{
    if (!$pc) {
        return false;
    }
    $desc = (string) ($pc['description'] ?? '');
    if (strpos($desc, '[pago:') !== false || strpos($desc, '[insc:') !== false) {
        return true;
    }
    return ($pc['payment_method'] ?? '') === 'card' && ($pc['channel'] ?? '') === 'web';
}

/** Campos que la pantalla de Pagos lee de los compromisos. */
function sticpa_payments_commitment_fields()
{
    return array('id', 'name', 'description', 'end_date', 'payment_method', 'payment_type', 'channel', 'banking_concept', 'date_entered');
}

/** Los compromisos de quien paga (filas del CRM). */
function sticpa_payments_commitment_rows($objSCP, $module, $link)
{
    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => $module,
        'module_id' => $_SESSION['scp_user_id'] ?? '',
        'link_field_name' => $link,
        'related_fields' => sticpa_payments_commitment_fields(),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
    ));
    return is_array($rows) ? $rows : array();
}

/**
 * LO QUE PAGOS NECESITA SABER Y LA API NO DA (02/10/2026). Tres arreglos, con
 * las mismas llamadas: los pagos de cada compromiso VIVO de quien paga (una por
 * compromiso, en paralelo si se puede).
 *
 *   1. **De qué compromiso es cada pago.** Por la API v4.1 el campo plano
 *      `stic_paymebfe2itments_ida` de un pago llega vacío, y sin él la lista
 *      no sabía qué era un intento de tarjeta ni qué estaba sustituido: el
 *      mismo pendiente salía dos veces. Se rellena desde el compromiso.
 *   2. **Los pagos sin persona.** El CRM genera el pago al guardar el
 *      compromiso, antes de que se le ponga la persona, y Pagos lista por
 *      persona: lo que se debía no salía. Se enseña y SE ARREGLA (se le pone la
 *      persona), salvo en los intentos de tarjeta.
 *   3. **Pagos sin nombre.** El FWA no pone concepto, y todos se llamaban
 *      «Tarjeta (vía Redsys)». Se le pone el evento de su inscripción, al
 *      compromiso y a sus pagos, una vez (lo que no tiene inscripción no se
 *      vuelve a mirar en 12 h).
 *
 * @param array  $commitmentRows Los compromisos de quien paga.
 * @param array  $payments       Los pagos de quien paga.
 * @param array  $fields         Campos de pago que pide la lista.
 * @param string $payerId        Quien paga (para arreglar la relación).
 * @param string $link           `stic_payments_contacts` o `stic_payments_accounts`.
 * @return array 'payments' (sin repetidos, con su compromiso) y 'map'
 *               (sticpa_payments_commitment_map() con los conceptos puestos).
 */
function sticpa_payments_complete($objSCP, $commitmentRows, $payments, $fields, $payerId, $link)
{
    $porId = array();
    foreach ((array) $payments as $p) {
        $pid = strtolower((string) ($p->id ?? ($p->name_value_list->id->value ?? '')));
        if ($pid !== '' && !isset($porId[$pid])) {
            $porId[$pid] = $p;
        }
    }
    $map = sticpa_payments_commitment_map($commitmentRows);
    $vivos = array();
    foreach ($map as $cid => $pc) {
        if (trim((string) ($pc['end_date'] ?? '')) === '') {
            $vivos[] = $cid;
        }
    }
    $params = function ($cid) use ($fields) {
        return array(
            'module_name' => 'stic_Payment_Commitments',
            'module_id' => $cid,
            'link_field_name' => 'stic_payments_stic_payment_commitments',
            'related_fields' => $fields,
            'related_module_link_name_to_fields_array' => array(),
            'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
        );
    };
    if (count($vivos) > 1 && function_exists('sticpa_pl_prime')) {
        sticpa_pl_prime($objSCP, function () use ($objSCP, $vivos, $params) {
            foreach ($vivos as $cid) {
                $objSCP->getRelatedElementsForLoggedUser($params($cid));
            }
        });
    }

    $deCompromiso = array();
    foreach ($vivos as $cid) {
        $rows = $objSCP->getRelatedElementsForLoggedUser($params($cid));
        $deCompromiso[$cid] = array();
        foreach ((is_array($rows) ? $rows : array()) as $row) {
            $pid = strtolower((string) ($row->id ?? ($row->name_value_list->id->value ?? '')));
            if ($pid === '') {
                continue;
            }
            if (isset($porId[$pid])) {
                $row = $porId[$pid];
            } elseif (sticpa_commitment_is_card_attempt($map[$cid])) {
                continue;
            } else {
                $porId[$pid] = $row;
                if ($payerId !== '') {
                    $objSCP->set_relationship('stic_Payments', (string) ($row->id ?? $pid), $link, array($payerId));
                }
            }
            if (isset($row->name_value_list) && trim((string) ($row->name_value_list->stic_paymebfe2itments_ida->value ?? '')) === '') {
                $row->name_value_list->stic_paymebfe2itments_ida = (object) array('value' => $cid);
            }
            $deCompromiso[$cid][] = $row;
        }
    }

    // El nombre: el evento de la inscripción, si el compromiso no dice nada.
    foreach ($vivos as $cid) {
        if (!function_exists('sticpa_concept_is_blank') || !sticpa_concept_is_blank($map[$cid]['banking_concept'] ?? '')) {
            continue;
        }
        $clave = 'sticpa_pc_sin_evento_' . md5($cid);
        if (function_exists('get_transient') && get_transient($clave)) {
            continue;
        }
        $concepto = sticpa_commitment_event_concept($objSCP, $cid);
        if ($concepto === '') {
            if (function_exists('set_transient')) {
                set_transient($clave, 1, 12 * HOUR_IN_SECONDS);
            }
            continue;
        }
        $objSCP->set_entry('stic_Payment_Commitments', array('id' => $cid, 'banking_concept' => $concepto));
        foreach ($deCompromiso[$cid] as $row) {
            $nvl = $row->name_value_list ?? null;
            if ($nvl && sticpa_concept_is_blank($nvl->banking_concept->value ?? '')) {
                $objSCP->set_entry('stic_Payments', array('id' => (string) ($row->id ?? ($nvl->id->value ?? '')), 'banking_concept' => $concepto));
                $nvl->banking_concept = (object) array('value' => $concepto);
            }
        }
        $map[$cid]['banking_concept'] = $concepto;
    }

    return array('payments' => array_values($porId), 'map' => $map);
}

/** compromiso => sus datos, para sticpa_payments_list_html(). */
function sticpa_payments_commitment_map($rows)
{
    $map = array();
    foreach ((array) $rows as $row) {
        $nvl = $row->name_value_list ?? null;
        $id = (string) ($row->id ?? ($nvl->id->value ?? ''));
        if ($id === '' || !$nvl) {
            continue;
        }
        $map[$id] = array();
        foreach (array('description', 'end_date', 'payment_method', 'channel', 'banking_concept') as $f) {
            $map[$id][$f] = isset($nvl->$f->value) ? (string) $nvl->$f->value : '';
        }
    }
    return $map;
}

/**
 * Pone en orden los intentos de tarjeta de quien paga antes de enseñar nada:
 * los cobrados sustituyen a lo que debían, y los abandonados se cierran
 * (sticpa_pay_claim() y sticpa_registration_claim_card_commitment()). Solo
 * mira los compromisos vivos que llevan marca, así que de normal no cuesta
 * ninguna llamada.
 *
 * @return bool Si ha cambiado algo (y hay que volver a leer).
 */
function sticpa_payments_settle($objSCP, $commitmentRows)
{
    if (!function_exists('sticpa_pay_claim')) {
        return false;
    }
    $cambio = false;
    foreach ((array) $commitmentRows as $row) {
        $nvl = $row->name_value_list ?? null;
        if (!$nvl || trim((string) ($nvl->end_date->value ?? '')) !== '') {
            continue;
        }
        $desc = (string) ($nvl->description->value ?? '');
        if (preg_match('/\[pagado:([0-9a-f-]{36})\]/', $desc, $m) && function_exists('sticpa_pay_substitute')) {
            // Una sustitución que se quedó a medias (01-02/10/2026: el pago
            // viejo no se encontraba). Se termina, y queda `[sustituido:]`.
            $cid = (string) ($row->id ?? ($nvl->id->value ?? ''));
            if ($cid !== '') {
                sticpa_pay_substitute($objSCP, $cid, $nvl, sticpa_commitment_payments($objSCP, $cid), $m[1], $m[0]);
                $cambio = true;
            }
        } elseif (preg_match('/\[pago:([0-9a-f-]{36}):[0-9a-f]{16}\]/', $desc, $m)) {
            $cambio = (sticpa_pay_claim($objSCP, $m[1]) !== '') || $cambio;
        } elseif (preg_match('/\[insc:([0-9a-f-]{36}):[0-9a-f]{16}\]/', $desc, $m) && function_exists('sticpa_registration_claim_card_commitment')) {
            // De una inscripción sin compromiso: si se cobró, se ata; si se
            // abandonó, se cierra (lo hace la misma función).
            $cambio = (sticpa_registration_claim_card_commitment($objSCP, $m[1]) > 0) || $cambio;
        }
    }
    return $cambio;
}

/** Campos de la FICHA de un pago: aquí sí interesan el porqué y el si falló. */
function sticpa_payment_detail_fields()
{
    return array_merge(sticpa_payment_list_fields(), array(
        'bank_account',
        'banking_concept',
        'transaction_code',
        'rejection_date',
        'sepa_rejected_reason',
        'c19_rejected_reason',
        'gateway_rejection_reason',
        'in_kind_description',
        'stic_payments_stic_payment_commitments_name',
        'stic_payments_stic_registrations_name',
    ));
}

/**
 * Enmascara un número de cuenta para enseñarlo.
 *
 * Nunca se pinta un IBAN entero en pantalla: es un dato bancario y esta página
 * se abre en el metro. Se deja el país y las cuatro últimas cifras, que es lo
 * único que hace falta para reconocer CUÁL de tus cuentas es.
 */
function sticpa_payment_mask_account($iban)
{
    $iban = preg_replace('/\s+/', '', (string) $iban);
    if ($iban === '') {
        return '';
    }
    if (strlen($iban) <= 8) {
        return $iban;
    }
    return substr($iban, 0, 4) . ' •••• ' . substr($iban, -4);
}

/**
 * Normaliza un pago del CRM.
 *
 * @param object $nvl name_value_list del registro.
 * @return array|null null si la fila no tiene ni importe ni nombre.
 */
function sticpa_payment_view_model($nvl)
{
    $val = function ($field) use ($nvl) {
        return isset($nvl->$field->value) ? trim((string) $nvl->$field->value) : '';
    };

    $name = $val('name');
    $amount = $val('amount');
    if ($name === '' && $amount === '') {
        return null;
    }

    $dateStr = $val('payment_date');
    return array(
        'id'         => $val('id'),
        'name'       => $name !== '' ? $name : __('Pago', 'sticpa'),
        'amount'     => $amount,
        'amount_txt' => $amount !== '' ? (string) formatValue($amount, 'currency') : '',
        'date_ts'    => $dateStr !== '' ? strtotime($dateStr) : null,
        'status'     => $val('status'),
        'type'       => $val('payment_type'),
        'method'     => $val('payment_method'),
        'nvl'        => $nvl,
    );
}

/**
 * LISTADO DE PAGOS en tres bloques (plan 041, 01/10/2026):
 *
 *   · «Pendiente de pagar»: lo que se debe, con «Pagar con tarjeta». Y lo que
 *     no se pudo cobrar (devuelto, rechazado), en rojo.
 *   · «Se cobrará por domiciliación»: lo que entra en la próxima remesa. No
 *     hay nada que hacer, y decirlo tranquiliza.
 *   · «Pagado»: el historial.
 *
 * Antes era una lista revuelta de recibos, con estados del CRM («No
 * remesado»). Lo que NO sale: los intentos de tarjeta sin terminar (no son una
 * deuda) y lo pendiente de un compromiso ya cerrado (sustituido o cancelado).
 *
 * @param array $rows        Pagos del CRM.
 * @param array $definition  Definición cacheada de stic_Payments.
 * @param array $commitments compromiso => array(description, end_date,
 *                           payment_method, channel, banking_concept).
 */
function sticpa_payments_list_html($rows, $definition = array(), $commitments = array())
{
    $bloques = array('pendiente' => array(), 'domiciliado' => array(), 'pagado' => array(), 'otro' => array());
    foreach ((array) $rows as $row) {
        $nvl = $row->name_value_list ?? null;
        if (!$nvl) {
            continue;
        }
        $pay = sticpa_payment_view_model($nvl);
        if (!$pay) {
            continue;
        }
        $pcId = isset($nvl->stic_paymebfe2itments_ida->value) ? trim((string) $nvl->stic_paymebfe2itments_ida->value) : '';
        $pc = $pcId !== '' ? ($commitments[$pcId] ?? null) : null;
        $pagado = $pay['status'] === 'paid' || sticpa_record_status_tone($pay['status']) === 'ok';
        $cerrado = $pc && trim((string) ($pc['end_date'] ?? '')) !== '';
        // Ni los intentos de tarjeta ni lo pendiente de algo ya cerrado.
        if (!$pagado && ($cerrado || sticpa_commitment_is_card_attempt($pc) || preg_match('/cancel|anulad/i', $pay['status']))) {
            continue;
        }
        $tone = sticpa_record_status_tone($pay['status']);
        if ($pagado) {
            $bloque = 'pagado';
        } elseif ($tone === 'danger' || sticpa_pay_is_payable($pay['status'], $pay['method'])) {
            $bloque = 'pendiente';
        } elseif (strpos($pay['status'], 'remit') !== false) {
            $bloque = 'domiciliado';
        } else {
            $bloque = 'otro';
        }
        $conceptoPc = (string) ($pc['banking_concept'] ?? '');
        $pay['concepto'] = sticpa_payment_concept($pay['name'],
            (function_exists('sticpa_concept_is_blank') ? !sticpa_concept_is_blank($conceptoPc) : $conceptoPc !== '')
                ? $conceptoPc : (string) ($nvl->banking_concept->value ?? ''));
        $bloques[$bloque][] = $pay;
    }

    if (empty(array_filter($bloques))) {
        return sticpa_record_empty_html(
            'card',
            __('No tienes ningún pago', 'sticpa'),
            __('Cuando te apuntes a algo que cueste dinero, aquí verás lo que tienes pendiente, lo que se cobrará y lo pagado.', 'sticpa')
        );
    }

    $titulos = array(
        'pendiente'   => __('Pendiente de pagar', 'sticpa'),
        'domiciliado' => __('Se cobrará por domiciliación', 'sticpa'),
        'pagado'      => __('Pagado', 'sticpa'),
        'otro'        => __('Otros', 'sticpa'),
    );
    $html = '';
    foreach ($bloques as $bloque => $pagos) {
        if (empty($pagos)) {
            continue;
        }
        // Lo que viene, por fecha; lo pagado, lo último arriba.
        usort($pagos, function ($a, $b) use ($bloque) {
            $cmp = ($a['date_ts'] ?? 0) <=> ($b['date_ts'] ?? 0);
            return $bloque === 'pagado' ? -$cmp : $cmp;
        });
        $cards = array();
        foreach ($pagos as $pay) {
            $cards[] = sticpa_payment_card($pay, $bloque, $definition);
        }
        $html .= "<h4 class='stic-rec-group-title'>" . esc_html($titulos[$bloque]) . "</h4>";
        $html .= sticpa_record_list_html($cards);
    }
    return $html;
}

/** La tarjeta de un pago en su bloque. */
function sticpa_payment_card($pay, $bloque, $definition = array())
{
    $tone = sticpa_record_status_tone($pay['status']);
    $method = sticpa_record_enum_label($definition, 'payment_method', $pay['method']);

    $lines = array();
    if ($pay['concepto']['para'] !== '') {
        /* translators: %s = nombre de la persona para quien es el pago */
        $lines[] = array('icon' => 'user', 'text' => sprintf(__('Para %s', 'sticpa'), $pay['concepto']['para']));
    }
    // La fecha ya la dice la cápsula de la izquierda: no se repite.
    if ($bloque === 'domiciliado') {
        $lines[] = array('icon' => 'bank', 'text' => __('Por domiciliación', 'sticpa'));
    } elseif ($bloque === 'pagado') {
        $lines[] = array('icon' => 'check', 'text' => $method);
    } elseif ($method !== '' && $pay['method'] !== 'card') {
        $lines[] = array('icon' => 'card', 'text' => $method);
    }

    // El chip, solo cuando dice algo que el bloque no dice ya: un devuelto.
    $chips = array();
    if ($tone === 'danger') {
        $label = sticpa_record_enum_label($definition, 'status', $pay['status']);
        $chips[] = array('label' => $label !== '' ? $label : __('No se pudo cobrar', 'sticpa'), 'tone' => 'danger');
    }

    $actions = array();
    if ($bloque === 'pendiente' && function_exists('sticpa_pay_url')) {
        $actions[] = array('label' => __('Pagar con tarjeta', 'sticpa'), 'url' => sticpa_pay_url($pay['id']), 'primary' => true);
    }

    return array(
        'url'     => '?internalpage=single_stic_payments&action=detail&id=' . rawurlencode($pay['id']),
        'ts'      => $pay['date_ts'],
        'icon'    => 'card',
        'name'    => $pay['concepto']['title'],
        'lines'   => $lines,
        'chips'   => $chips,
        'amount'  => $pay['amount_txt'],
        'actions' => $actions,
    );
}

/**
 * El motivo por el que un pago falló, dicho en el idioma del CRM.
 *
 * Hay tres campos distintos según por dónde falló (SEPA, cuaderno 19, pasarela)
 * y solo uno viene relleno. Se prueban en orden y se devuelve el primero que
 * diga algo; el de la pasarela es texto libre y va el último porque suele ser
 * jerga técnica.
 */
function sticpa_payment_rejection_reason($nvl, $definition)
{
    $val = function ($field) use ($nvl) {
        return isset($nvl->$field->value) ? trim((string) $nvl->$field->value) : '';
    };
    foreach (array('sepa_rejected_reason', 'c19_rejected_reason') as $field) {
        $label = sticpa_record_enum_label($definition, $field, $val($field));
        if ($label !== '') {
            return $label;
        }
    }
    return $val('gateway_rejection_reason');
}

/**
 * FICHA de un pago.
 *
 * @param array $pay        Modelo de sticpa_payment_view_model().
 * @param array $definition Definición cacheada del módulo.
 */
function sticpa_payment_detail_html($pay, $definition = array())
{
    $nvl = $pay['nvl'];
    $val = function ($field) use ($nvl) {
        return isset($nvl->$field->value) ? trim((string) $nvl->$field->value) : '';
    };

    $tone = sticpa_record_status_tone($pay['status']);
    $statusLabel = sticpa_record_enum_label($definition, 'status', $pay['status']);
    $method = sticpa_record_enum_label($definition, 'payment_method', $pay['method']);

    $chips = array();
    if ($statusLabel !== '') {
        $chips[] = array('label' => $statusLabel, 'tone' => $tone);
    }

    // --- Si algo falló, se dice lo primero y se dice qué hacer ---
    $notes = array();
    if ($tone === 'danger') {
        $motivo = sticpa_payment_rejection_reason($nvl, $definition);
        $rejTs = $val('rejection_date') !== '' ? strtotime($val('rejection_date')) : null;
        $texto = $motivo !== ''
            /* translators: %s = motivo de la devolución, tal y como lo dice el CRM */
            ? sprintf(__('Este recibo no se pudo cobrar: %s.', 'sticpa'), rtrim($motivo, '.'))
            : __('Este recibo no se pudo cobrar.', 'sticpa');
        if ($rejTs) {
            /* translators: %s = fecha de la devolución */
            $texto .= ' ' . sprintf(__('Ocurrió el %s.', 'sticpa'), sticpa_record_date_line($rejTs));
        }
        // Si se puede pagar desde aquí, el aviso no puede mandar a la
        // delegación con el botón de pagar justo debajo: dos instrucciones
        // contrarias (FAM-a12). Mismo texto que la ficha de la inscripción.
        $texto .= ' ' . ((!empty($pay['pagable']) && function_exists('sticpa_pay_url'))
            ? __('Puedes pagarlo con tarjeta o hablar con tu delegación.', 'sticpa')
            : __('Ponte en contacto con tu delegación para volver a intentarlo.', 'sticpa'));
        $notes[] = array('tone' => 'danger', 'text' => $texto);
    }

    // --- El importe es EL dato de la pantalla ---
    $headline = null;
    if ($pay['amount_txt'] !== '') {
        $headline = array(
            'label' => __('Importe', 'sticpa'),
            'text'  => $pay['amount_txt'],
            'sub'   => $method,
        );
    }

    // --- Datos clave ---
    // La fecha del pago NO se repite aquí: ya la lleva la cabecera.
    $facts = array();
    // «Servicios» es jerga del CRM y casi todo lo que se paga lo es: no dice
    // nada. «Cuota», «Donativo»… sí distinguen un recibo de otro.
    $type = strtolower((string) $pay['type']) === 'services' ? '' : sticpa_record_enum_label($definition, 'payment_type', $pay['type']);
    if ($type !== '') {
        $facts[] = array('icon' => 'tag', 'label' => __('Tipo', 'sticpa'), 'text' => $type);
    }
    $cuenta = sticpa_payment_mask_account($val('bank_account'));
    if ($cuenta !== '') {
        $facts[] = array('icon' => 'bank', 'label' => __('Cuenta', 'sticpa'), 'text' => $cuenta);
    }
    // El concepto ya es el título cuando lo hay: no se repite debajo.
    $concepto = sticpa_payment_concept($pay['name'], $val('banking_concept'));
    if ($val('banking_concept') !== '' && $val('banking_concept') !== $concepto['title']) {
        $facts[] = array('icon' => 'tag', 'label' => __('Concepto', 'sticpa'), 'text' => $val('banking_concept'));
    }
    if ($val('in_kind_description') !== '') {
        $facts[] = array('icon' => 'info', 'label' => __('Descripción', 'sticpa'), 'text' => $val('in_kind_description'));
    }
    // Para quién, si es de otra persona (un hijo). El «de qué» ya es el título.
    if ($concepto['para'] !== '') {
        $facts[] = array('icon' => 'user', 'label' => __('Para', 'sticpa'), 'text' => $concepto['para']);
    }
    // La referencia de la transacción, la última: es lo que hay que decir por
    // teléfono cuando algo va mal, y no antes. Con tarjeta es el número de
    // operación del TPV, y así se llama (a «Referencia» nadie le encuentra
    // sentido).
    if ($val('transaction_code') !== '') {
        $facts[] = array('icon' => 'tag',
            'label' => $val('payment_method') === 'card' ? __('Nº de operación', 'sticpa') : __('Referencia', 'sticpa'),
            'text'  => $val('transaction_code'));
    }

    // Lo que se debe se paga desde aquí; lo demás no tiene nada que hacer.
    // (Antes había un «Mis compromisos de pago»: esa palabra no es de las
    // familias, plan 041.)
    $actions = array();
    if (!empty($pay['pagable']) && function_exists('sticpa_pay_url')) {
        $actions[] = array('label' => sprintf(__('Pagar %s con tarjeta', 'sticpa'), $pay['amount_txt']), 'url' => sticpa_pay_url($pay['id']), 'primary' => true, 'icon' => 'go');
    }

    return sticpa_record_detail_html(array(
        'back'     => array('url' => '?internalpage=list_stic_payments', 'label' => __('Pagos', 'sticpa')),
        'title'    => $concepto['title'],
        'meta'     => array(array('icon' => 'calendar', 'text' => $pay['date_ts'] ? sticpa_record_date_line($pay['date_ts']) : '')),
        'chips'    => $chips,
        'headline' => $headline,
        'notes'    => $notes,
        'facts'    => $facts,
        'actions'  => $actions,
    ));
}

/* ==========================================================================
   2. COMPROMISOS DE PAGO
   ========================================================================== */

/** Campos del LISTADO de compromisos. */
function sticpa_commitment_list_fields()
{
    return array(
        'id',
        'name',
        'amount',
        'periodicity',
        'payment_method',
        'payment_type',
        'first_payment_date',
        'end_date',
        'active',
    );
}

/** Campos de la FICHA de un compromiso: lo pagado y lo que queda. */
function sticpa_commitment_detail_fields()
{
    return array_merge(sticpa_commitment_list_fields(), array(
        // Quién paga: el nombre para enseñarlo y el id para SABER si es otra
        // persona. En la ficha de un participante es LO que hay que decir,
        // porque el compromiso no es suyo: es de quien lo paga por él.
        'stic_payment_commitments_contacts_name',
        'stic_payment_commitments_contactscontacts_ida',
        'bank_account',
        'banking_concept',
        'signature_date',
        'annualized_fee',
        'paid_annualized_fee',
        'pending_annualized_fee',
        'card_expiry_date',
        'in_kind_donation',
        'destination',
    ));
}

/**
 * "20,00 € al mes": el importe y la periodicidad juntos, que es como se dice
 * en voz alta. Por separado no significan nada.
 *
 * La periodicidad se toma del CRM ya traducida; solo se le pone la preposición
 * delante. Si el CRM no sabe traducirla, se devuelve el importe solo antes que
 * enseñar la clave cruda.
 */
function sticpa_commitment_amount_line($amountTxt, $periodicityLabel)
{
    $amountTxt = trim((string) $amountTxt);
    $periodicityLabel = trim((string) $periodicityLabel);
    if ($amountTxt === '') {
        return $periodicityLabel;
    }
    if ($periodicityLabel === '') {
        return $amountTxt;
    }
    /* translators: 1 = importe formateado, 2 = periodicidad traducida por el CRM */
    return sprintf(__('%1$s · %2$s', 'sticpa'), $amountTxt, $periodicityLabel);
}

/**
 * Normaliza un compromiso del CRM.
 *
 * @return array|null null si la fila no tiene ni nombre ni importe.
 */
function sticpa_commitment_view_model($nvl)
{
    $val = function ($field) use ($nvl) {
        return isset($nvl->$field->value) ? trim((string) $nvl->$field->value) : '';
    };

    $name = $val('name');
    $amount = $val('amount');
    if ($name === '' && $amount === '') {
        return null;
    }

    $endStr = $val('end_date');
    $endTs = $endStr !== '' ? strtotime($endStr) : null;
    // `active` es un bool del CRM: llega como '1'/'0' o 'true'/'false'.
    $activeRaw = strtolower($val('active'));
    $active = in_array($activeRaw, array('1', 'true', 'yes', 'on'), true);
    // Un compromiso con fecha de fin pasada está terminado, diga lo que diga la
    // casilla: la fecha es un hecho y la casilla es una intención.
    $terminado = ($endTs !== null && $endTs < strtotime('today'));

    return array(
        'id'          => $val('id'),
        'name'        => $name !== '' ? $name : __('Compromiso de pago', 'sticpa'),
        'amount'      => $amount,
        'amount_txt'  => $amount !== '' ? (string) formatValue($amount, 'currency') : '',
        'periodicity' => $val('periodicity'),
        'method'      => $val('payment_method'),
        'type'        => $val('payment_type'),
        'start_ts'    => $val('first_payment_date') !== '' ? strtotime($val('first_payment_date')) : null,
        'end_ts'      => $endTs,
        'active'      => $active && !$terminado,
        'terminado'   => $terminado,
        'nvl'         => $nvl,
    );
}

/** LISTADO de compromisos como tarjetas. */
function sticpa_commitments_list_html($rows, $definition = array())
{
    $models = array();
    foreach ((array) $rows as $row) {
        $nvl = $row->name_value_list ?? null;
        if (!$nvl) {
            continue;
        }
        $model = sticpa_commitment_view_model($nvl);
        if ($model) {
            $models[] = $model;
        }
    }

    if (empty($models)) {
        return sticpa_record_empty_html(
            'repeat',
            __('No tienes ningún compromiso de pago', 'sticpa'),
            __('Un compromiso es una aportación que se repite: una cuota mensual, una anual. Aquí verías cuánto es, cada cuánto y qué queda por pagar.', 'sticpa')
        );
    }

    // Los activos primero: son los que siguen costando dinero cada mes.
    usort($models, function ($a, $b) {
        if ($a['active'] !== $b['active']) {
            return $a['active'] ? -1 : 1;
        }
        return ($b['start_ts'] ?? 0) <=> ($a['start_ts'] ?? 0);
    });

    $cards = array();
    foreach ($models as $com) {
        $periodicity = sticpa_record_enum_label($definition, 'periodicity', $com['periodicity']);

        // Solo la forma de pago. El "Desde el…" también estaba aquí y se
        // truncaba a "Desde el 1 de Janu…", que no informa de nada: la fecha
        // de inicio es material de ficha. Cómo se cobra, en cambio, es lo que
        // se comprueba de un vistazo.
        $lines = array();
        $method = sticpa_record_enum_label($definition, 'payment_method', $com['method']);
        if ($method !== '') {
            $lines[] = array('icon' => 'card', 'text' => $method);
        }

        $chips = array();
        if ($com['terminado']) {
            $chips[] = array('label' => __('Terminado', 'sticpa'), 'tone' => 'past');
        } elseif ($com['active']) {
            $chips[] = array('label' => __('Activo', 'sticpa'), 'tone' => 'ok');
        } else {
            $chips[] = array('label' => __('Sin actividad', 'sticpa'), 'tone' => '');
        }

        $cards[] = array(
            'url'         => '?internalpage=single_stic_payment_commitments&action=detail&id=' . rawurlencode($com['id']),
            'ts'          => $com['start_ts'],
            'icon'        => 'repeat',
            'name'        => $com['name'],
            'lines'       => $lines,
            'chips'       => $chips,
            'amount'      => $com['amount_txt'],
            'amount_note' => $periodicity,
            'is_past'     => !$com['active'],
        );
    }

    return sticpa_record_list_html($cards);
}

/**
 * ¿Este compromiso lo paga OTRA persona en nombre de quien estamos viendo?
 *
 * SinergiaCRM separa a propósito la persona PAGADORA (obligatoria, la del IBAN y
 * el mandato) de la persona DESTINATARIA (opcional, quien se beneficia), y su
 * documentación pone justo nuestro caso: «en el ámbito de la infancia, los
 * adultos realizan el pago de una actividad en la que participa un menor».
 * Rellenarlas hace que el compromiso salga EN LAS DOS FICHAS, y así se queda.
 *
 * ESTO SIRVE PARA AÑADIR UN DATO, NO PARA ESCONDER NINGUNO. Se probó a ocultar
 * el banco en la ficha del participante y el propietario lo tumbó con razón:
 * obligaba a mirar el dinero en la ficha del adulto y todo lo demás en la del
 * niño, que es un incordio a diario; y con padres separados el problema real no
 * es que uno vea la cuenta del otro —el IBAN ya sale enmascarado a cuatro
 * cifras para todo el mundo, como en cualquier otra pantalla— sino que
 * cualquiera de los dos pueda saber CÓMO se pagó algo y pagarlo si hace falta.
 *
 * Así que en la ficha del participante se ve el compromiso entero, se puede
 * aportar, y encima se dice quién lo paga, que es la pregunta que se hace uno.
 */
function sticpa_commitment_lo_paga_otra_persona($com)
{
    $audiencia = function_exists('sticpa_profile_audience') ? sticpa_profile_audience() : 'miembro';
    if ($audiencia !== 'participante') {
        return false;
    }
    // Si el propio participante es el titular, no hay nada que aclarar.
    $nvl = $com['nvl'];
    $titularId = isset($nvl->stic_payment_commitments_contactscontacts_ida->value)
        ? trim((string) $nvl->stic_payment_commitments_contactscontacts_ida->value)
        : '';
    return !($titularId !== '' && $titularId === ($_SESSION['scp_user_id'] ?? ''));
}

/** FICHA de un compromiso de pago. */
function sticpa_commitment_detail_html($com, $definition = array())
{
    $nvl = $com['nvl'];
    $val = function ($field) use ($nvl) {
        return isset($nvl->$field->value) ? trim((string) $nvl->$field->value) : '';
    };

    // Un compromiso que paga OTRA persona por quien estamos viendo: solo sirve
    // para AÑADIR "lo paga fulanita". No se esconde nada.
    $loPagaOtra = sticpa_commitment_lo_paga_otra_persona($com);
    $titular = $val('stic_payment_commitments_contacts_name');

    $periodicity = sticpa_record_enum_label($definition, 'periodicity', $com['periodicity']);
    $method = sticpa_record_enum_label($definition, 'payment_method', $com['method']);

    $chips = array();
    if ($com['terminado']) {
        $chips[] = array('label' => __('Terminado', 'sticpa'), 'tone' => 'past');
    } elseif ($com['active']) {
        $chips[] = array('label' => __('Activo', 'sticpa'), 'tone' => 'ok');
    } else {
        $chips[] = array('label' => __('Sin actividad', 'sticpa'), 'tone' => '');
    }

    // --- El importe con su periodicidad: así es como se dice en voz alta ---
    $headline = null;
    if ($com['amount_txt'] !== '') {
        $headline = array(
            'label' => __('Aportación', 'sticpa'),
            'text'  => $com['amount_txt'],
            'sub'   => $periodicity !== '' ? $periodicity : $method,
        );
    }

    // --- Avisos ---
    $notes = array();
    $pendiente = $val('pending_annualized_fee');
    if ($com['terminado'] && $com['end_ts']) {
        $notes[] = array(
            'tone' => 'info',
            /* translators: %s = fecha de fin */
            'text' => sprintf(__('Este compromiso terminó el %s.', 'sticpa'), sticpa_record_date_line($com['end_ts'])),
        );
    }

    // --- Datos clave ---
    $facts = array();
    if ($method !== '') {
        $facts[] = array('icon' => 'card', 'label' => __('Forma de pago', 'sticpa'), 'text' => $method);
    }
    // Quién lo paga, cuando no es quien estamos viendo. Es un dato MÁS, y de
    // los útiles: responde a "¿esto quién lo tiene domiciliado?".
    if ($loPagaOtra && $titular !== '') {
        $facts[] = array('icon' => 'user', 'label' => __('Lo paga', 'sticpa'), 'text' => $titular);
    }
    $cuenta = sticpa_payment_mask_account($val('bank_account'));
    if ($cuenta !== '') {
        $facts[] = array('icon' => 'bank', 'label' => __('Cuenta', 'sticpa'), 'text' => $cuenta);
    }
    if ($com['end_ts']) {
        $facts[] = array('icon' => 'calendar', 'label' => __('Hasta', 'sticpa'), 'text' => sticpa_record_date_line($com['end_ts']));
    }
    $destino = sticpa_record_enum_label($definition, 'destination', $val('destination'));
    if ($destino !== '') {
        $facts[] = array('icon' => 'tag', 'label' => __('Destino', 'sticpa'), 'text' => $destino);
    }
    if ($val('banking_concept') !== '') {
        $facts[] = array('icon' => 'tag', 'label' => __('Concepto', 'sticpa'), 'text' => $val('banking_concept'));
    }

    // --- El año en curso, como UNA historia ---
    // Antes eran tres cajas seguidas (total, aportado, pendiente) más un aviso
    // que repetía el pendiente: la misma cuenta cuatro veces en media pantalla.
    $progress = null;
    $total = $val('annualized_fee');
    if ($total !== '' && (float) $total > 0) {
        $aportado = $val('paid_annualized_fee');
        $note = '';
        if ($pendiente !== '' && (float) $pendiente > 0) {
            /* translators: %s = importe pendiente del año, ya formateado */
            $note = sprintf(__('Queda %s por aportar.', 'sticpa'), formatValue($pendiente, 'currency'));
        } elseif ((float) $aportado >= (float) $total) {
            $note = __('Ya está todo aportado. Gracias.', 'sticpa');
        }
        $progress = array(
            'label'     => __('Este año', 'sticpa'),
            'value'     => (float) $aportado,
            'max'       => (float) $total,
            'value_txt' => (string) formatValue($aportado !== '' ? $aportado : '0', 'currency'),
            'max_txt'   => (string) formatValue($total, 'currency'),
            'note'      => $note,
        );
    }

    // --- La acción ---
    // Ya NO hay «Hacer una aportación» (01/10/2026): creaba un donativo nuevo
    // que no saldaba nada —así salieron los compromisos de más del Foro— y
    // desde el área no se hacen aportaciones. Lo que se debe se paga desde
    // «Pagos» o desde la inscripción (plan 041).
    $actions = array(array('label' => __('Ver mis pagos', 'sticpa'), 'url' => '?internalpage=list_stic_payments', 'primary' => true));
    $ctaNote = '';

    return sticpa_record_detail_html(array(
        'back'     => array('url' => '?internalpage=list_stic_payments', 'label' => __('Pagos', 'sticpa')),
        'title'    => $com['name'],
        // La cabecera dice DESDE CUÁNDO, no cuánto: el cuánto va justo debajo
        // en grande, y decirlo dos veces seguidas es gastar media pantalla de
        // móvil en repetirse (design.md §5).
        'meta'     => array(array(
            'icon' => 'calendar',
            /* translators: %s = fecha del primer pago */
            'text' => $com['start_ts'] ? sprintf(__('Desde el %s', 'sticpa'), sticpa_record_date_line($com['start_ts'])) : '',
        )),
        'chips'    => $chips,
        'headline' => $headline,
        'notes'    => $notes,
        'progress' => $progress,
        'facts'    => $facts,
        'actions'  => $actions,
        'cta_note' => $ctaNote,
    ));
}
