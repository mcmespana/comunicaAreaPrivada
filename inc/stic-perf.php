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
 * @return array{styles: string[], scripts: string[]}
 */
function sticpa_area_foreign_assets()
{
    return array(
        // VEL-1: la capa de los formularios públicos.
        'styles'  => array('crm-comunica-estilos'),
        'scripts' => array('crm-comunica-script'),
    );
}

function sticpa_area_dequeue_foreign_assets()
{
    if (!function_exists('sticpa_queried_page_has_area_shortcode') || !sticpa_queried_page_has_area_shortcode()) {
        return;
    }
    $assets = sticpa_area_foreign_assets();
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
