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
 *     cualquiera podía bombardear el buzón de cualquier contacto del CRM.
 *
 *  ────────────────────────────────────────────────────────────────────────
 *  ¿SE DICE SI UN CORREO EXISTE? SÍ (31/10/2026) — antes no
 *  ────────────────────────────────────────────────────────────────────────
 *  Hasta el 31/10/2026 el flujo respondía igual para un correo registrado y
 *  para uno inventado, para no regalar una forma de enumerar el CRM. El precio
 *  era real: quien se equivocaba de correo se quedaba esperando un mensaje que
 *  nunca iba a llegar, sin nada que se lo dijera. Decisión del propietario: si
 *  el correo no existe, se dice, y en el mismo sitio se ofrece entrar por DNI
 *  (que ya contestaba con la verdad).
 *
 *  Lo que NO se quita es el freno: cada correo que no existe se apunta por IP
 *  (`sticpa_otp_note_miss`) y, pasado el tope, ni se mira (`…_miss_allowed`).
 *  Quien se equivoca una o dos veces no lo nota; quien barre direcciones, sí.
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

/**
 * Correos que NO existen que se toleran desde una misma IP (31/10/2026).
 *
 * Es el freno de la decisión de decir «ese correo no está»: como ahora la
 * respuesta distingue, probar direcciones al azar ya no es gratis. Ocho en
 * media hora deja de sobra a quien se equivoca (se le ofrece el DNI a la
 * segunda) y a un colegio entero con una sola IP, pero no a un barrido.
 */
function sticpa_otp_miss_max()
{
    return (int) apply_filters('sticpa_otp_miss_max', 8);
}

function sticpa_otp_miss_window()
{
    return (int) apply_filters('sticpa_otp_miss_window', 30 * MINUTE_IN_SECONDS);
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
 * email: es el tope de quien bombardea un buzón, y no debe depender de si la
 * dirección está en el CRM.
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

/**
 * ¿Se puede mirar en el CRM si existe otro correo desde esta IP? (31/10/2026)
 * Va por IP y no por correo: lo que se frena es probar muchos distintos.
 */
function sticpa_otp_miss_allowed()
{
    $ip = sticpa_otp_client_ip();
    return $ip === '' || (int) get_transient(sticpa_otp_key('ipmiss', $ip)) < sticpa_otp_miss_max();
}

/**
 * Apunta un correo que no existe. Cada fallo renueva la ventana: quien insiste
 * se mantiene él solo en el tope. Los aciertos no cuentan (el límite de envíos
 * por correo ya se ocupa de ellos).
 */
function sticpa_otp_note_miss()
{
    $ip = sticpa_otp_client_ip();
    if ($ip === '') {
        return;
    }
    $key = sticpa_otp_key('ipmiss', $ip);
    set_transient($key, ((int) get_transient($key)) + 1, sticpa_otp_miss_window());
}

/* ============================================================================
 *  ENTRAR CUANDO NO SABES CON QUÉ CORREO TE DISTE DE ALTA
 * ----------------------------------------------------------------------------
 *  El caso real: alguien escribe su correo, no le llega nada y no hay forma de
 *  saber por qué. Hasta el 31/10/2026 era porque el flujo NUNCA decía si un
 *  correo existía; ahora lo dice (ver la cabecera de este fichero) y, en la
 *  misma pantalla, ofrece esta vía.
 *
 *  La salida es preguntar por el DNI. Tiene tres virtudes:
 *
 *   1. **Es la que sabe la persona**: su DNI se lo sabe, el correo con el que
 *      rellenó un formulario hace ocho meses, no.
 *   2. **Enseñar el correo enmascarado ayuda de verdad** —«ya, era el del
 *      trabajo»— y cuesta poco: hay que acertar un DNI para verlo.
 *   3. **Sirve también cuando el correo no existe**: no hace falta recordarlo,
 *      solo recordarse a uno mismo.
 *
 *  LO QUE ESTA VÍA NO HACE, Y ES LO IMPORTANTE: **no abre sesión**. Un DNI no
 *  es un secreto (está en cualquier formulario que hayas firmado), así que aquí
 *  solo sirve para MANDAR el acceso al correo que ya tenemos guardado. Quien no
 *  tenga ese correo, no entra.
 * ========================================================================== */

/**
 * Deja el documento en letras y dígitos, en mayúsculas.
 *
 * La gente lo escribe como quiere: `12345678-z`, `12.345.678 Z`, con espacios
 * al final del autocompletado del móvil. En el CRM está sin puntos.
 */
function sticpa_dni_normalize($raw)
{
    $raw = (string) $raw;
    $raw = function_exists('mb_strtoupper') ? mb_strtoupper($raw, 'UTF-8') : strtoupper($raw);
    return preg_replace('/[^A-Z0-9]/', '', $raw);
}

/**
 * ¿Merece la pena preguntarle al CRM por esto?
 *
 * NO se comprueba que sea un DNI válido (ni la letra): en el CRM hay también
 * pasaportes y documentos de otros países (`stic_identification_type_c`), y
 * rechazar lo que no encaje en el molde español dejaría fuera justo a quien más
 * lío tiene para entrar. Esto solo descarta lo que no puede ser un documento de
 * nadie, para no gastar una llamada al CRM con `12`.
 */
function sticpa_dni_es_plausible($dni)
{
    $dni = sticpa_dni_normalize($dni);
    $len = strlen($dni);
    return ($len >= 5 && $len <= 20);
}

/** ¿Se le puede mandar acceso a este documento, o está pidiéndolo en bucle? */
function sticpa_dni_send_allowed($dni)
{
    $dni = sticpa_dni_normalize($dni);
    if ($dni !== '' && (int) get_transient(sticpa_otp_key('dnisent', $dni)) >= sticpa_otp_send_max()) {
        return false;
    }
    // Y el mismo tope por IP que la vía del correo: si no, esta puerta sería la
    // barata para probar documentos a lo bruto.
    $ip = sticpa_otp_client_ip();
    if ($ip !== '' && (int) get_transient(sticpa_otp_key('ipsent', $ip)) >= sticpa_otp_ip_send_max()) {
        return false;
    }
    return true;
}

/**
 * Apunta el intento. Igual que en la vía del correo, se apunta SIEMPRE —exista
 * o no el documento—: si solo contáramos los aciertos, gastar cupo sería una
 * forma de saber qué documentos están dados de alta.
 */
function sticpa_dni_note_send($dni)
{
    $dni = sticpa_dni_normalize($dni);
    if ($dni !== '') {
        $key = sticpa_otp_key('dnisent', $dni);
        set_transient($key, ((int) get_transient($key)) + 1, sticpa_otp_send_window());
    }
    $ip = sticpa_otp_client_ip();
    if ($ip !== '') {
        $key = sticpa_otp_key('ipsent', $ip);
        set_transient($key, ((int) get_transient($key)) + 1, sticpa_otp_ip_send_window());
    }
}

/**
 * `sticpa_icon()` vive en el archivo principal del plugin y aquí no siempre
 * está (los tests cargan solo los `inc/`). Un icono que falta no puede tumbar
 * una pantalla de acceso: si no está la función, se pinta nada.
 */
function sticpa_otp_icon($name, $class = '')
{
    return function_exists('sticpa_icon') ? sticpa_icon($name, $class) : '';
}

/**
 * A DÓNDE SE MANDA A QUIEN NO TIENE ACCESO.
 *
 * Las altas NO se hacen en el área privada: se hacen en la web pública, donde
 * cada caso —familia del MIC, del COM, monitor, laico— tiene su formulario. El
 * login llevaba a `?internalpage=single_stic_signup`, una página que fuera de
 * sesión no se pintaba: un enlace a un div vacío (archivada el 25/09/2026).
 *
 * Se puede cambiar sin tocar código con la opción `sticpa_signup_url` o con el
 * filtro del mismo nombre.
 */
function sticpa_signup_url()
{
    $url = get_option('sticpa_signup_url');
    if (empty($url)) {
        $url = 'https://comunica.movimientoconsolacion.com/';
    }
    return apply_filters('sticpa_signup_url', $url);
}

/**
 * El correo al que escribir cuando ya no queda nada que probar.
 *
 * Aparece en la pantalla del código y en los errores del acceso por documento:
 * es el final del camino, y hasta ahora ese final no estaba escrito en ninguna
 * parte —la persona se quedaba mirando una pantalla que no le decía qué hacer—.
 */
function sticpa_support_email()
{
    $mail = get_option('sticpa_support_email');
    if (empty($mail)) {
        $mail = 'comunica@movimientoconsolacion.com';
    }
    return apply_filters('sticpa_support_email', $mail);
}

/**
 * EL FORMULARIO DE «no sé con qué correo me di de alta».
 *
 * Se pinta en dos sitios —el login y la pantalla del código— y por eso está
 * aquí: es el mismo formulario, y dos copias acaban divergiendo. Devuelve SOLO
 * el formulario: lo envuelve el desplegable de ayuda de cada pantalla
 * (`sticpa_login_help_html`, `sticpa_access_rescue_html`), siempre cerrado,
 * porque es la salida de emergencia y no la puerta principal.
 *
 * El porqué de esta vía y lo que NO hace (no abre sesión) está en
 * `inc/stic-otp.php` y en `sticpa_handle_send_access_dni`.
 */
function sticpa_dni_access_form_html($return_url, $soft = false)
{
    // `$soft`: el botón va en contorno y no en degradado. Es para cuando el
    // formulario comparte pantalla con otro botón de marca (el del correo, tras
    // un «ese correo no existe»): design.md, una sola acción principal.
    $button = $soft
        ? array(__('Buscarme por mi DNI', 'sticpa'), ' stic-btn-soft')
        : array(__('Enviarme el acceso', 'sticpa'), '');
    return "
        <form action='" . site_url() . "/wp-admin/admin-post.php' method='post' class='stic-loading-form stic-dni-form'
              data-loading-text='" . esc_attr__('Buscándote…', 'sticpa') . "'
              data-loading-sub='" . esc_attr__('Si te encontramos, te mandamos el acceso a tu correo.', 'sticpa') . "'>
            <label class='stic-code-label' for='stic-dni'>" . esc_html__('Tu DNI o NIE', 'sticpa') . "</label>
            <span class='stic-field'>
                <span class='stic-field-icon'>" . sticpa_otp_icon('user') . "</span>
                <input type='text' class='input-text' id='stic-dni' name='sticpa_dni'
                       autocomplete='off' autocapitalize='characters' spellcheck='false'
                       placeholder='" . esc_attr__('12345678Z', 'sticpa') . "' required>
            </span>
            <input type='hidden' name='action' value='sticpa_send_access_dni'>
            <input type='hidden' name='scp_current_url' value='" . esc_attr($return_url) . "'>
            <button type='submit' class='stic-btn-magic" . $button[1] . "'>
                <span class='stic-btn-magic-icon'>" . sticpa_otp_icon($soft ? 'arrow' : 'send') . "</span>
                <span>" . esc_html($button[0]) . "</span>
            </button>
        </form>";
}

/**
 * Un desplegable de ayuda: la pregunta fuera, todo lo demás dentro.
 *
 * Las dos pantallas del acceso tienen UNO, y solo uno (03/10/2026): antes el
 * login llevaba dos (`¿Qué correo debo poner?` entre el campo y el botón, y
 * `No sé con qué correo me di de alta` debajo) y la pantalla del código una
 * lista de cuatro consejos siempre a la vista. Es lo que hacen las pantallas
 * de acceso de cualquier servicio: campo, botón y la ayuda plegada.
 */
function sticpa_auth_help_details($summary, $body, $open = false)
{
    return "
        <details class='stic-code-reveal stic-auth-rescue'" . ($open ? " open" : "") . ">
            <summary>" . sticpa_otp_icon('help', 'stic-hint-icon') . "<span>" . esc_html($summary) . "</span>"
                . sticpa_otp_icon('chevron', 'stic-hint-chevron') . "</summary>
            <div class='stic-auth-rescue-body'>" . $body . "</div>
        </details>";
}

/**
 * La ayuda del LOGIN: qué correo poner y, si no lo sabes, el DNI.
 *
 * @param bool $open Abierto cuando se vuelve de un error del DNI: el mensaje
 *                   sale arriba y el formulario tiene que estar a mano.
 */
function sticpa_login_help_html($return_url, $open = false)
{
    $body = "<p><strong>" . esc_html__('Familias de MIC y COM:', 'sticpa') . "</strong> "
        . esc_html__('el correo del familiar, no el del participante.', 'sticpa') . "</p>"
        . "<p><strong>" . esc_html__('Miembros del MCM:', 'sticpa') . "</strong> "
        . esc_html__('tu correo propio.', 'sticpa') . "</p>"
        . "<p>" . esc_html__('¿No recuerdas cuál? Escribe tu DNI y te mandamos el acceso al correo que tenemos.', 'sticpa') . "</p>"
        . sticpa_dni_access_form_html($return_url);

    return sticpa_auth_help_details(__('¿Qué correo pongo?', 'sticpa'), $body, $open);
}

/**
 * LO QUE SE VE CUANDO EL CORREO NO ESTÁ (31/10/2026).
 *
 * Dos piezas que el login pinta en sitios distintos de la misma pantalla:
 *
 *   · el MENSAJE, arriba, con la dirección que se tecleó para que la errata se
 *     vea (y se corrige en el propio campo, que vuelve relleno);
 *   · la TARJETA del DNI, debajo del botón del correo, ya abierta y con el
 *     botón en contorno: es la otra salida, no una segunda acción principal.
 *
 * La dirección sale escapada y en negrita; es la que acaba de escribir quien
 * mira la pantalla, así que mostrarla no revela nada de nadie.
 *
 * @param string $email El correo tecleado ('' si la sesión lo perdió).
 */
function sticpa_email_miss_message_html($email)
{
    $head = $email !== ''
        ? sprintf(
            /* translators: %s: el correo que se escribió, ya escapado y en negrita */
            esc_html__('No encontramos este correo: %s', 'sticpa'),
            '<strong>' . esc_html($email) . '</strong>'
        )
        : esc_html__('No encontramos ese correo.', 'sticpa');

    return "<span id='stic-email-miss' class='error stic-msg-long' role='alert'>" . $head . "</span>"
        . "<p class='stic-auth-help'>"
        . esc_html__('¿Una errata? Corrígela y vuelve a probar. Si eres familia de un participante, pon el correo del familiar.', 'sticpa')
        . "</p>";
}

/** La tarjeta del DNI de debajo del botón del correo (ver arriba). */
function sticpa_email_miss_dni_html($return_url)
{
    return "
        <section class='stic-auth-alt' aria-labelledby='stic-auth-alt-title'>
            <h4 id='stic-auth-alt-title' class='stic-auth-alt-title'>" . esc_html__('¿Te diste de alta con otro correo?', 'sticpa') . "</h4>
            <p class='stic-auth-alt-sub'>" . esc_html__('Escribe tu DNI o NIE y te mandamos el acceso al que tenemos guardado.', 'sticpa') . "</p>"
        . sticpa_dni_access_form_html($return_url, true) . "
        </section>";
}

/**
 * Los avisos de la vía del correo que no son «no está» (ese tiene su bloque).
 * Cada uno dice qué hacer ahora; un error que no conocemos no inventa mensaje.
 */
function sticpa_email_error_message($error)
{
    switch ($error) {
        case 'formato':
            return __('Escribe el correo entero, con su @ y su dominio.', 'sticpa');
        case 'throttled':
            return sprintf(
                /* translators: %s: dirección de correo de la oficina técnica */
                __('Has probado varias direcciones seguidas. Espera unos minutos y vuelve a intentarlo, o escríbenos a %s.', 'sticpa'),
                sticpa_support_email()
            );
        case 'crm':
            return __('Ahora mismo no podemos comprobar tu correo. Prueba otra vez en un minuto.', 'sticpa');
    }
    return '';
}

/**
 * LA AYUDA de la pantalla del código: «¿necesitas ayuda?».
 *
 * Aquí se llega con un correo que SÍ existe (desde el 31/10/2026 el acceso dice
 * cuando no), así que lo que queda por salir mal es lo de siempre: el correo en
 * spam, que sea el de otra persona de la familia, o que quien lo pidió prefiera
 * buscarse por el DNI. Las salidas, en orden de probabilidad: spam, el correo de
 * arriba, buscarse por el DNI y, al final, la oficina técnica.
 *
 * @param string $masked     El correo al que se mandó, ya tapado (puede ir vacío).
 * @param string $return_url URL de vuelta al área, para el formulario.
 */
function sticpa_access_rescue_html($masked, $return_url)
{
    $soporte = sticpa_support_email();
    $body = "<ul class='stic-auth-rescue-list'>";
    $body .= "<li>" . esc_html__('Mira en la carpeta de spam.', 'sticpa') . "</li>";
    if ($masked !== '') {
        // La dirección ya sale tapada en el subtítulo; aquí no se repite.
        $body .= "<li>" . esc_html__('Comprueba que el correo de arriba sea el tuyo.', 'sticpa') . "</li>";
    }
    $body .= "<li>" . esc_html__('¿Te diste de alta con otro correo? Búscate por tu DNI:', 'sticpa') . "</li>";
    $body .= "</ul>";
    $body .= sticpa_dni_access_form_html($return_url);
    $body .= "<p class='stic-auth-rescue-foot'>" . sprintf(
        /* translators: %s: enlace al correo de la oficina técnica */
        esc_html__('¿Sigues sin poder entrar? Escríbenos a %s.', 'sticpa'),
        "<a href='mailto:" . esc_attr($soporte) . "'>" . esc_html($soporte) . "</a>"
    ) . "</p>";

    return sticpa_auth_help_details(__('¿Necesitas ayuda?', 'sticpa'), $body);
}

/**
 * Los mensajes de la vía del documento. Uno por caso, y ninguno es «ha habido
 * un error»: cada uno dice qué ha pasado y qué hacer ahora.
 */
function sticpa_dni_error_message($error)
{
    $soporte = sticpa_support_email();
    switch ($error) {
        case 'formato':
            return __('Escribe el documento entero, sin espacios: número y letra.', 'sticpa');
        case 'throttled':
            return __('Lo has intentado varias veces seguidas. Espera unos minutos y vuelve a probar.', 'sticpa');
        case 'sincorreo':
            return sprintf(
                /* translators: %s: dirección de correo de la oficina técnica */
                __('Te hemos encontrado, pero no tenemos ningún correo tuyo guardado, así que no hay dónde mandarte el acceso. Escríbenos a %s y lo arreglamos.', 'sticpa'),
                $soporte
            );
        case 'nohay':
            return sprintf(
                /* translators: %s: dirección de correo de la oficina técnica */
                __('No encontramos a nadie con ese documento. Comprueba que esté bien escrito; si lo está, es que todavía no estás dado de alta: escríbenos a %s.', 'sticpa'),
                $soporte
            );
    }
    return '';
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
