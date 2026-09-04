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
    );
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
 * LISTADO de pagos como tarjetas.
 *
 * @param array $rows       Registros del CRM.
 * @param array $definition Definición cacheada del módulo (etiquetas de enums).
 */
function sticpa_payments_list_html($rows, $definition = array())
{
    $models = array();
    foreach ((array) $rows as $row) {
        $nvl = $row->name_value_list ?? null;
        if (!$nvl) {
            continue;
        }
        $model = sticpa_payment_view_model($nvl);
        if ($model) {
            $models[] = $model;
        }
    }

    if (empty($models)) {
        return sticpa_record_empty_html(
            'card',
            __('Todavía no hay ningún pago', 'sticpa'),
            __('Aquí aparecerán los recibos con su importe y su estado, en cuanto se emita el primero.', 'sticpa')
        );
    }

    // Lo más reciente arriba. En dinero, lo que se busca es el último recibo:
    // "¿me han cobrado ya el de este mes?".
    usort($models, function ($a, $b) {
        return ($b['date_ts'] ?? 0) <=> ($a['date_ts'] ?? 0);
    });

    $cards = array();
    foreach ($models as $pay) {
        $tone = sticpa_record_status_tone($pay['status']);

        // UNA sola línea bajo el título, y es la forma de pago. El tipo
        // ("Cuota", "Donación") estaba también aquí y se cayó al mirar la
        // captura: con el importe ocupando la derecha, al título le quedan
        // ~180px a 375px, y dos líneas más un chip lo partían en tres. Lo que
        // se recorre en una lista de recibos es importe, fecha y estado; el
        // tipo es material de ficha.
        $lines = array();
        $method = sticpa_record_enum_label($definition, 'payment_method', $pay['method']);
        if ($method !== '') {
            $lines[] = array('icon' => 'card', 'text' => $method);
        }

        $chips = array();
        $statusLabel = sticpa_record_enum_label($definition, 'status', $pay['status']);
        if ($statusLabel !== '') {
            $chips[] = array('label' => $statusLabel, 'tone' => $tone);
        }

        $cards[] = array(
            'url'    => '?internalpage=single_stic_payments&action=detail&id=' . rawurlencode($pay['id']),
            'ts'     => $pay['date_ts'],
            'icon'   => 'card',
            'name'   => $pay['name'],
            'lines'  => $lines,
            'chips'  => $chips,
            'amount' => $pay['amount_txt'],
            // Un recibo devuelto se apaga: ya no cuenta como dinero cobrado.
            'is_past' => $tone === 'danger',
        );
    }

    return sticpa_record_list_html($cards);
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
        $texto .= ' ' . __('Ponte en contacto con tu delegación para volver a intentarlo.', 'sticpa');
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
    $type = sticpa_record_enum_label($definition, 'payment_type', $pay['type']);
    if ($type !== '') {
        $facts[] = array('icon' => 'tag', 'label' => __('Tipo', 'sticpa'), 'text' => $type);
    }
    $cuenta = sticpa_payment_mask_account($val('bank_account'));
    if ($cuenta !== '') {
        $facts[] = array('icon' => 'bank', 'label' => __('Cuenta', 'sticpa'), 'text' => $cuenta);
    }
    if ($val('banking_concept') !== '') {
        $facts[] = array('icon' => 'tag', 'label' => __('Concepto', 'sticpa'), 'text' => $val('banking_concept'));
    }
    if ($val('in_kind_description') !== '') {
        $facts[] = array('icon' => 'info', 'label' => __('Descripción', 'sticpa'), 'text' => $val('in_kind_description'));
    }
    // De qué venía el pago: el compromiso o la inscripción que lo originó.
    if ($val('stic_payments_stic_registrations_name') !== '') {
        $facts[] = array('icon' => 'check', 'label' => __('Inscripción', 'sticpa'), 'text' => $val('stic_payments_stic_registrations_name'));
    }
    if ($val('stic_payments_stic_payment_commitments_name') !== '') {
        $facts[] = array('icon' => 'repeat', 'label' => __('Compromiso de pago', 'sticpa'), 'text' => $val('stic_payments_stic_payment_commitments_name'));
    }
    // La referencia de la transacción, la última: es lo que hay que decir por
    // teléfono cuando algo va mal, y no antes.
    if ($val('transaction_code') !== '') {
        $facts[] = array('icon' => 'tag', 'label' => __('Referencia', 'sticpa'), 'text' => $val('transaction_code'));
    }

    return sticpa_record_detail_html(array(
        'back'     => array('url' => '?internalpage=list_stic_payments', 'label' => __('Mis pagos', 'sticpa')),
        'title'    => $pay['name'],
        'meta'     => array(array('icon' => 'calendar', 'text' => $pay['date_ts'] ? sticpa_record_date_line($pay['date_ts']) : '')),
        'chips'    => $chips,
        'headline' => $headline,
        'notes'    => $notes,
        'facts'    => $facts,
        'actions'  => array(
            array('label' => __('Mis compromisos de pago', 'sticpa'), 'url' => '?internalpage=list_stic_payment_commitments'),
        ),
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
 * URL para hacer un pago suelto, con el importe ya puesto.
 *
 * OJO, Y ESTO IMPORTA: el formulario de pago del área crea un compromiso NUEVO
 * de tipo puntual (es un formulario web de SinergiaCRM, `webFormClass=Donation`)
 * y NO liquida el compromiso desde el que se le llama — el formulario web no
 * tiene por dónde recibir un compromiso existente. Así que esto es "hacer una
 * aportación por este importe", no "pagar este recibo", y la ficha lo dice con
 * esas palabras. Enlazar de verdad un pago con su compromiso necesita trabajo
 * en el CRM (un punto de entrada que acepte el id del compromiso).
 */
function sticpa_commitment_pay_url($amount = '')
{
    $url = '?internalpage=single_stic_payment_form';
    $amount = trim((string) $amount);
    if ($amount !== '' && (float) $amount > 0) {
        $url .= '&amount=' . rawurlencode(number_format((float) $amount, 2, '.', ''));
    }
    return $url;
}

/** FICHA de un compromiso de pago. */
function sticpa_commitment_detail_html($com, $definition = array())
{
    $nvl = $com['nvl'];
    $val = function ($field) use ($nvl) {
        return isset($nvl->$field->value) ? trim((string) $nvl->$field->value) : '';
    };

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
    // Solo tiene sentido ofrecer aportar en un compromiso VIVO. En uno
    // terminado, el botón sería una invitación a pagar algo que ya no existe.
    $actions = array();
    $ctaNote = '';
    if ($com['active']) {
        $importePago = ($pendiente !== '' && (float) $pendiente > 0) ? $pendiente : $com['amount'];
        $actions[] = array(
            'label'   => __('Hacer una aportación', 'sticpa'),
            'url'     => sticpa_commitment_pay_url($importePago),
            'primary' => true,
            'icon'    => 'go',
        );
        // Se dice lo que es. El formulario de pago registra una aportación
        // puntual; no liquida este compromiso por su cuenta.
        $ctaNote = __('Se registra como una aportación puntual. Tu delegación la asocia a este compromiso.', 'sticpa');
    }
    $actions[] = array('label' => __('Ver mis pagos', 'sticpa'), 'url' => '?internalpage=list_stic_payments');

    return sticpa_record_detail_html(array(
        'back'     => array('url' => '?internalpage=list_stic_payment_commitments', 'label' => __('Mis compromisos', 'sticpa')),
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
