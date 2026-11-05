<?php

/**
 * ============================================================================
 *  FORMA DE PAGO DE LA PERSONA (05/11/2026)
 * ----------------------------------------------------------------------------
 *  Cualquiera que entre al área puede ver y cambiar SU forma de pago y su
 *  IBAN. Lo que hay que tener en la cabeza antes de tocar nada de aquí:
 *
 *  1. **Es de quien paga, no del menor.** Un menor no tiene forma de pago
 *     propia: paga su familiar. La pantalla trabaja siempre sobre
 *     `sticpa_registration_payer()['pagador']` (el familiar si se está viendo
 *     a un menor; uno mismo si no), que sale de la SESIÓN, nunca de la
 *     petición. Nadie ve ni cambia la cuenta de otro familiar.
 *
 *  2. **Dónde vive.** Tres campos de la ficha de la persona (CAMPOS.md →
 *     «Pago y domiciliación»): `ajmcm_forma_pago_c`, `ajmcm_iban_c` y
 *     `ajmcm_iban_titular_c`. Pero lo que se COBRA es el pago, y cada pago lleva
 *     su propia copia de la cuenta, heredada de su compromiso al crearse. Por
 *     eso guardar la ficha no basta: hay que llevar la cuenta nueva a lo que
 *     aún no se ha cobrado.
 *
 *  3. **Solo se toca lo que no se ha pagado** (decidido el 05/11/2026). Un
 *     compromiso se actualiza únicamente si:
 *       · se cobra por domiciliación,
 *       · sigue vigente,
 *       · tiene algún pago «No remesado» (aún no ha salido al banco),
 *       · y NINGÚN otro pago suyo está en curso (remesado, impagado, a
 *         recobrar…). Lo cobrado y lo anulado es historia y no cuenta.
 *     Un compromiso con todos los pagos hechos no se toca. Uno con un pago ya
 *     en una remesa tampoco: ese recibo salió al banco con la cuenta vieja y
 *     cambiarla aquí falsearía lo que se mandó. Se cuenta, y tesorería lo ve.
 *
 *  4. **Cambiar de medio no es cambiar de cuenta.** Pasar de domiciliación a
 *     transferencia o Bizum cambia el ciclo de los pagos (un domiciliado nace
 *     «No remesado», uno por transferencia «Pendiente»). Eso NO se hace solo:
 *     se guarda la preferencia y se deja una nota en cada compromiso afectado
 *     para que tesorería lo revise antes de la próxima remesa.
 *
 *  5. **Sin código por correo** (decidido el 05/11/2026): la sesión y la firma
 *     del formulario (CSRF) son la protección. El IBAN nunca se pinta entero.
 *
 *  Pendiente (necesita un campo nuevo en Studio, ver docs/comunica/CAMPOS.md →
 *  «Quién paga por un menor»): elegir cuál de dos familiares paga. Hoy paga
 *  el que ha entrado, que es lo que ya hace la inscripción.
 * ============================================================================
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hoy, con la zona horaria de WordPress (Madrid). `date()` da UTC y entre las
 * 00:00 y las 02:00 un compromiso que acaba hoy se vería como vigente.
 */
function sticpa_fp_today($format = 'Y-m-d')
{
    return function_exists('wp_date') ? wp_date($format) : date($format);
}

/**
 * ¿El último recorrido de listas del CRM llegó hasta el final?
 *
 * ⚠️ `getRelatedElementsForLoggedUser` con `limit = 0` PAGINA y NUNCA devuelve
 * null: si una página falla devuelve lo que llevaba (a veces nada) y lo apunta
 * aquí. Fiarse solo del retorno daba por buena una lista vacía por un fallo de
 * red, y la pantalla decía «guardado» sin haber cambiado ninguna cuenta.
 */
function sticpa_fp_walk_ok()
{
    return !class_exists('SugarRestApiCall')
        || !method_exists('SugarRestApiCall', 'lastWalkComplete')
        || SugarRestApiCall::lastWalkComplete();
}

/** Los tres campos de la ficha (CAMPOS.md → «Pago y domiciliación»). */
function sticpa_fp_fields()
{
    return array('ajmcm_forma_pago_c', 'ajmcm_iban_c', 'ajmcm_iban_titular_c');
}

/** La clave de «Domiciliación» en `ajmcm_forma_pago_c` (la observada en los datos). */
function sticpa_fp_direct_debit_key()
{
    return 'cargo_cuenta';
}

/**
 * Las claves de `ajmcm_forma_pago_c` que el área sabe escribir: las TRES que hay
 * en datos reales (560 `cargo_cuenta`, 98 `transferencia`, 4 `bizum`, 05/11/2026).
 * La API acepta cualquier cadena en un desplegable sin avisar, así que no se
 * ofrece ninguna que no se haya visto. Etiquetas de reserva por si el CRM no
 * contesta; con respuesta manda la etiqueta del CRM.
 */
function sticpa_fp_known_methods()
{
    return array(
        'cargo_cuenta' => __('Domiciliación bancaria', 'sticpa'),
        'transferencia' => __('Transferencia', 'sticpa'),
        'bizum' => __('Bizum', 'sticpa'),
    );
}

/**
 * Países cuyo IBAN admite una remesa de domiciliación SEPA. Un IBAN de otro
 * sitio (p. ej. Brasil) pasa el módulo 97 pero el banco rechaza el recibo.
 */
function sticpa_fp_sepa_countries()
{
    return array(
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV',
        'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE',
        'IS', 'LI', 'NO', 'CH', 'GB', 'GI', 'GG', 'JE', 'IM', 'MC', 'SM', 'VA', 'AD',
    );
}

/** ¿Un IBAN válido y de un país SEPA? */
function sticpa_fp_iban_is_sepa($iban)
{
    $iban = sticpa_iban_normalize($iban);
    return sticpa_iban_is_valid($iban) && in_array(substr($iban, 0, 2), sticpa_fp_sepa_countries(), true);
}

/**
 * El nombre del titular, limpio: sin etiquetas ni entidades, sin espacios de
 * más (tampoco los duros, que `&nbsp;` colaba como un titular «lleno») y con
 * un máximo de 255. `strip_tags` se descartó: «Marta <3 Pérez» lo dejaba en
 * «Marta».
 */
function sticpa_fp_clean_holder($raw)
{
    $t = html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Primero las etiquetas de verdad («<b>x</b>» → «x»); lo que quede suelto
    // (un «<3») pierde solo el signo, no el resto del nombre.
    $t = (string) preg_replace('/<\/?[a-z][^>]*>/i', '', $t);
    $t = str_replace(array('<', '>'), '', $t);
    $t = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $t));
    return function_exists('mb_substr') ? mb_substr($t, 0, 255, 'UTF-8') : substr($t, 0, 255);
}

/** Lo que se ofrece en el desplegable: clave => etiqueta. */
function sticpa_fp_methods($objSCP)
{
    $known = sticpa_fp_known_methods();
    $crm = array();
    if ($objSCP !== null && function_exists('sticpa_cached_field_definition') && function_exists('sticpa_crm_enum_options')) {
        $def = sticpa_cached_field_definition($objSCP, 'Contacts', array('ajmcm_forma_pago_c'));
        $crm = sticpa_crm_enum_options($def, 'ajmcm_forma_pago_c');
    }
    if (empty($crm)) {
        return $known;
    }
    $out = array();
    foreach ($known as $key => $fallback) {
        if (isset($crm[$key])) {
            $out[$key] = trim((string) $crm[$key]) !== '' ? $crm[$key] : $fallback;
        }
    }
    return $out;
}

/** Un campo de una fila del CRM, venga plana o dentro de `name_value_list`. */
function sticpa_fp_val($row, $field)
{
    if (isset($row->name_value_list->$field->value)) {
        return trim((string) $row->name_value_list->$field->value);
    }
    if (isset($row->$field)) {
        $v = $row->$field;
        return trim((string) (is_object($v) ? ($v->value ?? '') : $v));
    }
    return '';
}

/**
 * La forma de pago guardada en la ficha de una persona.
 *
 * @return array ok, forma, iban (sin espacios), titular, nombre.
 */
function sticpa_fp_read($objSCP, $contactId)
{
    $vacio = array('ok' => false, 'forma' => '', 'iban' => '', 'titular' => '', 'nombre' => '');
    if ($objSCP === null || trim((string) $contactId) === '') {
        return $vacio;
    }
    $res = $objSCP->getRecordDetail((string) $contactId, 'Contacts',
        array_merge(array('id', 'first_name', 'last_name'), sticpa_fp_fields()));
    $nvl = $res->entry_list[0]->name_value_list ?? null;
    if (!$nvl) {
        return $vacio;
    }
    $v = function ($f) use ($nvl) {
        return isset($nvl->$f->value) ? trim((string) $nvl->$f->value) : '';
    };
    return array(
        'ok' => true,
        'forma' => $v('ajmcm_forma_pago_c'),
        'iban' => sticpa_iban_normalize($v('ajmcm_iban_c')),
        'titular' => $v('ajmcm_iban_titular_c'),
        'nombre' => trim($v('first_name') . ' ' . $v('last_name')),
    );
}

/** El IBAN en grupos de cuatro y con el medio tapado: «ES91 •••• 8644». */
function sticpa_fp_mask($iban)
{
    return function_exists('sticpa_payment_mask_account') ? sticpa_payment_mask_account($iban) : '';
}

/**
 * La forma de pago en una línea, para enseñarla: «Domiciliación bancaria ·
 * ES91 •••• 8644 · titular: Marta Pérez». La cuenta, siempre tapada. '' si no
 * hay nada guardado (o es una clave que ya no se ofrece).
 *
 * @return string Texto plano (quien lo pinte, lo escapa).
 */
function sticpa_fp_summary($ficha, $methods)
{
    $forma = (string) ($ficha['forma'] ?? '');
    if ($forma === '' || !isset($methods[$forma])) {
        return '';
    }
    $partes = array((string) $methods[$forma]);
    if ($forma === sticpa_fp_direct_debit_key() && ($ficha['iban'] ?? '') !== '') {
        $partes[] = sticpa_fp_mask($ficha['iban']);
        if (($ficha['titular'] ?? '') !== '') {
            /* translators: %s = nombre del titular de la cuenta */
            $partes[] = sprintf(__('titular: %s', 'sticpa'), $ficha['titular']);
        }
    }
    return implode(' · ', $partes);
}

/**
 * La tarjeta que sale en «Datos de Lucía»: dice cuál es la forma de pago con la
 * que se cobran las cosas de Lucía —la de quien ha entrado, que es quien paga—
 * y lleva a cambiarla. Una llamada al CRM (la ficha de quien paga).
 */
function sticpa_fp_card_html($objSCP)
{
    $payerId = function_exists('sticpa_registration_payer') ? (string) sticpa_registration_payer()['pagador'] : '';
    if ($payerId === '') {
        return '';
    }
    $ficha = sticpa_fp_read($objSCP, $payerId);
    if (!$ficha['ok']) {
        // Sin poder leerla no se dice «todavía no has indicado la tuya»: sería mentir.
        return '';
    }
    $resumen = sticpa_fp_summary($ficha, sticpa_fp_methods($objSCP));
    $url = '?internalpage=single_stic_forma_pago';
    $texto = $resumen !== ''
        ? sprintf(__('<strong>Forma de pago:</strong> la tuya, %s.', 'sticpa'), esc_html($resumen))
        : __('<strong>Forma de pago:</strong> todavía no has indicado la tuya.', 'sticpa');
    return $texto . " <a class='stic-fp-link' href='" . esc_url($url) . "'>"
        . esc_html($resumen !== '' ? __('Cambiar', 'sticpa') : __('Indicarla', 'sticpa')) . "</a>";
}

/**
 * Comprueba lo que manda el formulario contra lo que ya hay guardado.
 *
 * El IBAN no se pinta entero en la pantalla, así que el campo llega vacío si la
 * persona no quiere cambiarlo: en ese caso se queda el que había. Solo se
 * propaga a los compromisos cuando la persona ESCRIBIÓ un IBAN (`propagar`):
 * guardar solo el titular no debe pisar las cuentas de los compromisos con la
 * de la ficha, que puede ser una vieja.
 *
 * @param array $input  Los campos del formulario (los tres de sticpa_fp_fields()).
 * @param array $actual Lo guardado (sticpa_fp_read()).
 * @param array $methods Lo que se ofrece (sticpa_fp_methods()).
 * @return array ok, error ('' | forma | iban | titular), clean (forma, iban,
 *               titular), propagar (bool).
 */
function sticpa_fp_validate($input, $actual, $methods)
{
    $forma = trim((string) ($input['ajmcm_forma_pago_c'] ?? ''));
    $clean = array('forma' => $forma, 'iban' => (string) ($actual['iban'] ?? ''), 'titular' => (string) ($actual['titular'] ?? ''));
    $fallo = function ($error) use ($clean) {
        return array('ok' => false, 'error' => $error, 'clean' => $clean, 'propagar' => false);
    };

    if ($forma === '' || !isset($methods[$forma])) {
        return $fallo('forma');
    }

    $propagar = false;
    if ($forma === sticpa_fp_direct_debit_key()) {
        $escrito = sticpa_iban_normalize($input['ajmcm_iban_c'] ?? '');
        $iban = $escrito !== '' ? $escrito : $clean['iban'];
        if ($iban === '' || !sticpa_fp_iban_is_sepa($iban)) {
            return $fallo('iban');
        }
        $titular = sticpa_fp_clean_holder($input['ajmcm_iban_titular_c'] ?? '');
        if ($titular === '') {
            $titular = sticpa_fp_clean_holder($clean['titular']);
        }
        if ($titular === '') {
            return $fallo('titular');
        }
        $clean['iban'] = $iban;
        $clean['titular'] = $titular;
        $propagar = $escrito !== '';
    }
    // Con otro medio, el IBAN y el titular que hubiera se quedan como estaban:
    // un dato bancario no se pierde por cambiar de idea.
    return array('ok' => true, 'error' => '', 'clean' => $clean, 'propagar' => $propagar);
}

/** Guarda la ficha. false si el CRM no confirma. */
function sticpa_fp_save($objSCP, $contactId, $clean)
{
    $id = $objSCP->set_entry('Contacts', array(
        'id' => (string) $contactId,
        'ajmcm_forma_pago_c' => (string) $clean['forma'],
        'ajmcm_iban_c' => (string) $clean['iban'],
        'ajmcm_iban_titular_c' => (string) $clean['titular'],
    ));
    return (bool) $id;
}

/** ¿Un valor de fecha del CRM está vacío (o es el «cero» de MySQL)? */
function sticpa_fp_no_date($d)
{
    $d = trim((string) $d);
    return $d === '' || strpos($d, '0000') === 0;
}

/**
 * ¿Se le puede cambiar la cuenta a este compromiso? Ver el punto 3 de la
 * cabecera. Función PURA: recibe las filas ya leídas.
 *
 * @param array  $c      'method', 'end_date' y, si se conoce, 'active' del compromiso.
 * @param array  $pays   Lista de array('id', 'status', 'method').
 * @param string $today  'Y-m-d'.
 * @param array  $historia Estados que son historia (no cuentan como «en curso»).
 * @return array estado (tocable | sin_pendientes | en_curso | no_domiciliado |
 *               terminado) y pendientes (ids de los pagos «No remesado» que se
 *               pueden tocar).
 */
function sticpa_fp_classify($c, $pays, $today, $historia = array('paid', 'cancelled'))
{
    $resultado = function ($estado, $pend = array()) {
        return array('estado' => $estado, 'pendientes' => $pend);
    };
    if (strtolower(trim((string) ($c['method'] ?? ''))) !== 'direct_debit') {
        return $resultado('no_domiciliado');
    }
    $fin = trim((string) ($c['end_date'] ?? ''));
    if (!sticpa_fp_no_date($fin) && substr($fin, 0, 10) <= $today) {
        return $resultado('terminado');
    }
    // El CRM y el área (`sticpa_pay_close_commitment`) cierran un compromiso con
    // fecha de fin y `active = 0`; uno con `active = 0` explícito y sin fecha
    // está apagado a mano. Ante la duda, no se toca.
    if (trim((string) ($c['active'] ?? '')) === '0') {
        return $resultado('terminado');
    }
    $pendientes = array();
    foreach ((array) $pays as $p) {
        $status = strtolower(trim((string) ($p['status'] ?? '')));
        if ($status === 'not_remitted') {
            // Un «No remesado» que no es una domiciliación (el recurrente por
            // tarjeta también nace así) no es de este cambio. Y como no se sabe
            // qué es, se trata como algo en curso.
            if (strtolower(trim((string) ($p['method'] ?? ''))) !== 'direct_debit') {
                return $resultado('en_curso');
            }
            $pendientes[] = (string) ($p['id'] ?? '');
            continue;
        }
        if (in_array($status, array_map('strtolower', (array) $historia), true)) {
            continue;
        }
        // Remesado, impagado, a recobrar… o un estado que no conocemos: no se
        // toca nada de este compromiso.
        return $resultado('en_curso');
    }
    $pendientes = array_values(array_filter($pendientes, function ($id) {
        return $id !== '';
    }));
    return $pendientes ? $resultado('tocable', $pendientes) : $resultado('sin_pendientes');
}

/**
 * Los compromisos que PAGA esta persona (ella es la persona pagadora), con lo
 * que hace falta para decidir. null si el CRM no contesta.
 */
function sticpa_fp_payer_commitments($objSCP, $contactId)
{
    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'Contacts',
        'module_id' => (string) $contactId,
        'link_field_name' => 'stic_payment_commitments_contacts',
        'related_fields' => array('id', 'name', 'description', 'payment_method', 'bank_account', 'end_date', 'active'),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
    ));
    // Ni un retorno que no es lista ni un recorrido a medias valen: con la
    // mitad de los compromisos se «guardaría» sin cambiar los que faltan.
    return (is_array($rows) && sticpa_fp_walk_ok()) ? $rows : null;
}

/**
 * Los pagos de un compromiso, con su cuenta. null si el recorrido va a medias:
 * con una lista parcial no se sabe si hay un recibo ya remesado que no se ve.
 */
function sticpa_fp_commitment_payments($objSCP, $commitmentId)
{
    $rows = $objSCP->getRelatedElementsForLoggedUser(array(
        'module_name' => 'stic_Payment_Commitments',
        'module_id' => (string) $commitmentId,
        'link_field_name' => 'stic_payments_stic_payment_commitments',
        'related_fields' => array('id', 'status', 'payment_method', 'bank_account'),
        'related_module_link_name_to_fields_array' => array(),
        'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
    ));
    return (is_array($rows) && sticpa_fp_walk_ok()) ? $rows : null;
}

/**
 * Los compromisos de quien paga, ya clasificados.
 *
 * @return array|null Lista de array(id, description, bank_account, pay_accounts,
 *                    class) o null si el CRM no contesta del todo (entonces no
 *                    se toca nada). `class.estado` puede ser 'ilegible': no se
 *                    pudieron leer todos sus pagos y no se toca.
 */
function sticpa_fp_scan($objSCP, $contactId)
{
    $rows = sticpa_fp_payer_commitments($objSCP, $contactId);
    if ($rows === null) {
        return null;
    }
    $historia = array('paid', 'cancelled');
    if (function_exists('sticpa_pay_cancel_status')) {
        $anulado = sticpa_pay_cancel_status($objSCP);
        if ($anulado !== '') {
            $historia[] = $anulado;
        }
    }
    $hoy = sticpa_fp_today('Y-m-d');
    $out = array();
    foreach ($rows as $row) {
        $cid = sticpa_fp_val($row, 'id');
        if ($cid === '') {
            continue;
        }
        $com = array(
            'method' => sticpa_fp_val($row, 'payment_method'),
            'end_date' => sticpa_fp_val($row, 'end_date'),
            'active' => sticpa_fp_val($row, 'active'),
        );
        $pays = array();
        $cuentas = array();
        $clase = sticpa_fp_classify($com, array(), $hoy, $historia);
        // Solo se piden los pagos si puede ser tocable: un compromiso que no es
        // una domiciliación vigente no necesita ni mirarse.
        if ($clase['estado'] === 'sin_pendientes') {
            $filas = sticpa_fp_commitment_payments($objSCP, $cid);
            if ($filas === null) {
                $clase = array('estado' => 'ilegible', 'pendientes' => array());
            } else {
                foreach ($filas as $p) {
                    $pid = sticpa_fp_val($p, 'id');
                    $pays[] = array(
                        'id' => $pid,
                        'status' => sticpa_fp_val($p, 'status'),
                        'method' => sticpa_fp_val($p, 'payment_method'),
                    );
                    $cuentas[$pid] = sticpa_iban_normalize(sticpa_fp_val($p, 'bank_account'));
                }
                $clase = sticpa_fp_classify($com, $pays, $hoy, $historia);
            }
        }
        $out[] = array(
            'id' => $cid,
            'description' => sticpa_fp_val($row, 'description'),
            'bank_account' => sticpa_iban_normalize(sticpa_fp_val($row, 'bank_account')),
            'pay_accounts' => $cuentas,
            'class' => $clase,
        );
    }
    return $out;
}

/** Añade una línea a la descripción de un compromiso, sin repetir la misma. */
function sticpa_fp_append_note($description, $nota)
{
    $description = (string) $description;
    if (strpos($description, $nota) !== false) {
        return $description;
    }
    return $description . ($description !== '' ? "\n" : '') . $nota;
}

/**
 * Lleva la cuenta nueva a lo que AÚN NO SE HA COBRADO.
 *
 * Solo se escribe lo que cambia: si el compromiso o un pago ya tienen esa
 * cuenta, no se reescribe ni se deja una nota que no dice nada.
 *
 * @return array compromisos y pagos (los que se han escrito), en_curso
 *               (compromisos que se dejaron como estaban por tener un recibo
 *               en curso), errores (incluye los que no se pudieron leer
 *               enteros), sin_respuesta.
 */
function sticpa_fp_propagate($objSCP, $contactId, $iban)
{
    $res = array('compromisos' => 0, 'pagos' => 0, 'en_curso' => 0, 'errores' => 0, 'sin_respuesta' => false);
    $scan = sticpa_fp_scan($objSCP, $contactId);
    if ($scan === null) {
        $res['sin_respuesta'] = true;
        return $res;
    }
    foreach ($scan as $c) {
        $estado = $c['class']['estado'];
        if ($estado === 'en_curso') {
            $res['en_curso']++;
            continue;
        }
        if ($estado === 'ilegible') {
            // No se pudo comprobar que no haya un recibo ya remesado: no se
            // toca, y se dice (la pantalla avisa de que falta algo).
            $res['errores']++;
            continue;
        }
        if ($estado !== 'tocable') {
            continue;
        }
        if ($c['bank_account'] !== $iban) {
            // Sin enseñar ninguna cuenta entera: ni la vieja ni la nueva.
            $nota = sprintf('[Área privada %s] Cuenta de cobro cambiada por la propia persona (de %s a %s).',
                sticpa_fp_today('d/m/Y'), sticpa_fp_mask($c['bank_account']) ?: 'sin cuenta', sticpa_fp_mask($iban));
            $ok = $objSCP->set_entry('stic_Payment_Commitments', array(
                'id' => $c['id'],
                'bank_account' => $iban,
                'description' => sticpa_fp_append_note($c['description'], $nota),
            ));
            if (!$ok) {
                // Si el compromiso no se actualiza, sus pagos tampoco: o todo o nada.
                $res['errores']++;
                continue;
            }
            $res['compromisos']++;
        }
        foreach ($c['class']['pendientes'] as $pid) {
            if (($c['pay_accounts'][$pid] ?? '') === $iban) {
                continue;
            }
            if ($objSCP->set_entry('stic_Payments', array('id' => $pid, 'bank_account' => $iban))) {
                $res['pagos']++;
            } else {
                $res['errores']++;
            }
        }
    }
    return $res;
}

/**
 * Cambió de domiciliación a otro medio: NO se cambia nada, se deja una nota en
 * cada compromiso domiciliado con pagos aún sin remesar para que tesorería lo
 * revise antes de la próxima remesa. Ver el punto 4 de la cabecera.
 *
 * @return int Cuántos compromisos se han marcado.
 */
function sticpa_fp_flag_method_change($objSCP, $contactId, $etiquetaMedio)
{
    $scan = sticpa_fp_scan($objSCP, $contactId);
    if ($scan === null) {
        return 0;
    }
    $marcados = 0;
    foreach ($scan as $c) {
        // Aquí también cuentan los que tienen un pago en curso: lo que importa
        // es que tesorería sepa que esta persona ya no quiere domiciliar.
        if (!in_array($c['class']['estado'], array('tocable', 'en_curso', 'ilegible'), true)) {
            continue;
        }
        // Una sola vez por medio, aunque guarde otro día: la fecha de la primera
        // vale, y no se llena la descripción de líneas iguales.
        if (strpos($c['description'], 'ha pedido pasar a «' . $etiquetaMedio . '»') !== false) {
            continue;
        }
        $nota = sprintf('[Área privada %s] La persona ha pedido pasar a «%s». Revisar este compromiso antes de la próxima remesa.',
            sticpa_fp_today('d/m/Y'), $etiquetaMedio);
        $desc = sticpa_fp_append_note($c['description'], $nota);
        if ($objSCP->set_entry('stic_Payment_Commitments', array('id' => $c['id'], 'description' => $desc))) {
            $marcados++;
        }
    }
    return $marcados;
}
