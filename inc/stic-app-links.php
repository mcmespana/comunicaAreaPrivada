<?php

/**
 * ============================================================================
 *  ENLACES QUE ABREN LA APP MCM (universal links / app links)
 * ----------------------------------------------------------------------------
 *  Objetivo: que el botón "Acceder a mi área privada" de los correos abra la
 *  **app MCM** si la persona la tiene instalada, y el navegador si no. Sin
 *  pedirle que elija nada y sin duplicar el enlace.
 *
 *  Cómo funciona, de fuera a dentro:
 *
 *  1) Los correos NO enlazan directamente al área privada, sino a una ruta
 *     puente de este mismo dominio:
 *
 *         https://comunica.…/app/acceso?acceso_magico=XXXX
 *
 *  2) Ese dominio está declarado en la app como universal link (iOS) y app link
 *     (Android). Para que iOS/Android se lo crean hay que servir dos ficheros
 *     de verificación en `/.well-known/` — los sirve este archivo, porque el
 *     área vive en WordPress y no siempre hay acceso al directorio del hosting.
 *
 *  3) Con la app instalada, el sistema **intercepta el enlace antes de que
 *     llegue aquí** y abre la app, que carga el área privada en su WebView con
 *     el mismo token. La petición web ni se hace.
 *
 *  4) Sin la app (o si el cliente de correo rompe el universal link), la
 *     petición sí llega: `sticpa_app_link_bridge()` redirige al área privada de
 *     siempre con el token intacto, y se entra por web como hasta ahora.
 *
 *  Se reclama SOLO `/app/acceso`, no el área entera: así ningún otro enlace del
 *  portal (uno que alguien comparta por WhatsApp, por ejemplo) se abre en la app
 *  por sorpresa.
 *
 *  ⚠️ El fichero de Android necesita la huella SHA-256 del certificado de firma
 *  de la app. Se configura en Ajustes → SinergiaCRM Private Area. Sin ella,
 *  Android no verifica el dominio y los enlaces seguirán abriendo el navegador
 *  (en iOS no hace falta nada equivalente: basta con el Team ID + bundle ID).
 * ============================================================================
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Ruta puente que reclaman iOS y Android. Si cambia, hay que cambiarla también en la app. */
define('STICPA_APP_LINK_PATH', '/app/acceso');

/** App ID de iOS: Team ID + bundle identifier. */
define('STICPA_IOS_APP_ID', '5P53S6QB23.com.familiaconsolacion.mcmapp');

/** Nombre del paquete de Android. */
define('STICPA_ANDROID_PACKAGE', 'com.mcmespana.mcmapp');

/**
 * Parámetros de acceso que la ruta puente sabe reenviar. Cualquier otro se
 * descarta: el puente no es un redirector abierto.
 */
function sticpa_app_link_params()
{
    return array('acceso_magico', 'token');
}

/** Ruta pedida, sin query ni barra final. */
function sticpa_current_path()
{
    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path)) {
        return '';
    }
    return rtrim($path, '/');
}

/**
 * Convierte un enlace del área privada en su equivalente "abre la app":
 *
 *   https://…/area-privada/?acceso_magico=XXX  →  https://…/app/acceso?acceso_magico=XXX
 *
 * Solo se conservan los parámetros de acceso; el resto (tema, app=1…) los pone
 * la app o el redirect, según por dónde se acabe entrando.
 *
 * Si el enlace no lleva ningún parámetro de acceso se devuelve tal cual: no hay
 * nada que llevar a la app.
 */
function sticpa_app_link_url($url)
{
    $query = parse_url($url, PHP_URL_QUERY);
    if (empty($query)) {
        return $url;
    }
    parse_str($query, $params);

    $keep = array();
    foreach (sticpa_app_link_params() as $key) {
        if (!empty($params[$key])) {
            $keep[$key] = $params[$key];
        }
    }
    if (empty($keep)) {
        return $url;
    }

    return home_url(STICPA_APP_LINK_PATH) . '?' . http_build_query($keep);
}

/**
 * Ruta puente. Solo se ejecuta cuando el sistema operativo NO ha abierto la app
 * (no está instalada, es un ordenador, o el cliente de correo ha envuelto el
 * enlace en un redirector y se ha perdido el universal link).
 *
 * Redirige al área privada conservando el token, que es quien de verdad
 * autentica: aquí no se valida nada, de eso ya se encarga `stic-magic-login.php`
 * en el destino.
 */
add_action('init', 'sticpa_app_link_bridge', 0);
function sticpa_app_link_bridge()
{
    if (is_admin() || sticpa_current_path() !== rtrim(STICPA_APP_LINK_PATH, '/')) {
        return;
    }

    $areaUrl = get_option('sticpa_scp_area_url');
    if (empty($areaUrl)) {
        $areaUrl = home_url('/');
    }

    $args = array();
    foreach (sticpa_app_link_params() as $key) {
        if (!empty($_GET[$key])) {
            // El token viaja tal cual: es base64url/hex, sin nada que sanear más
            // allá de quitar lo que no pertenece a ese alfabeto.
            $args[$key] = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $_GET[$key]);
        }
    }

    $destination = empty($args) ? $areaUrl : add_query_arg($args, $areaUrl);

    // `wp_safe_redirect`: el destino es siempre una URL propia (el área privada
    // configurada en ajustes), así que la lista blanca de hosts nos vale.
    wp_safe_redirect($destination, 302);
    exit;
}

/* ============================================================================
 *  Ficheros de verificación de dominio (/.well-known/…)
 * ========================================================================== */

/** Devuelve JSON crudo y termina. Sin caché: estos ficheros cambian poco pero mal. */
function sticpa_serve_json($data)
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
    }
    echo wp_json_encode($data);
    exit;
}

add_action('init', 'sticpa_serve_well_known', 0);
function sticpa_serve_well_known()
{
    $path = sticpa_current_path();

    // ── iOS ──────────────────────────────────────────────────────────────────
    // Apple exige HTTPS, sin redirecciones y sin extensión en el nombre. Cachea
    // el fichero de forma agresiva: tras cambiarlo, la primera apertura tarda.
    if ($path === '/.well-known/apple-app-site-association' || $path === '/apple-app-site-association') {
        sticpa_serve_json(array(
            'applinks' => array(
                'details' => array(
                    array(
                        'appIDs' => array(STICPA_IOS_APP_ID),
                        'components' => array(
                            array(
                                '/' => STICPA_APP_LINK_PATH,
                                'comment' => 'Acceso al area privada desde el correo',
                            ),
                            array(
                                '/' => STICPA_APP_LINK_PATH . '*',
                                'comment' => 'Acceso al area privada desde el correo (con token)',
                            ),
                        ),
                    ),
                ),
            ),
        ));
    }

    // ── Android ──────────────────────────────────────────────────────────────
    if ($path === '/.well-known/assetlinks.json') {
        $fingerprints = sticpa_android_fingerprints();
        if (empty($fingerprints)) {
            // Mejor una lista vacía que una huella inventada: Android
            // simplemente no verificará el dominio.
            sticpa_serve_json(array());
        }
        sticpa_serve_json(array(
            array(
                'relation' => array('delegate_permission/common.handle_all_urls'),
                'target' => array(
                    'namespace' => 'android_app',
                    'package_name' => STICPA_ANDROID_PACKAGE,
                    'sha256_cert_fingerprints' => $fingerprints,
                ),
            ),
        ));
    }
}

/**
 * Huellas SHA-256 del certificado de firma de la app Android.
 *
 * Se configuran en Ajustes → SinergiaCRM Private Area (una por línea o
 * separadas por comas). Se sacan de Play Console → Setup → App integrity →
 * App signing → "SHA-256 certificate fingerprint".
 */
function sticpa_android_fingerprints()
{
    $raw = (string) get_option('sticpa_android_sha256', '');
    $out = array();
    foreach (preg_split('/[\s,]+/', $raw) as $item) {
        $item = strtoupper(trim($item));
        // Formato válido: 32 pares hexadecimales separados por ':'.
        if (preg_match('/^([A-F0-9]{2}:){31}[A-F0-9]{2}$/', $item)) {
            $out[] = $item;
        }
    }
    return $out;
}
