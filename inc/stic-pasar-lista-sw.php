<?php
/**
 * PASAR LISTA — el service worker, servido desde la raíz del sitio.
 * ----------------------------------------------------------------------------
 * EL PROBLEMA. Un service worker solo puede controlar rutas que estén DENTRO de
 * la carpeta desde la que se sirve. El archivo vive en la carpeta del plugin
 * (`/wp-content/plugins/…/js/`), y el área privada está en otra parte del sitio
 * (`/area-privada/` o donde el usuario haya puesto el shortcode), así que
 * servirlo desde su carpeta real no serviría de nada.
 *
 * LA SOLUCIÓN, sin tocar las reglas de reescritura de WordPress: se sirve en
 * `/?sticpa_sw=1`. El alcance se calcula con la RUTA de la url, y la ruta de eso
 * es `/`, así que el alcance máximo es todo el sitio. Un parámetro no cambia la
 * ruta. Sin reglas nuevas, sin vaciar la caché de enlaces permanentes al
 * activar el plugin, y sin nada que se pueda quedar a medias en una migración.
 *
 * ACTIVADO. Un service worker manda sobre TODAS las peticiones del sitio, y este
 * plugin vive en instalaciones de WordPress con sus cachés, sus CDN y sus
 * plugins, así que si alguna vez se pelea con uno de ellos, se apaga sin tocar
 * código:
 *
 *     add_filter('sticpa_pl_offline_enabled', '__return_false');
 *
 * Ojo: esto NO es lo que hace que se pueda marcar sin cobertura. El borrador y
 * la cola de envíos de
 * js/stic-pasar-lista.js funcionan igual con esto apagado y cubren el caso real
 * de un sábado: que se caiga la cobertura mientras se marca. El service worker
 * añade lo otro, poder ABRIR la pantalla ya sin cobertura.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** ¿Está encendido el modo sin conexión completo? Apagado por defecto. */
function sticpa_pl_offline_enabled()
{
    return (bool) apply_filters('sticpa_pl_offline_enabled', true);
}

/**
 * Sirve el archivo del service worker en `/?sticpa_sw=1`.
 *
 * Se engancha en `init` y no en `template_redirect` para responder antes de que
 * WordPress monte la consulta y el tema: es un archivo estático y no necesita
 * nada de eso.
 */
function sticpa_pl_serve_sw()
{
    if (empty($_GET['sticpa_sw'])) {
        return;
    }
    if (!sticpa_pl_offline_enabled()) {
        status_header(404);
        exit;
    }

    $file = plugin_dir_path(dirname(__FILE__)) . 'js/stic-pasar-lista-sw.js';
    if (!file_exists($file)) {
        status_header(404);
        exit;
    }

    // La cabecera que autoriza el alcance amplio. Sin ella, el navegador rechaza
    // el registro con `scope: '/'` aunque la ruta sea la raíz.
    header('Service-Worker-Allowed: /');
    header('Content-Type: application/javascript; charset=utf-8');
    // Un service worker cacheado es un service worker que no se puede
    // actualizar. El navegador ya lo revisa solo; aquí se le quita la
    // tentación a las cachés intermedias.
    header('Cache-Control: no-cache, must-revalidate, max-age=0');

    readfile($file);
    exit;
}
add_action('init', 'sticpa_pl_serve_sw', 1);

/**
 * El registro, para incrustar en las pantallas de Pasar Lista.
 *
 * Va con el `user` dentro: la caché de páginas se nombra con él, de modo que si
 * en el mismo móvil entra otra persona no puede leer las pantallas de la
 * anterior. Es un hash, no el id: no hace falta publicar identificadores del
 * CRM en el código de la página.
 */
function sticpa_pl_sw_register_html()
{
    if (!sticpa_pl_offline_enabled()) {
        return '';
    }
    $userId = isset($_SESSION['scp_user_id']) ? (string) $_SESSION['scp_user_id'] : '';
    if ($userId === '') {
        return '';
    }
    $userKey = substr(md5('sticpa_pl|' . $userId), 0, 16);

    $swUrl = esc_url(home_url('/?sticpa_sw=1'));
    $key = esc_js($userKey);

    return "<script>\n"
        . "(function () {\n"
        . "    if (!('serviceWorker' in navigator)) { return; }\n"
        . "    navigator.serviceWorker.register('{$swUrl}', { scope: '/' }).then(function (reg) {\n"
        . "        function tell(worker) {\n"
        . "            if (worker) { worker.postMessage({ type: 'sticpa:user', key: '{$key}' }); }\n"
        . "        }\n"
        . "        tell(reg.active);\n"
        . "        // Recién instalado, `active` aún no existe: se avisa al tomar el control.\n"
        . "        navigator.serviceWorker.ready.then(function (r) { tell(r.active); });\n"
        . "    }).catch(function () { /* sin modo sin conexión, la pantalla funciona igual */ });\n"
        . "\n"
        . "    // Al cerrar sesión se borra TODO lo guardado: son pantallas con\n"
        . "    // nombres, teléfonos y datos de salud de menores.\n"
        . "    document.addEventListener('click', function (ev) {\n"
        . "        var link = ev.target.closest && ev.target.closest('a[href]');\n"
        . "        if (!link || link.href.indexOf('logout') === -1) { return; }\n"
        . "        if (navigator.serviceWorker.controller) {\n"
        . "            navigator.serviceWorker.controller.postMessage({ type: 'sticpa:logout' });\n"
        . "        }\n"
        . "        try {\n"
        . "            Object.keys(window.localStorage).forEach(function (k) {\n"
        . "                if (k.indexOf('sticpa_pl_') === 0) { window.localStorage.removeItem(k); }\n"
        . "            });\n"
        . "        } catch (e) { /* nada */ }\n"
        . "    }, true);\n"
        . "}());\n"
        . "</script>";
}
