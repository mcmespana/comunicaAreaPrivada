<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ============================================================================
 *  MODO APP (?app=1) — para la WebView de la app (React Native, etc.)
 * ----------------------------------------------------------------------------
 * Oculta el header/footer/admin-bar del tema de WordPress para que la WebView
 * muestre SOLO el área privada. Se activa con ?app=1 en cualquier URL del área
 * y se recuerda en una cookie (los enlaces internos no llevan el parámetro);
 * se desactiva con ?app=0. Ejemplo de URL de arranque de la app:
 *   https://…/area-privada/?token=XXXX&app=1
 */
// Prioridad 1: por delante del login por enlace mágico (init/2), que ya
// necesita saber si estamos en la app para resolver el tema de su pantalla puente.
add_action('init', 'sticpa_app_mode_boot', 1);
function sticpa_app_mode_boot()
{
    if (!isset($_GET['app'])) {
        return;
    }
    $on = $_GET['app'] !== '0';
    setcookie('sticpa_app', $on ? '1' : '', $on ? time() + 30 * DAY_IN_SECONDS : time() - 3600, '/');
    $_COOKIE['sticpa_app'] = $on ? '1' : ''; // efectivo ya en esta petición
}

/** ¿Estamos dentro de la app (WebView)? */
function sticpa_is_app_mode()
{
    return !empty($_COOKIE['sticpa_app']);
}

/**
 * ============================================================================
 *  TEMA CLARO / OSCURO (plan 016, 2ª vuelta) — AUTOMÁTICO por defecto
 * ----------------------------------------------------------------------------
 * Dos conceptos distintos, no los confundas:
 *
 *   · PREFERENCIA (`data-stic-theme`): 'auto' | 'light' | 'dark'. Qué ha pedido
 *     quien manda. 'auto' = "lo que diga el dispositivo".
 *   · ESQUEMA RESUELTO (`data-stic-scheme`): 'light' | 'dark'. Lo que se pinta.
 *     Es lo ÚNICO que mira el CSS (§44). Con preferencia explícita lo resuelve
 *     ya el servidor (cero parpadeo); con 'auto' lo resuelve el script inline
 *     de `<head>` con `matchMedia` antes de pintar (ver sticpa_theme_boot_js).
 *
 * Quién manda, por orden:
 *
 *   1. **La app MCM** cuando estamos en su WebView (`?app=1`). La app tiene su
 *      propio selector Sistema/Claro/Oscuro y manda el resultado por `?theme=`
 *      (primera carga) y por la cookie `mcm_theme` (el resto). Dos interruptores
 *      compitiendo sería peor que ninguno: en modo app NO pintamos el nuestro.
 *      Contrato completo en docs/comunica/CONTRATO-APP-WEBVIEW.md.
 *   2. **La cookie propia `sticpa_theme`** ('light'|'dark'), que escribe el
 *      control discreto de "Apariencia" del pie del área (solo en navegador).
 *   3. **El dispositivo** (`prefers-color-scheme`) — el caso por defecto.
 */

/** Preferencia de tema del área: 'auto' | 'light' | 'dark'. */
function sticpa_theme_pref()
{
    if (sticpa_is_app_mode()) {
        // NUNCA confiar en el valor crudo: solo se aceptan los dos literales.
        $fromApp = isset($_GET['theme']) ? $_GET['theme'] : (isset($_COOKIE['mcm_theme']) ? $_COOKIE['mcm_theme'] : '');
        return ($fromApp === 'dark' || $fromApp === 'light') ? $fromApp : 'auto';
    }
    $own = isset($_COOKIE['sticpa_theme']) ? $_COOKIE['sticpa_theme'] : '';
    return ($own === 'dark' || $own === 'light') ? $own : 'auto';
}

/** ¿Se muestra el control de apariencia? No en la app (ella ya tiene el suyo). */
function sticpa_theme_switch_enabled()
{
    return !sticpa_is_app_mode();
}

/**
 * Atributos de tema para los contenedores del área (`.stic-container`,
 * `.stic-auth-shell`). El CSS puede engancharse tanto aquí como en `<html>`,
 * así que el tema oscuro funciona incluso si el tema de WordPress no imprime
 * los `language_attributes()` con nuestro filtro.
 */
function sticpa_theme_attr()
{
    $pref = sticpa_theme_pref();
    $attr = " data-stic-theme='" . esc_attr($pref) . "'";
    if ($pref !== 'auto') {
        $attr .= " data-stic-scheme='" . esc_attr($pref) . "'";
    }
    return $attr;
}

/**
 * Los mismos atributos en `<html>`: hacen falta para los elementos que se
 * anclan a `<body>` (overlay de carga, tooltips, modales, desplegable de
 * Selectize) y para que el script de arranque tenga dónde escribir.
 */
add_filter('language_attributes', 'sticpa_theme_html_attrs');
function sticpa_theme_html_attrs($output)
{
    if (!sticpa_has_area_shortcode()) {
        return $output;
    }
    return $output . sticpa_theme_attr();
}

/** ¿La página actual contiene el shortcode del área privada? */
function sticpa_has_area_shortcode()
{
    if (!is_singular()) {
        return false;
    }
    $post = get_post();
    return $post instanceof WP_Post && has_shortcode($post->post_content, 'sinergiacrm-private-area');
}

/**
 * Script de arranque del tema, inline y lo más arriba posible de `<head>`:
 * resuelve 'auto' ANTES del primer pintado (sin flash blanco) y deja el área
 * escuchando cambios del dispositivo y de la app.
 *
 * Sin JavaScript, 'auto' se queda en claro — exactamente el comportamiento que
 * el área tenía antes de este plan, así que no hay regresión.
 */
add_action('wp_head', 'sticpa_theme_boot_js', 1);
function sticpa_theme_boot_js()
{
    if (!sticpa_has_area_shortcode()) {
        return;
    }
    $js = <<<'JS'
(function () {
    try {
        var r = document.documentElement;
        var app = STICPA_APP_MODE;
        var phpPref = STICPA_PREF;
        var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

        // La PREFERENCIA vive en <html data-stic-theme>. La pinta PHP y la
        // reescribe el control del pie, así que leerla de ahí evita tener dos
        // copias del mismo estado. Si el tema de WordPress no imprime
        // language_attributes(), el atributo no existe y usamos la de PHP.
        function pref() {
            var p = r.getAttribute('data-stic-theme');
            return (p === 'dark' || p === 'light' || p === 'auto') ? p : phpPref;
        }
        // Dentro de la app, <html> lleva data-mcm-theme (la WebView lo inyecta en
        // cada carga y también al cambiar el tema en caliente): es la fuente más
        // fresca y manda sobre todo lo demás.
        function appTheme() {
            var t = r.getAttribute('data-mcm-theme');
            return (t === 'dark' || t === 'light') ? t : '';
        }
        function active() {
            return (app && appTheme()) || pref();
        }
        function paint() {
            var p = active();
            var scheme = (p === 'dark' || p === 'light') ? p : ((mq && mq.matches) ? 'dark' : 'light');
            r.setAttribute('data-stic-theme', p);
            r.setAttribute('data-stic-scheme', scheme);
            // Los contenedores también, para que el CSS funcione aunque el tema
            // de WordPress no imprima nuestros atributos en <html>.
            var nodes = document.querySelectorAll('.stic-container, .stic-auth-shell');
            for (var i = 0; i < nodes.length; i++) {
                nodes[i].setAttribute('data-stic-scheme', scheme);
            }
            var btns = document.querySelectorAll('[data-stic-theme-set]');
            for (var j = 0; j < btns.length; j++) {
                btns[j].setAttribute('aria-pressed', btns[j].getAttribute('data-stic-theme-set') === p ? 'true' : 'false');
            }
        }

        paint();
        window.sticpaPaintTheme = paint;
        // El dispositivo cambia de apariencia (ajustes, modo noche automático).
        if (mq) {
            var onChange = function () { paint(); };
            if (mq.addEventListener) { mq.addEventListener('change', onChange); }
            else if (mq.addListener) { mq.addListener(onChange); }
        }
        // La app cambia el tema en caliente y NO recarga (perdería el formulario).
        if (app && window.MutationObserver) {
            new MutationObserver(paint).observe(r, { attributes: true, attributeFilter: ['data-mcm-theme'] });
        }
        document.addEventListener('DOMContentLoaded', paint);
    } catch (e) { /* sin tema dinámico: se queda como lo pintó el servidor */ }
})();
JS;
    $js = str_replace(
        array('STICPA_APP_MODE', 'STICPA_PREF'),
        array(sticpa_is_app_mode() ? 'true' : 'false', wp_json_encode(sticpa_theme_pref())),
        $js
    );
    echo "<script>" . $js . "</script>\n";
}

/**
 * Control discreto de apariencia para el pie del área (Auto / Claro / Oscuro).
 * NO va en la barra de navegación a propósito: ahí ya compiten identidad,
 * selector de participante, salir y menú. Aquí abajo no le quita sitio a nada
 * y es donde el resto de la web pone los ajustes de apariencia.
 * En modo app devuelve '' (manda el selector de la propia app MCM).
 */
function sticpa_appearance_switch_html()
{
    if (!sticpa_theme_switch_enabled()) {
        return '';
    }
    $pref = sticpa_theme_pref();
    $options = array(
        'auto'  => array(
            'label' => __('Auto', 'sticpa'),
            'title' => __('Seguir la apariencia del dispositivo', 'sticpa'),
            'icon'  => "<rect x='2' y='4' width='20' height='13' rx='2'/><path d='M8 21h8M12 17v4'/>",
        ),
        'light' => array(
            'label' => __('Claro', 'sticpa'),
            'title' => __('Usar siempre el tema claro', 'sticpa'),
            'icon'  => "<circle cx='12' cy='12' r='4.2'/><path d='M12 2v2.4M12 19.6V22M2 12h2.4M19.6 12H22M4.9 4.9l1.7 1.7M17.4 17.4l1.7 1.7M19.1 4.9l-1.7 1.7M6.6 17.4l-1.7 1.7'/>",
        ),
        'dark'  => array(
            'label' => __('Oscuro', 'sticpa'),
            'title' => __('Usar siempre el tema oscuro', 'sticpa'),
            'icon'  => "<path d='M20.5 14.6A8.6 8.6 0 0 1 9.4 3.5a8.6 8.6 0 1 0 11.1 11.1Z'/>",
        ),
    );

    $html = "<div class='stic-appearance'>";
    $html .= "<span class='stic-appearance-label' id='stic-appearance-label'>" . esc_html__('Apariencia', 'sticpa') . "</span>";
    $html .= "<div class='stic-appearance-seg' role='group' aria-labelledby='stic-appearance-label'>";
    foreach ($options as $value => $opt) {
        $pressed = ($value === $pref) ? 'true' : 'false';
        $html .= "<button type='button' class='stic-appearance-btn' data-stic-theme-set='" . esc_attr($value) . "'"
            . " aria-pressed='" . $pressed . "' title='" . esc_attr($opt['title']) . "'>"
            . "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'>" . $opt['icon'] . "</svg>"
            . "<span>" . esc_html($opt['label']) . "</span></button>";
    }
    $html .= "</div></div>";
    return $html;
}

// Clase en el body + CSS que esconde el "chrome" del tema alrededor del área.
add_filter('body_class', function ($classes) {
    if (sticpa_is_app_mode()) {
        $classes[] = 'sticpa-app-mode';
    }
    return $classes;
});

function sticpa_app_mode_css()
{
    // Selectores genéricos de headers/footers de los temas habituales
    // (clásicos, Elementor, bloques). Si el tema usa otro wrapper, añádelo aquí.
    return '
    body.sticpa-app-mode :is(
        header, .site-header, #masthead, #site-header,
        .elementor-location-header, header.wp-block-template-part,
        footer, .site-footer, #colophon,
        .elementor-location-footer, footer.wp-block-template-part,
        #wpadminbar
    ) { display: none !important; }
    body.sticpa-app-mode { padding-top: 0 !important; margin-top: 0 !important; }
    body.sticpa-app-mode.admin-bar { margin-top: 0 !important; } /* hueco del admin bar */
    ';
}
