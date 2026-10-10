<?php
/**
 * ============================================================================
 *  VELOCIDAD DEL ÁREA — lo que el área NO pide (plan 042)
 * ============================================================================
 *
 * El área son recargas completas: todo lo que viaja con la página se paga en
 * CADA toque. Aquí se aparta, solo en las páginas del área, lo que otros meten
 * en todo el sitio y el área no usa.
 *
 * ---- VEL-1: la capa de los formularios públicos ---------------------------
 * `crm_comunica_estilos.css` y `crm_comunica_script.js` (handles
 * `crm-comunica-estilos` y `crm-comunica-script`) son de los formularios
 * públicos: los despliega `comunicaFormularios` en la raíz del hosting y algo
 * de fuera de este repo los encola en TODAS las páginas. El área no usa ni una
 * clase suya, y costaban en cada pantalla 48 KB de CSS que bloquean el primer
 * pintado y 81 KB de JS (gzip): +140 ms de hilo principal en un móvil medio,
 * medido el 09/10/2026.
 *
 * Y rompían algo: su `html, body { overflow-x: hidden }` convierte el <body> en
 * contenedor de scroll, y con eso NINGÚN `position: sticky` del área se pegaba
 * («Guardar lista» de Pasar Lista, el buscador del árbol, la botonera de los
 * formularios). Sin la hoja, el `body { overflow-x: hidden }` del propio tema
 * (Astra) sigue impidiendo la barra horizontal —se propaga al viewport porque
 * <html> no lleva overflow— y el sticky vuelve a funcionar. Comprobado con el
 * CSS de Astra de producción en el render de marcar a 375 px.
 *
 * Lo que dejaba de propina, y por qué no hace falta reponerlo:
 *   · sus tokens de :root (mismos nombres que los del área, cargados DESPUÉS)
 *     pisaban cuatro en claro: --success-dark/-soft/-border y --warning-dark.
 *     Vuelven los del área (§1 de custom-style.css), que son los diseñados y
 *     más oscuros (mejor contraste).
 *   · el gris de <html> (#f1f5f9). La página ya era blanca (el <body> lo pinta
 *     `.ast-plain-container`); solo se veía al estirar el scroll, y en OSCURO
 *     salía una franja gris clara. Ahora el lienzo toma el fondo del <body>.
 *   · su @font-face de Inter (otra copia, en la raíz del hosting). El área ya
 *     trae la suya, precargada.
 *   · su JS, que en el área no hacía nada... salvo en «Pagar con tarjeta»: ese
 *     formulario lleva `id="WebToLeadForm"` (lo pide el formulario web de
 *     SinergiaCRM) y el JS de los formularios arrancaba sobre él su motor de
 *     ALTA (validación, borradores en localStorage, estado de envío). Sin él,
 *     al formulario de pago solo lo mueve su propio script.
 *
 * El arreglo de raíz es que `comunicaFormularios` solo encole su capa en las
 * páginas de formularios; esto es el cinturón.
 *
 * ---- VEL-7: las otras tres Inter (y Google Fonts) --------------------------
 * El área solo usa Inter, autoalojada y precargada (design.md §5: «nunca se
 * carga desde Google Fonts»). Pero en su página entraban además:
 *   · `elementor-gf-local-inter`: 126 @font-face «locales» de Elementor que
 *     apuntan TODOS al dominio provisional de Hostinger
 *     (steelblue-mallard-178509.hostingersite.com). Se declaran después que la
 *     del área y ganaban en los pesos 600 y 700: la negrita del área se bajaba
 *     de ese dominio. Fuera SIEMPRE: el kit de Elementor pide la familia
 *     'Inter' para sus títulos y la del área (100-900) la cubre entera.
 *   · `astra-google-fonts`: Inter 400/600 y Plus Jakarta Sans 600 desde
 *     fonts.googleapis.com, una hoja en otro origen que bloquea el pintado
 *     (más su preconnect a fonts.gstatic.com). Fuera SIEMPRE: la Inter ya la
 *     pone el área, y Plus Jakarta solo la usarían `.site-title` (la cabecera
 *     es el logo, una imagen) y los h1-h6, que el kit de Elementor pasa a
 *     'Inter'. Con ella se van su dns-prefetch y sus preconnect.
 *   · `elementor-gf-local-lato`: Lato, del mismo dominio provisional. Es la
 *     letra del kit de Elementor para el texto de la web, o sea, del PIE de
 *     página. Solo en modo app (allí cabecera y pie no se ven); en el
 *     navegador el pie sigue en Lato.
 * El arreglo de raíz (y el de la web pública) es de quien administra
 * WordPress: en Elementor, Herramientas → Regenerar archivos, para que sus
 * fuentes locales apunten al dominio de verdad.
 *
 * POR QUÉ TRES ENGANCHES: quien encola puede hacerlo tarde. `wp_enqueue_scripts`
 * con prioridad 100 llega después de casi todo; `wp_print_styles` (justo antes
 * de imprimir las hojas del <head>) recoge lo que se encola en `wp_head`, como
 * las fuentes de Elementor; y `wp_print_footer_scripts` a prioridad 1, lo que
 * se encola para el pie. Desencolar algo que no está encolado no hace nada.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Hojas y scripts ajenos que se apartan de las páginas del área.
 *
 * @param bool $appMode dentro de MCM App (sin cabecera ni pie del tema).
 * @return array{styles: string[], scripts: string[]}
 */
function sticpa_area_foreign_assets($appMode = false)
{
    $styles = array(
        // VEL-1: la capa de los formularios públicos.
        'crm-comunica-estilos',
        // VEL-7: las Inter que no son la del área.
        'elementor-gf-local-inter',
        'astra-google-fonts',
    );
    if ($appMode) {
        // VEL-7: Lato solo la usa el pie de la web, que en la app no se ve.
        $styles[] = 'elementor-gf-local-lato';
    }
    return array(
        'styles'  => $styles,
        'scripts' => array('crm-comunica-script'),
    );
}

function sticpa_area_dequeue_foreign_assets()
{
    if (!function_exists('sticpa_queried_page_has_area_shortcode') || !sticpa_queried_page_has_area_shortcode()) {
        return;
    }
    $assets = sticpa_area_foreign_assets(function_exists('sticpa_is_app_mode') && sticpa_is_app_mode());
    foreach ($assets['styles'] as $handle) {
        wp_dequeue_style($handle);
    }
    foreach ($assets['scripts'] as $handle) {
        wp_dequeue_script($handle);
    }
}
add_action('wp_enqueue_scripts', 'sticpa_area_dequeue_foreign_assets', 100);
add_action('wp_print_styles', 'sticpa_area_dequeue_foreign_assets', 100);
add_action('wp_print_footer_scripts', 'sticpa_area_dequeue_foreign_assets', 1);

/**
 * VEL-7: sin Google Fonts no hacen falta su dns-prefetch ni sus preconnect
 * (los pone el tema por su cuenta, aunque la hoja ya no se cargue). Cada uno
 * es una resolución DNS y un saludo TLS de más en la primera carga.
 *
 * @param array $urls entradas de `wp_resource_hints`: una URL o un array con 'href'.
 */
function sticpa_strip_google_font_hints($urls)
{
    $out = array();
    foreach ((array) $urls as $url) {
        $href = is_array($url) ? (string) ($url['href'] ?? '') : (string) $url;
        if (preg_match('#(^|//|\.)fonts\.(googleapis|gstatic)\.com#i', $href)) {
            continue;
        }
        $out[] = $url;
    }
    return $out;
}

function sticpa_area_resource_hints($urls, $relation = '')
{
    if (!function_exists('sticpa_queried_page_has_area_shortcode') || !sticpa_queried_page_has_area_shortcode()) {
        return $urls;
    }
    return sticpa_strip_google_font_hints($urls);
}
add_filter('wp_resource_hints', 'sticpa_area_resource_hints', 100, 2);
