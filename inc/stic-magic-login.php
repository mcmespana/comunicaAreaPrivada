<?php

/**
 * ============================================================================
 *  Acceso por enlace (passwordless) para el Área Privada
 * ----------------------------------------------------------------------------
 *  Dos formas de entrar sin usuario/contraseña:
 *
 *  1) TOKEN PERMANENTE  ->  .../area-privada/?token=XXXX
 *     - Cada contacto del CRM tiene un campo custom `ajmcm_pa_token_c` con un
 *       token aleatorio (128 bits). Se usa en el botón "Acceder" al pie de los
 *       emails y para impersonar desde el admin. Es revocable (se regenera).
 *
 *  2) ACCESO MÁGICO     ->  .../area-privada/?acceso_magico=XXXX
 *     - Enlace firmado con HMAC y CADUCABLE (por defecto 1h). NO se guarda nada
 *       en el CRM: es autovalidable con un secreto que vive en WordPress.
 *       Se usa en el flujo "introduce tu email y te mando acceso".
 *
 *  En ambos casos, al validar se crea la sesión PHP normal del área y se
 *  redirige a una URL limpia (sin el token en la barra de direcciones).
 *
 *  Toda la lógica vive aquí (WordPress). SinergiaCRM solo almacena el campo
 *  `ajmcm_pa_token_c` (creado con Studio, sin programar nada en el CRM).
 * ============================================================================
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Caducidad por defecto del acceso mágico (segundos). Configurable vía filtro.
 */
function sticpa_magic_link_ttl()
{
    return apply_filters('sticpa_magic_link_ttl', HOUR_IN_SECONDS);
}

/**
 * Secreto HMAC para firmar los accesos mágicos. Se genera una sola vez y se
 * guarda en wp_options. Rotarlo invalida TODOS los accesos mágicos vigentes.
 */
function sticpa_get_magic_secret()
{
    $secret = get_option('sticpa_magic_secret');
    if (empty($secret)) {
        $secret = bin2hex(random_bytes(32));
        update_option('sticpa_magic_secret', $secret, false);
    }
    return $secret;
}

/** Token permanente aleatorio (128 bits -> 32 hex). */
function sticpa_generate_access_token()
{
    return bin2hex(random_bytes(16));
}

/** Base64 URL-safe (sin +, /, =) para meter el payload en una URL. */
function sticpa_b64url_encode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function sticpa_b64url_decode($data)
{
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Módulos del CRM en los que buscar al usuario, según la configuración.
 * Si está en "Any", probamos Contacts y luego Accounts.
 */
function sticpa_modules_to_try()
{
    $module = getDestinationModule();
    if ($module === 'Any') {
        return array('Contacts', 'Accounts');
    }
    if ($module === 'Accounts') {
        return array('Accounts');
    }
    return array('Contacts');
}

/** Campos que necesitamos del contacto para montar la sesión. */
function sticpa_session_select_fields($module)
{
    if ($module === 'Accounts') {
        return array('id', 'name', 'stic_pa_username_c', 'assigned_user_id', 'email1', 'ajmcm_pa_token_c');
    }
    return array('id', 'name', 'stic_pa_username_c', 'account_id', 'assigned_user_id', 'email1', 'ajmcm_pa_token_c');
}

/**
 * Genera un enlace de acceso mágico firmado y caducable para un contacto.
 *
 * @param string $baseUrl   URL de la página del área privada (sin parámetros).
 * @param string $module    'Contacts' | 'Accounts'.
 * @param string $contactId ID del registro en el CRM.
 * @param int    $ttl       Validez en segundos.
 * @return string           URL completa con ?acceso_magico=...
 */
function sticpa_generate_magic_link($baseUrl, $module, $contactId, $ttl = null)
{
    $ttl = $ttl !== null ? $ttl : sticpa_magic_link_ttl();
    $exp = time() + (int) $ttl;
    $payload = $module . '|' . $contactId . '|' . $exp;
    $sig = hash_hmac('sha256', $payload, sticpa_get_magic_secret());
    $data = sticpa_b64url_encode($payload . '|' . $sig);
    return add_query_arg('acceso_magico', $data, $baseUrl);
}

/**
 * Valida un acceso mágico. Devuelve array(module, contactId) si es válido y no
 * ha caducado; false en caso contrario.
 */
function sticpa_validate_magic_link($data)
{
    $raw = sticpa_b64url_decode($data);
    if ($raw === false || strpos($raw, '|') === false) {
        return false;
    }
    $parts = explode('|', $raw);
    if (count($parts) !== 4) {
        return false;
    }
    list($module, $contactId, $exp, $sig) = $parts;

    if (!in_array($module, array('Contacts', 'Accounts'), true)) {
        return false;
    }
    $expected = hash_hmac('sha256', $module . '|' . $contactId . '|' . $exp, sticpa_get_magic_secret());
    if (!hash_equals($expected, (string) $sig)) {
        return false;
    }
    if (time() > (int) $exp) {
        return false; // caducado
    }
    return array($module, $contactId);
}

/**
 * Asigna (o regenera) el token permanente de un contacto vía API del CRM.
 *
 * @return string el token asignado.
 */
function sticpa_set_contact_token($module, $contactId, $token = null)
{
    $token = $token ? $token : sticpa_generate_access_token();
    $objSCP = SugarRestApiCall::getObjSCP();
    $objSCP->set_entry($module, array(
        'id' => $contactId,
        'ajmcm_pa_token_c' => $token,
    ));
    return $token;
}

/**
 * Genera tokens para los contactos que aún no tengan uno (procesado por lotes).
 *
 * @return int número de tokens generados en esta pasada.
 */
function sticpa_generate_tokens_bulk($module, $limit = 200)
{
    $objSCP = SugarRestApiCall::getObjSCP();
    $records = $objSCP->getRecordsModule($module, '', array('id', 'ajmcm_pa_token_c'));
    $count = 0;
    if (is_array($records)) {
        foreach ($records as $record) {
            $current = $record->name_value_list->ajmcm_pa_token_c->value ?? '';
            if ($current === '' || $current === null) {
                sticpa_set_contact_token($module, $record->id);
                $count++;
                if ($count >= $limit) {
                    break;
                }
            }
        }
    }
    return $count;
}

/**
 * Crea la sesión del área privada a partir de un registro del CRM.
 * Centraliza lo que antes hacía sólo el login por usuario/contraseña.
 *
 * @param object $entry  entry_list[0] del CRM (con ->id y ->name_value_list).
 * @param string $module 'Contacts' | 'Accounts'.
 */
function sticpa_establish_session($entry, $module)
{
    $nvl = $entry->name_value_list;

    $_SESSION['scp_module'] = $module;
    $_SESSION['scp_user_id'] = $entry->id;
    $_SESSION['scp_user_contact_name'] = $nvl->name->value ?? '';
    $_SESSION['scp_account_id'] = isset($nvl->account_id) ? $nvl->account_id->value : null;
    $_SESSION['scp_user_account_name'] = isset($nvl->stic_pa_username_c) ? $nvl->stic_pa_username_c->value : null;
    $_SESSION['scp_user_assigned_user_id'] = isset($nvl->assigned_user_id) ? $nvl->assigned_user_id->value : null;

    if (defined('RELATIONSHIP_TUTOR_TYPES')) {
        $_SESSION['scp_user_adult'] = check_user_adult($entry->id, RELATIONSHIP_TUTOR_TYPES);
    } else {
        $_SESSION['scp_user_adult'] = true;
    }
}

/**
 * Handler principal: intercepta ?token= y ?acceso_magico= en el front, valida,
 * crea la sesión y redirige a una URL limpia.
 *
 * Prioridad 2 para ejecutarse DESPUÉS de sugar_crm_portal_start_session (que
 * arranca la sesión PHP en init con prioridad 1).
 */
add_action('init', 'sticpa_process_passwordless_login', 2);
function sticpa_process_passwordless_login()
{
    if (is_admin()) {
        return;
    }
    if (isset($_SESSION['scp_user_id'])) {
        return; // ya hay sesión
    }
    $hasToken = isset($_REQUEST['token']);
    $hasMagic = isset($_REQUEST['acceso_magico']);
    if (!$hasToken && !$hasMagic) {
        return;
    }
    if (!class_exists('SugarRestApiCall')) {
        return;
    }

    // Pantalla de carga: validar contra el CRM puede tardar hasta ~5s. En lugar de
    // dejar el navegador en blanco, en la PRIMERA visita al enlace mostramos un
    // interstitial bonito ("Verificando tu acceso…") y, vía JS, relanzamos la misma
    // URL con ?sticpa_go=1 para hacer ahí la validación lenta. Beneficio extra: los
    // escáneres de enlaces de email (que no ejecutan JS) no consumen el acceso.
    if (!isset($_REQUEST['sticpa_go'])) {
        sticpa_render_access_loading_screen();
        exit;
    }

    $entry = null;
    $foundModule = null;

    if ($hasToken) {
        // El token es hexadecimal (lo generamos nosotros); saneamos a [a-f0-9].
        $token = preg_replace('/[^a-f0-9]/i', '', (string) $_REQUEST['token']);
        if (strlen($token) >= 16) {
            foreach (sticpa_modules_to_try() as $module) {
                $result = SugarRestApiCall::getObjSCP()->PortalLoginByToken($token, $module);
                if (isset($result->entry_list[0]) && $result->entry_list[0] != null) {
                    $entry = $result->entry_list[0];
                    $foundModule = $module;
                    break;
                }
            }
        }
    } elseif ($hasMagic) {
        $valid = sticpa_validate_magic_link((string) $_REQUEST['acceso_magico']);
        if ($valid) {
            list($foundModule, $contactId) = $valid;
            $result = SugarRestApiCall::getObjSCP()->getRecordDetail($contactId, $foundModule, sticpa_session_select_fields($foundModule));
            if (isset($result->entry_list[0]) && $result->entry_list[0] != null) {
                $entry = $result->entry_list[0];
            }
        }
    }

    if ($entry) {
        sticpa_establish_session($entry, $foundModule);
        // Redirigir a la misma URL pero sin el token (no queda en historial/marcadores).
        $clean = remove_query_arg(array('token', 'acceso_magico', 'sticpa_go'));
        wp_safe_redirect($clean);
        exit;
    }
    // Si no valida (token erróneo o caducado): limpiamos los parámetros de acceso
    // para no quedarnos en bucle en la pantalla de carga y mostramos el login.
    if (isset($_REQUEST['sticpa_go'])) {
        wp_safe_redirect(remove_query_arg(array('token', 'acceso_magico', 'sticpa_go')));
        exit;
    }
}

/**
 * Pantalla de carga (interstitial) que se muestra mientras validamos el enlace de
 * acceso contra el CRM. Es un documento HTML autónomo (estilos inline) porque se
 * pinta ANTES de que cargue el tema de WordPress. Relanza la misma URL con
 * ?sticpa_go=1 vía JS; <noscript> usa meta-refresh como salvaguarda.
 */
function sticpa_render_access_loading_screen()
{
    // Construimos el destino: misma URL + sticpa_go=1 (mantiene token/acceso_magico).
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    $currentUrl = $scheme . '://' . $host . $uri;
    $goUrl = add_query_arg('sticpa_go', '1', $currentUrl);

    $title = __('Verificando tu acceso…', 'sticpa');
    $sub = __('Estamos preparando tu área privada de forma segura. Esto puede tardar unos segundos.', 'sticpa');
    $goUrlAttr = esc_url($goUrl); // para el <meta refresh> del <noscript> (codifica & como &#038;, correcto en HTML)
    $lang = esc_attr(substr(get_locale(), 0, 2));

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }
    ?><!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?= esc_html($title) ?></title>
    <noscript><meta http-equiv="refresh" content="0;url=<?= $goUrlAttr ?>"></noscript>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --blue:#1c6fb3; --pink:#9d1e74; --violet:#6c4b9e; }
        * { box-sizing:border-box; margin:0; padding:0; }
        html,body { height:100%; }
        body {
            font-family:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
            display:flex; align-items:center; justify-content:center;
            min-height:100vh; padding:24px; color:#1f2937; overflow:hidden;
            background:
                radial-gradient(40% 50% at 18% 22%, rgba(28,111,179,.28), transparent 60%),
                radial-gradient(45% 55% at 85% 18%, rgba(157,30,116,.26), transparent 60%),
                radial-gradient(50% 60% at 70% 90%, rgba(108,75,158,.24), transparent 62%),
                linear-gradient(135deg,#eef5fc 0%,#f4eef9 50%,#fbeef5 100%);
            background-size:200% 200%;
            animation:mesh 16s ease-in-out infinite;
        }
        @keyframes mesh { 0%{background-position:0 0;} 50%{background-position:100% 100%;} 100%{background-position:0 0;} }
        .card {
            position:relative; width:100%; max-width:440px; padding:48px 36px; text-align:center;
            background:rgba(255,255,255,.74); backdrop-filter:blur(18px) saturate(1.4);
            -webkit-backdrop-filter:blur(18px) saturate(1.4);
            border:1px solid rgba(255,255,255,.6); border-radius:30px;
            box-shadow:0 32px 70px rgba(21,36,71,.20), inset 0 1px 0 rgba(255,255,255,.7);
            animation:pop .6s cubic-bezier(.34,1.56,.64,1) both;
        }
        @keyframes pop { from{opacity:0;transform:translateY(20px) scale(.97);} to{opacity:1;transform:none;} }
        .logo {
            width:72px; height:72px; margin:0 auto 22px; border-radius:20px; display:grid; place-items:center;
            color:#fff; box-shadow:0 10px 28px rgba(21,36,71,.18);
            background:linear-gradient(135deg,var(--blue) 0%,var(--violet) 52%,var(--pink) 100%);
            animation:float 4.5s ease-in-out infinite;
        }
        @keyframes float { 0%,100%{transform:translateY(0);} 50%{transform:translateY(-7px);} }
        .logo svg { width:36px; height:36px; }
        .spinner {
            width:58px; height:58px; margin:0 auto 26px; border-radius:50%;
            background:conic-gradient(from 0deg,var(--blue),var(--pink),var(--blue));
            -webkit-mask:radial-gradient(farthest-side,transparent calc(100% - 7px),#000 0);
            mask:radial-gradient(farthest-side,transparent calc(100% - 7px),#000 0);
            animation:spin .9s linear infinite;
        }
        @keyframes spin { to{transform:rotate(1turn);} }
        h1 {
            font-size:1.5rem; font-weight:800; letter-spacing:-.02em; margin-bottom:10px;
            background:linear-gradient(135deg,var(--blue),var(--pink));
            -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent;
        }
        p { font-size:.98rem; line-height:1.55; color:#6b7280; }
        .dots { margin-top:22px; display:flex; gap:8px; justify-content:center; }
        .dots span {
            width:9px; height:9px; border-radius:50%;
            background:linear-gradient(135deg,var(--blue),var(--pink)); opacity:.35;
            animation:blink 1.4s ease-in-out infinite;
        }
        .dots span:nth-child(2){animation-delay:.2s;}
        .dots span:nth-child(3){animation-delay:.4s;}
        @keyframes blink { 0%,100%{opacity:.3;transform:scale(.85);} 50%{opacity:1;transform:scale(1.1);} }
        @media (prefers-color-scheme: dark) {
            body { color:#e8edf4;
                background:
                    radial-gradient(40% 50% at 18% 22%, rgba(28,111,179,.32), transparent 60%),
                    radial-gradient(45% 55% at 85% 18%, rgba(157,30,116,.30), transparent 60%),
                    linear-gradient(135deg,#0d1119 0%,#141019 50%,#190f16 100%);
                background-size:200% 200%; }
            .card { background:rgba(22,26,36,.74); border-color:rgba(255,255,255,.08); }
            p { color:#aab2c2; }
        }
        @media (prefers-reduced-motion: reduce) {
            body,.logo,.card { animation:none !important; }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 3l8 3v6c0 4.5-3.2 7.7-8 9-4.8-1.3-8-4.5-8-9V6l8-3Z"/><path d="m9 12 2 2 4-4"/>
            </svg>
        </div>
        <div class="spinner" role="status" aria-label="<?= esc_attr($title) ?>"></div>
        <h1><?= esc_html($title) ?></h1>
        <p><?= esc_html($sub) ?></p>
        <div class="dots" aria-hidden="true"><span></span><span></span><span></span></div>
    </div>
    <script>
        (function () {
            // IMPORTANTE: usamos wp_json_encode (no esc_js) para la URL. esc_js convierte
            // '&' en '&#038;', y el '#' se interpretaría como fragmento → 'sticpa_go' no
            // llegaría al servidor y se entraría en un BUCLE de la pantalla de carga.
            var go = <?php echo wp_json_encode($goUrl); ?>;
            // Salvaguarda anti-bucle: si por lo que sea ya íbamos con sticpa_go, no insistas.
            if (window.location.search.indexOf('sticpa_go=') !== -1) { return; }
            // Pequeño respiro para que la animación se vea y luego validamos contra el CRM.
            setTimeout(function () { window.location.replace(go); }, 350);
        })();
    </script>
</body>
</html>
    <?php
}

/* ============================================================================
 *  ADMIN: generación masiva, búsqueda, ver token, "entrar como" (impersonar)
 * ========================================================================== */

/** Acción admin-post: generar tokens para todos los contactos sin token. */
add_action('admin_post_sticpa_generate_tokens_bulk', 'sticpa_handle_generate_tokens_bulk');
function sticpa_handle_generate_tokens_bulk()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('No tienes permisos para hacer esto.', 'sticpa'));
    }
    check_admin_referer('sticpa_tokens');

    $module = getDestinationModule();
    if ($module === 'Any') {
        $module = 'Contacts';
    }
    $generated = sticpa_generate_tokens_bulk($module, 200);

    wp_safe_redirect(add_query_arg(array(
        'page' => 'sugar-crm-portal',
        'sticpa_msg' => 'bulk',
        'sticpa_n' => $generated,
    ), admin_url('admin.php')));
    exit;
}

/** Acción admin-post: regenerar el token de un contacto concreto. */
add_action('admin_post_sticpa_regenerate_token', 'sticpa_handle_regenerate_token');
function sticpa_handle_regenerate_token()
{
    if (!current_user_can('manage_options')) {
        wp_die(__('No tienes permisos para hacer esto.', 'sticpa'));
    }
    check_admin_referer('sticpa_tokens');

    $module = sanitize_text_field($_REQUEST['module'] ?? '');
    if (!in_array($module, array('Contacts', 'Accounts'), true)) {
        $module = 'Contacts';
    }
    $contactId = sanitize_text_field($_REQUEST['contact_id'] ?? '');
    if ($contactId) {
        sticpa_set_contact_token($module, $contactId);
    }
    // Volvemos a la ficha del mismo contacto para ver el token nuevo.
    wp_safe_redirect(add_query_arg(array(
        'page' => 'sugar-crm-portal',
        'sticpa_id' => rawurlencode($contactId),
        'sticpa_module' => rawurlencode($module),
    ), admin_url('admin.php')));
    exit;
}

/**
 * Renderiza la ficha de un contacto: nombre, email, token y botones de
 * "entrar como" y "regenerar token". Reutilizada para resultado único y para
 * abrir un contacto concreto desde la lista de resultados.
 */
function sticpa_render_contact_card($contact, $contactModule, $areaUrl)
{
    $nvl = $contact->name_value_list;
    $name = $nvl->name->value ?? '';
    $email = $nvl->email1->value ?? '';
    $username = $nvl->stic_pa_username_c->value ?? '';
    $token = $nvl->ajmcm_pa_token_c->value ?? '';
    if ($token === '') {
        // Si no tiene token todavía, se lo generamos al verlo.
        $token = sticpa_set_contact_token($contactModule, $contact->id);
    }
    $loginUrl = $areaUrl ? add_query_arg('token', $token, $areaUrl) : '';
    ?>
    <table class="form-table">
        <tr><th><?= __('Nombre', 'sticpa'); ?></th><td><?= esc_html($name); ?> <code><?= esc_html($contactModule); ?></code></td></tr>
        <?php if ($username !== '') : ?>
        <tr><th><?= __('Usuario', 'sticpa'); ?></th><td><?= esc_html($username); ?></td></tr>
        <?php endif; ?>
        <tr><th><?= __('Email', 'sticpa'); ?></th><td><?= esc_html($email); ?></td></tr>
        <tr><th><?= __('Token de acceso', 'sticpa'); ?></th><td><code><?= esc_html($token); ?></code></td></tr>
        <?php if ($loginUrl) : ?>
        <tr><th><?= __('Entrar como este usuario', 'sticpa'); ?></th>
            <td><a class="button button-primary" href="<?= esc_url($loginUrl); ?>" target="_blank"><?= __('Abrir área privada como', 'sticpa'); ?> <?= esc_html($name); ?></a></td>
        </tr>
        <?php endif; ?>
    </table>
    <form method="post" action="<?= esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('sticpa_tokens'); ?>
        <input type="hidden" name="action" value="sticpa_regenerate_token">
        <input type="hidden" name="module" value="<?= esc_attr($contactModule); ?>">
        <input type="hidden" name="contact_id" value="<?= esc_attr($contact->id); ?>">
        <?php submit_button(__('Regenerar token (invalida los enlaces antiguos)', 'sticpa'), 'delete'); ?>
    </form>
    <?php
}

/**
 * Renderiza el panel de herramientas de acceso por enlace dentro de la página
 * de ajustes del plugin (generar tokens, buscar usuario, ver token, entrar como).
 */
function sticpa_render_admin_tools()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $areaUrl = get_option('sticpa_scp_area_url');
    $module = getDestinationModule();
    if ($module === 'Any') {
        $module = 'Contacts';
    }
    ?>
    <div class="wrap">
        <hr>
        <h2><?= __('Acceso por enlace (sin contraseña)', 'sticpa'); ?></h2>

        <?php if (isset($_GET['sticpa_msg']) && $_GET['sticpa_msg'] === 'bulk') : ?>
            <div class="updated notice"><p>
                <?= sprintf(__('Se generaron %d tokens de acceso.', 'sticpa'), (int) ($_GET['sticpa_n'] ?? 0)); ?>
                <?= __('Vuelve a ejecutarlo si quedan más contactos pendientes.', 'sticpa'); ?>
            </p></div>
        <?php endif; ?>

        <?php if (empty($areaUrl)) : ?>
            <div class="notice notice-warning"><p>
                <?= __('Configura arriba la «URL del área privada» para poder construir los enlaces de acceso.', 'sticpa'); ?>
            </p></div>
        <?php endif; ?>

        <h3><?= __('Generar tokens en lote', 'sticpa'); ?></h3>
        <p class="description"><?= __('Crea un token de acceso para cada contacto que aún no tenga uno (en lotes de 200).', 'sticpa'); ?></p>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('sticpa_tokens'); ?>
            <input type="hidden" name="action" value="sticpa_generate_tokens_bulk">
            <?php submit_button(__('Generar tokens que falten', 'sticpa'), 'secondary'); ?>
        </form>

        <h3><?= __('Buscar usuario / entrar como', 'sticpa'); ?></h3>
        <form method="get" action="<?= esc_url(admin_url('admin.php')); ?>">
            <input type="hidden" name="page" value="sugar-crm-portal">
            <input type="text" name="sticpa_q" class="regular-text"
                   placeholder="<?= esc_attr__('Nombre, apellidos o usuario', 'sticpa'); ?>"
                   value="<?= esc_attr(stripslashes($_GET['sticpa_q'] ?? '')); ?>">
            <?php submit_button(__('Buscar', 'sticpa'), 'secondary', '', false); ?>
        </form>

        <?php
        if (!class_exists('SugarRestApiCall')) {
            echo '</div>';
            return;
        }

        // 1) Abrir la ficha de un contacto concreto (desde la lista de resultados
        //    o tras regenerar el token).
        $viewId = isset($_GET['sticpa_id']) ? sanitize_text_field(stripslashes($_GET['sticpa_id'])) : '';
        $viewModule = sanitize_text_field(stripslashes($_GET['sticpa_module'] ?? ''));
        if (!in_array($viewModule, array('Contacts', 'Accounts'), true)) {
            $viewModule = $module;
        }
        if ($viewId !== '') {
            $fields = array('id', 'name', 'stic_pa_username_c', 'ajmcm_pa_token_c', 'email1');
            $detail = SugarRestApiCall::getObjSCP()->getRecordDetail($viewId, $viewModule, $fields);
            if (isset($detail->entry_list[0]) && $detail->entry_list[0] != null) {
                sticpa_render_contact_card($detail->entry_list[0], $viewModule, $areaUrl);
            } else {
                echo '<p><em>' . __('No se encontró ese contacto.', 'sticpa') . '</em></p>';
            }
            echo '</div>';
            return;
        }

        // 2) Búsqueda por texto libre (nombre, apellidos o usuario).
        $term = isset($_GET['sticpa_q']) ? sanitize_text_field(stripslashes($_GET['sticpa_q'])) : '';
        if ($term !== '') {
            $matches = array();
            foreach (sticpa_modules_to_try() as $m) {
                $rows = SugarRestApiCall::getObjSCP()->searchContacts($term, $m, 25);
                foreach ($rows as $row) {
                    $matches[] = array('module' => $m, 'row' => $row);
                }
            }

            if (empty($matches)) {
                echo '<p><em>' . __('No se encontró ningún usuario con ese nombre, apellidos o usuario.', 'sticpa') . '</em></p>';
            } elseif (count($matches) === 1) {
                sticpa_render_contact_card($matches[0]['row'], $matches[0]['module'], $areaUrl);
            } else {
                ?>
                <p class="description"><?= sprintf(__('%d resultados. Elige uno para ver su token o entrar como ese usuario.', 'sticpa'), count($matches)); ?></p>
                <table class="widefat striped" style="max-width:760px">
                    <thead><tr>
                        <th><?= __('Nombre', 'sticpa'); ?></th>
                        <th><?= __('Usuario', 'sticpa'); ?></th>
                        <th><?= __('Email', 'sticpa'); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($matches as $match) :
                        $row = $match['row'];
                        $nvl = $row->name_value_list;
                        $viewLink = add_query_arg(array(
                            'page' => 'sugar-crm-portal',
                            'sticpa_id' => rawurlencode($row->id),
                            'sticpa_module' => rawurlencode($match['module']),
                        ), admin_url('admin.php'));
                        ?>
                        <tr>
                            <td><?= esc_html($nvl->name->value ?? ''); ?> <code><?= esc_html($match['module']); ?></code></td>
                            <td><?= esc_html($nvl->stic_pa_username_c->value ?? ''); ?></td>
                            <td><?= esc_html($nvl->email1->value ?? ''); ?></td>
                            <td><a class="button button-secondary" href="<?= esc_url($viewLink); ?>"><?= __('Ver / entrar', 'sticpa'); ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php
            }
        }
        ?>
    </div>
    <?php
}
