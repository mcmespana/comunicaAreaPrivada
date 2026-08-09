<?php

/**
 * ============================================================================
 *  CÓDIGO DE ACCESO (OTP) de 6 cifras
 * ----------------------------------------------------------------------------
 *  Complemento del enlace mágico (`stic-magic-login.php`), no sustituto: el
 *  mismo correo lleva las dos cosas y cualquiera de las dos entra igual.
 *
 *  ¿Por qué existe? Porque dentro de la app MCM el enlace es FRÁGIL. Si el
 *  cliente de correo envuelve la URL en un redirector, el universal link se
 *  pierde (lo avisa `stic-app-links.php`) y la sesión acaba en el navegador,
 *  no en la WebView: en la app sigue sin haber sesión y parece que la app no
 *  funciona. El código es lo único que sobrevive a cualquier cliente de correo,
 *  porque quien lo transporta es la persona. De paso resuelve el caso "leo el
 *  correo en el ordenador y quiero entrar en el móvil".
 *
 *  ────────────────────────────────────────────────────────────────────────
 *  SEGURIDAD — léelo antes de tocar los números de aquí abajo
 *  ────────────────────────────────────────────────────────────────────────
 *  Seis cifras son 1 entre un millón: muchísimo menos que el HMAC de 256 bits
 *  del enlace mágico. Lo que hace aceptable el código NO es su longitud, es el
 *  contador de fallos. Concretamente:
 *
 *   · El contador va por EMAIL, no por código, y pedir un código nuevo NO lo
 *     reinicia. Si fuera por código, bastaría con pedir otro cada 10 intentos
 *     para tener intentos infinitos y el límite no valdría absolutamente nada.
 *     Esta es LA propiedad que sostiene todo lo demás.
 *   · A los 10 fallos, ese email deja de aceptar códigos durante una hora.
 *     Nadie se queda fuera del área por esto: el enlace del mismo correo sigue
 *     funcionando (también si alguien quema los intentos de otro a propósito).
 *   · Los ENVÍOS también están limitados, por email y por IP. Sin eso
 *     cualquiera podía bombardear el buzón de cualquier contacto del CRM, y
 *     además se podía enumerar la base de datos a base de probar direcciones.
 *
 *  Nada de esto revela si un email existe o no: el flujo responde igual para
 *  una dirección registrada y para una inventada.
 * ============================================================================
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ============================================================================
 *  Parámetros (todos filtrables, por si hay que apretar sin tocar el código)
 * ========================================================================== */

/** Validez del código. */
function sticpa_otp_ttl()
{
    return (int) apply_filters('sticpa_otp_ttl', 40 * MINUTE_IN_SECONDS);
}

/** Fallos permitidos por email antes de bloquear la vía del código. */
function sticpa_otp_max_attempts()
{
    return (int) apply_filters('sticpa_otp_max_attempts', 10);
}

/** Cuánto dura el bloqueo por fallos. */
function sticpa_otp_lockout_window()
{
    return (int) apply_filters('sticpa_otp_lockout_window', HOUR_IN_SECONDS);
}

/** Envíos permitidos al mismo email dentro de la ventana. */
function sticpa_otp_send_max()
{
    return (int) apply_filters('sticpa_otp_send_max', 5);
}

function sticpa_otp_send_window()
{
    return (int) apply_filters('sticpa_otp_send_window', 20 * MINUTE_IN_SECONDS);
}

/**
 * Envíos permitidos desde la misma IP. Generoso a propósito: una familia, un
 * colegio o un hosting con proxy comparten IP y no queremos falsos positivos.
 * El límite que de verdad protege es el de por email.
 */
function sticpa_otp_ip_send_max()
{
    return (int) apply_filters('sticpa_otp_ip_send_max', 30);
}

function sticpa_otp_ip_send_window()
{
    return (int) apply_filters('sticpa_otp_ip_send_window', HOUR_IN_SECONDS);
}

/* ============================================================================
 *  Utilidades
 * ========================================================================== */

/** Forma canónica del email: lo que se usa para TODAS las claves. */
function sticpa_otp_normalize_email($email)
{
    return strtolower(trim((string) $email));
}

/**
 * Clave de transient. El email va hasheado con el secreto HMAC del área: así no
 * queda ninguna dirección en claro en `wp_options`, que es donde acaban los
 * transients cuando no hay caché de objetos.
 */
function sticpa_otp_key($scope, $value)
{
    $hash = hash_hmac('sha256', $scope . '|' . $value, sticpa_get_magic_secret());
    return 'sticpa_otp_' . $scope . '_' . substr($hash, 0, 32);
}

/** Código de 6 cifras. `random_int` es CSPRNG; `rand()` aquí sería un bug. */
function sticpa_otp_generate_code()
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Deja solo dígitos. Necesario porque el código se muestra como «123 456» y la
 * gente lo copia y pega con el espacio (y a veces con un guion).
 */
function sticpa_otp_normalize_code($raw)
{
    return preg_replace('/\D/', '', (string) $raw);
}

/** «123456» -> «123 456», que es como se lee y se dicta por teléfono. */
function sticpa_otp_format_code($code)
{
    $code = sticpa_otp_normalize_code($code);
    if (strlen($code) !== 6) {
        return $code;
    }
    return substr($code, 0, 3) . ' ' . substr($code, 3, 3);
}

/** «juan.perez@gmail.com» -> «ju•••••••@gmail.com», para confirmar sin revelar. */
function sticpa_otp_mask_email($email)
{
    $email = sticpa_otp_normalize_email($email);
    $at = strpos($email, '@');
    if ($at === false || $at < 1) {
        return '';
    }
    $user = substr($email, 0, $at);
    $domain = substr($email, $at);
    $keep = ($at >= 3) ? 2 : 1;
    return substr($user, 0, $keep) . str_repeat('•', max(3, strlen($user) - $keep)) . $domain;
}

/**
 * IP de quien llama. SOLO `REMOTE_ADDR`: las cabeceras `X-Forwarded-For` y
 * compañía las pone quien hace la petición, y un límite que se salta añadiendo
 * una cabecera no es un límite.
 */
function sticpa_otp_client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
}

/* ============================================================================
 *  Emisión
 * ========================================================================== */

/**
 * Genera y guarda un código para ese email. Devuelve el código EN CLARO (solo
 * para meterlo en el correo); en servidor queda únicamente su HMAC.
 *
 * Guardamos también módulo e id del contacto que ya resolvimos al enviar, así
 * la verificación no necesita volver a buscar por email en el CRM.
 */
function sticpa_otp_issue($email, $module, $contactId)
{
    $email = sticpa_otp_normalize_email($email);
    $code = sticpa_otp_generate_code();

    set_transient(sticpa_otp_key('code', $email), array(
        'hash' => hash_hmac('sha256', $code, sticpa_get_magic_secret()),
        'module' => $module,
        'id' => $contactId,
    ), sticpa_otp_ttl());

    return $code;
}

/* ============================================================================
 *  Límite de envíos
 * ========================================================================== */

/** ¿Se le puede mandar otro código a este email desde esta IP? */
function sticpa_otp_send_allowed($email)
{
    $email = sticpa_otp_normalize_email($email);
    if ($email !== '' && (int) get_transient(sticpa_otp_key('sent', $email)) >= sticpa_otp_send_max()) {
        return false;
    }
    $ip = sticpa_otp_client_ip();
    if ($ip !== '' && (int) get_transient(sticpa_otp_key('ipsent', $ip)) >= sticpa_otp_ip_send_max()) {
        return false;
    }
    return true;
}

/**
 * Apunta un envío. Se llama SIEMPRE que alguien pide un código, exista o no el
 * email: si solo contáramos los envíos reales, se podría enumerar la base de
 * datos probando direcciones sin gastar cupo.
 *
 * Cada intento renueva la ventana a propósito: quien insiste se mantiene él
 * solo en el límite.
 */
function sticpa_otp_note_send($email)
{
    $email = sticpa_otp_normalize_email($email);
    if ($email !== '') {
        $key = sticpa_otp_key('sent', $email);
        set_transient($key, ((int) get_transient($key)) + 1, sticpa_otp_send_window());
    }
    $ip = sticpa_otp_client_ip();
    if ($ip !== '') {
        $key = sticpa_otp_key('ipsent', $ip);
        set_transient($key, ((int) get_transient($key)) + 1, sticpa_otp_ip_send_window());
    }
}

/* ============================================================================
 *  Verificación
 * ========================================================================== */

/** ¿Está bloqueada la vía del código para este email? */
function sticpa_otp_is_locked($email)
{
    $fails = (int) get_transient(sticpa_otp_key('fail', sticpa_otp_normalize_email($email)));
    return $fails >= sticpa_otp_max_attempts();
}

/**
 * Suma un fallo. La ventana se renueva en cada fallo: quien esté probando
 * códigos a lo bruto se mantiene bloqueado solo mientras siga probando.
 */
function sticpa_otp_note_failure($email)
{
    $key = sticpa_otp_key('fail', sticpa_otp_normalize_email($email));
    $fails = ((int) get_transient($key)) + 1;
    set_transient($key, $fails, sticpa_otp_lockout_window());
    return $fails;
}

/**
 * Valida un código contra un email.
 *
 * @return array|false  array(module, contactId) si es correcto; false si no.
 *
 * El email llega del formulario, no de la sesión, y no pasa nada: el código
 * está guardado POR email, así que poner otra dirección solo sirve para fallar
 * contra el cupo de esa dirección. A cambio funciona el caso importante: pedir
 * el código en el ordenador y teclearlo en la app del móvil.
 */
function sticpa_otp_verify($email, $rawCode)
{
    $email = sticpa_otp_normalize_email($email);
    $code = sticpa_otp_normalize_code($rawCode);

    // Ni email ni código con pinta de código: no llega a ser un intento.
    if ($email === '' || strlen($code) !== 6) {
        return false;
    }
    if (sticpa_otp_is_locked($email)) {
        return false;
    }

    $stored = get_transient(sticpa_otp_key('code', $email));
    if (!is_array($stored) || empty($stored['hash'])) {
        // Caducado, ya usado, o email que nunca pidió nada. Cuenta como fallo:
        // si no, probar direcciones al azar saldría gratis.
        sticpa_otp_note_failure($email);
        return false;
    }

    if (!hash_equals((string) $stored['hash'], hash_hmac('sha256', $code, sticpa_get_magic_secret()))) {
        sticpa_otp_note_failure($email);
        return false;
    }

    // Un solo uso: al acertar, el código muere y el contador de fallos también.
    delete_transient(sticpa_otp_key('code', $email));
    delete_transient(sticpa_otp_key('fail', $email));

    return array($stored['module'], $stored['id']);
}
