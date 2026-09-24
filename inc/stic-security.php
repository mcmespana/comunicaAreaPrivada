<?php

/**
 * ============================================================================
 *  SEGURIDAD DE LOS HANDLERS — planes 001 a 005 (plans/README.md)
 * ----------------------------------------------------------------------------
 *  Lo que hay que tener en la cabeza antes de tocar nada de aquí:
 *
 *  1. Quien usa el área privada es un CONTACTO DEL CRM, no un usuario de
 *     WordPress. Para WordPress todas las visitas son anónimas, así que cada
 *     handler se registra también como `admin_post_nopriv_*`. La ÚNICA
 *     autenticación que existe es que el handler mire `$_SESSION['scp_user_id']`.
 *
 *  2. El plugin habla con el CRM con UN USUARIO TÉCNICO que puede leer y
 *     escribir cualquier registro. Los grupos de seguridad del CRM no filtran
 *     nada de lo que pasa por aquí (CLAUDE.md). Lo que no compruebe este
 *     archivo, no lo comprueba nadie.
 *
 *  Por eso las tres preguntas que se hace cada handler que escribe o sirve un
 *  fichero son siempre las mismas, y en este orden:
 *
 *    · ¿Hay sesión?                      → sticpa_require_session()
 *    · ¿Ese registro es tuyo?            → sticpa_user_owns_record()
 *    · ¿Ese campo lo enseñaba el form?   → sticpa_request_to_module_data()
 *
 *  Y una cuarta al terminar: ¿a dónde te mando? A una ruta de ESTE sitio
 *  (sticpa_return_url()), nunca a la que diga la petición.
 * ============================================================================
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------------------------------
 *  1. SESIÓN (plan 001)
 * ------------------------------------------------------------------------- */

function sticpa_has_session()
{
    return !empty($_SESSION['scp_user_id']);
}

/**
 * Sin sesión, fuera. Va LO PRIMERO en cada handler que escribe en el CRM o
 * sirve algo privado: antes de leer el request, antes de hablar con el CRM.
 */
function sticpa_require_session()
{
    if (!sticpa_has_session()) {
        wp_safe_redirect(home_url());
        exit;
    }
}

/* ---------------------------------------------------------------------------
 *  2. A DÓNDE SE VUELVE (plan 005)
 * ---------------------------------------------------------------------------
 *  Los formularios mandan `scp_current_url` para que el handler sepa a qué
 *  pantalla volver. Es un campo del cliente: se puede cambiar por
 *  `https://otro-sitio` y convertir nuestro dominio en el trampolín de un
 *  phishing. Se queda la RUTA y la QUERY; el esquema y el host se tiran
 *  siempre, así que el destino solo puede ser una página de este sitio.
 * ------------------------------------------------------------------------- */

/**
 * La ruta de `scp_current_url`, sin host ni query. Si no vale, la raíz.
 */
function sticpa_return_path()
{
    $raw = stripslashes((string) ($_REQUEST['scp_current_url'] ?? ''));
    $path = parse_url($raw, PHP_URL_PATH);
    // `//otro-sitio/x` es una URL «relativa al protocolo»: el navegador la lee
    // como otro host. Y una barra invertida la tratan igual algunos navegadores.
    if (!is_string($path) || $path === '' || $path[0] !== '/' || strpos($path, '//') === 0 || strpos($path, '\\') !== false) {
        return '/';
    }
    return $path;
}

/**
 * `scp_current_url` sin host: ruta y query. A esto se le siguen pegando los
 * `&msg=true` de siempre, que es lo que esperan las pantallas.
 */
function sticpa_return_url()
{
    $raw = stripslashes((string) ($_REQUEST['scp_current_url'] ?? ''));
    $query = parse_url($raw, PHP_URL_QUERY);
    $path = sticpa_return_path();
    // Sin query se añade `?` vacía: los handlers concatenan `&msg=…` detrás y
    // sin ella quedaría `/area&msg=true`, una ruta que no existe.
    return $path . '?' . (is_string($query) ? str_replace(array("\r", "\n"), '', $query) : '');
}

/* ---------------------------------------------------------------------------
 *  3. QUÉ CAMPOS SE PUEDEN ESCRIBIR (plan 002)
 * ---------------------------------------------------------------------------
 *  Antes cada handler volcaba TODO `$_REQUEST` en `set_entry`. Con el usuario
 *  técnico, eso era poder escribir cualquier campo de la ficha: la contraseña
 *  del área, el token de acceso, a qué delegación pertenece…
 *
 *  Ahora el formulario, al pintarse, firma la lista de campos que enseña
 *  (makeForm → sticpa_form_fields_input). El handler solo acepta esos. Si la
 *  firma no cuadra o no llega, no se guarda nada. Es una lista BLANCA que sale
 *  sola de la definición del formulario: añadir un campo al form lo hace
 *  guardable sin tocar el handler, y lo que no se enseña no se puede escribir.
 *
 *  Encima de la lista blanca hay una NEGRA que manda siempre, aunque un
 *  formulario los pinte: campos que ningún formulario del área debe escribir.
 * ------------------------------------------------------------------------- */

/**
 * Nunca se aceptan del cliente. `id` y `deleted` los fija el handler cuando
 * toca (id desde la sesión o tras comprobar la propiedad; deleted solo en la
 * ruta de borrado). `assigned_user_id` es la delegación: de ahí cuelga lo que
 * ve cada monitor.
 */
function sticpa_forbidden_fields()
{
    return array(
        'id', 'deleted',
        'assigned_user_id', 'assigned_user_name',
        'created_by', 'modified_user_id', 'date_entered', 'date_modified',
        'stic_pa_username_c', 'stic_pa_password_c', 'ajmcm_pa_token_c',
    );
}

/** Tipos del motor de formularios que no mandan ningún valor. */
function sticpa_form_non_posting_types()
{
    return array('header', 'subheader', 'readOnly', 'info', 'filler', 'placeholder', 'image', 'div', 'ul', 'note', 'html');
}

/**
 * Nombres de los campos que un $fieldList de makeForm deja escribir.
 *
 * - Un campo con tipo de solo lectura no cuenta.
 * - Un campo `disabled` o `readonly` tampoco: el navegador no lo manda, así
 *   que si llega es que alguien lo ha puesto a mano.
 * - Un campo `html` pinta su propio HTML y el motor no sabe qué hay dentro:
 *   declara lo que manda con `'posts' => array('campo', …)`.
 */
function sticpa_form_posted_fields($fieldList)
{
    $noPost = sticpa_form_non_posting_types();
    $names = array();
    foreach ((array) $fieldList as $field) {
        if (!empty($field['posts'])) {
            foreach ((array) $field['posts'] as $posted) {
                $names[] = (string) $posted;
            }
        }
        if (empty($field['name'])) {
            continue;
        }
        $type = $field['type'] ?? '';
        if ($type !== '' && in_array($type, $noPost, true)) {
            continue;
        }
        $attrs = array_change_key_case((array) ($field['attributes'] ?? array()), CASE_LOWER);
        if (isset($attrs['disabled']) || isset($attrs['readonly'])) {
            continue;
        }
        $names[] = (string) $field['name'];
    }
    return array_values(array_unique($names));
}

/**
 * Secreto de la firma de los formularios. Se crea una vez y vive en
 * wp_options. Rotarlo solo invalida los formularios que haya abiertos en ese
 * momento: al recargar la página se firman de nuevo.
 */
function sticpa_form_secret()
{
    $secret = get_option('sticpa_form_secret');
    if (empty($secret)) {
        $secret = bin2hex(random_bytes(32));
        update_option('sticpa_form_secret', $secret, false);
    }
    return $secret;
}

/**
 * La firma: la lista de campos + el handler al que va el formulario. Atado al
 * handler para que la lista de un formulario no sirva en otro.
 */
function sticpa_form_token($action, $fields)
{
    $payload = sticpa_b64url_encode(wp_json_encode(array('a' => (string) $action, 'f' => array_values($fields))));
    return $payload . '.' . hash_hmac('sha256', $payload, sticpa_form_secret());
}

/** El hidden que lleva la firma dentro del formulario. */
function sticpa_form_fields_input($action, $fields)
{
    return "<input type='hidden' name='stic_form_fields' value='" . esc_attr(sticpa_form_token($action, $fields)) . "'>";
}

/**
 * La lista de campos firmada que trae la petición, si la firma es buena y es
 * de este handler. null si no llega, está manipulada o es de otro formulario.
 */
function sticpa_form_fields_from_request($action)
{
    $token = (string) ($_REQUEST['stic_form_fields'] ?? '');
    $parts = explode('.', $token);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        return null;
    }
    list($payload, $sig) = $parts;
    if (!hash_equals(hash_hmac('sha256', $payload, sticpa_form_secret()), $sig)) {
        return null;
    }
    $data = json_decode((string) sticpa_b64url_decode($payload), true);
    if (!is_array($data) || ($data['a'] ?? null) !== (string) $action || !is_array($data['f'] ?? null)) {
        return null;
    }
    return array_map('strval', $data['f']);
}

/**
 * Los datos para set_entry: SOLO los campos que el formulario firmó, menos los
 * prohibidos. Mismo formato de multivalor que antes (^a^,^b^).
 *
 * Devuelve null si la firma no vale: el handler no debe guardar nada.
 */
function sticpa_request_to_module_data($action)
{
    $allowed = sticpa_form_fields_from_request($action);
    if ($allowed === null) {
        return null;
    }
    $forbidden = sticpa_forbidden_fields();
    $data = array();
    foreach ($allowed as $name) {
        if (in_array($name, $forbidden, true) || !array_key_exists($name, $_REQUEST)) {
            continue;
        }
        $value = $_REQUEST[$name];
        $data[$name] = is_array($value)
            ? '^' . implode('^,^', stripslashes_deep($value)) . '^'
            : stripslashes_deep($value);
    }
    return $data;
}

/* ---------------------------------------------------------------------------
 *  4. ¿ES TUYO? (planes 002 y 003)
 * ---------------------------------------------------------------------------
 *  Un id que llega en la petición no se cree: se busca entre los registros que
 *  la persona en sesión ve en su listado, con LA MISMA relación que usa ese
 *  listado. Así «lo que puedes abrir» y «lo que ves en la lista» no pueden
 *  divergir.
 *
 *  Si el CRM no contesta, la respuesta es NO. Mejor un «no disponible» en un
 *  hipo del CRM que servirle a alguien un documento ajeno.
 * ------------------------------------------------------------------------- */

/**
 * Las relaciones desde la persona en sesión hasta los registros de un módulo
 * que son suyos. Son las de los listados (pages/list_*.php).
 */
function sticpa_owned_links($module)
{
    $accounts = getDestinationModule() === 'Accounts';
    switch ($module) {
        case 'Documents':
            return array('documents');
        case 'stic_Registrations':
            return array($accounts ? 'stic_registrations_accounts' : 'stic_registrations_contacts');
        case 'stic_Payment_Commitments':
            // Los que paga (contacts) y los que son para él aunque los pague
            // otro (contacts_1): el listado enseña unos u otros según quién mira.
            return $accounts
                ? array('stic_payment_commitments_accounts')
                : array('stic_payment_commitments_contacts', 'stic_payment_commitments_contacts_1');
        case 'stic_Payments':
            return array($accounts ? 'stic_payments_accounts' : 'stic_payments_contacts');
    }
    return array();
}

/**
 * Ids de los registros de $module que cuelgan de la persona en sesión.
 * null si el CRM no ha contestado (y entonces no se puede afirmar nada).
 * Se memoriza por petición.
 */
function sticpa_owned_ids($objSCP, $module)
{
    static $memo = array();
    $userId = (string) ($_SESSION['scp_user_id'] ?? '');
    // El cliente del CRM entra en la clave para que un doble de test nuevo no
    // herede la respuesta del anterior. En producción es siempre el mismo.
    $key = $userId . '|' . $module . '|' . (is_object($objSCP) ? spl_object_id($objSCP) : '-');
    if (array_key_exists($key, $memo)) {
        return $memo[$key];
    }
    $links = sticpa_owned_links($module);
    if ($userId === '' || empty($links) || !$objSCP) {
        return $memo[$key] = null;
    }
    $ids = array();
    foreach ($links as $link) {
        $rows = $objSCP->getRelatedElementsForLoggedUser(array(
            'module_name' => getDestinationModule(),
            'module_id' => $userId,
            'link_field_name' => $link,
            'related_fields' => array('id'),
            'related_module_link_name_to_fields_array' => array(),
            'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 0,
        ));
        if (!is_array($rows)) {
            return $memo[$key] = null;
        }
        foreach ($rows as $row) {
            $id = $row->id ?? ($row->name_value_list->id->value ?? '');
            if ($id !== '') {
                $ids[] = (string) $id;
            }
        }
    }
    return $memo[$key] = array_values(array_unique($ids));
}

/**
 * ¿El registro $id de $module es de la persona en sesión?
 *
 * Los pagos tienen un segundo camino: un menor no paga nada, pero ve los
 * pagos de los compromisos que son para él (es lo que hace su listado). Ahí se
 * pregunta al pago por su compromiso y se mira si ese compromiso es suyo.
 */
function sticpa_user_owns_record($objSCP, $module, $id)
{
    $id = (string) $id;
    if ($id === '' || !sticpa_has_session()) {
        return false;
    }
    // Una sesión no cuelga de la persona sino de un EVENTO: es tuya si es de
    // un evento en el que tienes una inscripción activa (lo mismo que enseña
    // «Mis sesiones»). El evento se lee del campo plano `_ida`, la regla de la
    // casa: esta instancia no devuelve los enlaces anidados.
    if ($module === 'stic_Sessions') {
        $detail = $objSCP->getRecordDetail($id, 'stic_Sessions', array('id', 'stic_sessions_stic_eventsstic_events_ida'));
        $eventId = (string) ($detail->entry_list[0]->name_value_list->stic_sessions_stic_eventsstic_events_ida->value ?? '');
        return $eventId !== '' && function_exists('prefix_user_active_event_ids')
            && in_array($eventId, prefix_user_active_event_ids($objSCP), true);
    }

    $owned = sticpa_owned_ids($objSCP, $module);
    if (is_array($owned) && in_array($id, $owned, true)) {
        return true;
    }
    if ($module === 'stic_Payments' && getDestinationModule() !== 'Accounts') {
        $commitments = $objSCP->getRelatedElementsForLoggedUser(array(
            'module_name' => 'stic_Payments',
            'module_id' => $id,
            'link_field_name' => 'stic_payments_stic_payment_commitments',
            'related_fields' => array('id'),
            'related_module_link_name_to_fields_array' => array(),
            'deleted' => 0, 'order_by' => '', 'offset' => '', 'limit' => 1,
        ));
        foreach ((is_array($commitments) ? $commitments : array()) as $row) {
            $commitmentId = $row->id ?? ($row->name_value_list->id->value ?? '');
            if ($commitmentId !== '' && sticpa_user_owns_record($objSCP, 'stic_Payment_Commitments', $commitmentId)) {
                return true;
            }
        }
    }
    return false;
}

/* ---------------------------------------------------------------------------
 *  5. CAMBIO DE PARTICIPANTE (plan 004)
 * ---------------------------------------------------------------------------
 *  `scp_user_id` es lo que leen TODAS las pantallas. Si el cambio de
 *  participante acepta cualquier id, cualquiera con sesión se convierte en
 *  cualquier contacto del CRM. Solo se puede pasar a uno mismo (el familiar)
 *  o a alguien de su lista de participantes, que sale del CRM y vive en sesión.
 * ------------------------------------------------------------------------- */

/**
 * El participante $id al que se quiere pasar, si está permitido:
 * array('id' => …, 'name' => …). null si no.
 */
function sticpa_allowed_profile($id, $objSCP = null)
{
    $id = (string) $id;
    if ($id === '' || !sticpa_has_session()) {
        return null;
    }
    // Quien inició sesión. Si ya se ha cambiado alguna vez, está fijado en
    // `scp_tutor_user_id`; si no, todavía es `scp_user_id`.
    $familiarId = (string) ($_SESSION['scp_tutor_user_id'] ?? $_SESSION['scp_user_id']);
    $familiarName = (string) ($_SESSION['scp_tutor_user_contact_name'] ?? ($_SESSION['scp_user_contact_name'] ?? ''));
    if ($id === $familiarId) {
        return array('id' => $familiarId, 'name' => $familiarName);
    }

    $profiles = sticpa_available_profiles();
    if (empty($profiles) && $objSCP && function_exists('sticpa_load_family_participants')) {
        $profiles = sticpa_load_family_participants($objSCP);
    }
    foreach ((array) $profiles as $profile) {
        $profile = (array) $profile;
        if (isset($profile['id']) && (string) $profile['id'] === $id) {
            return array('id' => $id, 'name' => (string) ($profile['name'] ?? ''));
        }
    }
    return null;
}

/* ---------------------------------------------------------------------------
 *  6. FICHEROS (plan 003)
 * ------------------------------------------------------------------------- */

/** Nombre de fichero apto para una cabecera: sin saltos de línea ni comillas. */
function sticpa_header_filename($name)
{
    $name = str_replace(array("\r", "\n", '"', '\\', '/'), '', (string) $name);
    $name = trim($name);
    return $name !== '' ? $name : 'documento';
}

/** Extensiones que se aceptan como documento o certificado. */
function sticpa_allowed_upload_extensions()
{
    return array('pdf', 'jpg', 'jpeg', 'png', 'gif', 'heic', 'webp', 'doc', 'docx', 'odt', 'xls', 'xlsx', 'ods', 'txt');
}

/**
 * ¿Se puede subir este fichero de $_FILES? Tope de 6 MB (el mismo que la foto)
 * y extensión de la lista. Se mira la extensión porque es lo que decide cómo
 * lo abre quien lo descargue después.
 */
function sticpa_upload_is_acceptable($file, $extensions = null)
{
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return false;
    }
    if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 6 * 1048576) {
        return false;
    }
    $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    return in_array($ext, $extensions ?? sticpa_allowed_upload_extensions(), true);
}

/**
 * Para las páginas de ficha: '' si el registro `?id=` se puede enseñar, o la
 * tarjeta de «no disponible» si no. Sin id no hay nada que comprobar ('').
 *
 * Se dice «ya no está disponible» y no «no es tuyo» a propósito: la segunda
 * frase confirmaría que ese id existe.
 */
function sticpa_record_denied_html($objSCP, $module, $id, $listPage, $title)
{
    $id = (string) $id;
    if ($id === '' || sticpa_user_owns_record($objSCP, $module, $id)) {
        return '';
    }
    return sticpa_record_empty_html(
        'card',
        $title,
        __('Vuelve al listado y entra de nuevo desde ahí.', 'sticpa'),
        array('label' => __('Volver', 'sticpa'), 'url' => '?internalpage=' . $listPage, 'primary' => true)
    );
}
