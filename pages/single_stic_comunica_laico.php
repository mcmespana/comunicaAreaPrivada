<?php
/**
 * Comunica — Datos de Laico/a · PÁGINA RETIRADA (2026-07).
 * ----------------------------------------------------------------------------
 * El formulario público de laicos (comunicaFormularios/com-lc/laicos.html) solo
 * pide DATOS GENERALES (contacto, dirección, MCM, salud, RGPD): no hay ningún
 * campo que se pida a laicos y no a monitores. Por eso todo vive ahora en
 * "Mis datos" (single_stic_comunica_perfil.php) y esta sección desapareció del
 * menú (menu.php). Mantenemos el archivo solo para no romper enlaces antiguos
 * (?internalpage=single_stic_comunica_laico): mostramos un aviso con enlace.
 *
 * Si algún día aparecen campos EXCLUSIVOS de laicos, este es el sitio para
 * recuperarlos: vuelve a añadir la entrada en getSticMenuElements() (menu.php)
 * y define aquí el $fieldList igual que en las demás páginas single_*.
 */

$html .= "
<div class='stic-empty-state'>
    <span class='stic-empty-ico'>
        <svg viewBox='0 0 24 24' width='28' height='28' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='8' r='4'/><path d='M4 21v-1a8 8 0 0 1 16 0v1'/></svg>
    </span>
    <p class='stic-empty-title'>" . esc_html__('Esta sección ahora vive en «Mis datos»', 'sticpa') . "</p>
    <p class='stic-empty-sub'>" . esc_html__('Todos tus datos como laico/a (etapa, pañuelo, talla, grupo…) se editan desde tu ficha general.', 'sticpa') . "</p>
    <p style='margin-top:0.9rem;'>
        <a class='stic-button' style='padding:0.8rem 1.4rem; text-decoration:none;' href='?internalpage=single_stic_comunica_perfil'>" . esc_html__('Ir a Mis datos', 'sticpa') . "</a>
    </p>
</div>";
