<?php

$pageSettings['fileName'] = basename(__FILE__, ".php"); // List name, from the filename. Don't touch.

$current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
$current_url = $current_url[0];

?>
<h3><?=  __('Payment error', 'sticpa')?></h3>
<div class="stic-empty-state stic-empty-state--error" role="alert">
    <span class="stic-empty-ico stic-empty-ico--error">
        <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
    </span>
    <p class="stic-empty-title"><?= esc_html__('No hemos podido completar el pago', 'sticpa'); ?></p>
    <p class="stic-empty-sub"><?= esc_html__('No se ha realizado ningún cargo. Puedes intentarlo de nuevo o, si el problema continúa, contactar con el administrador.', 'sticpa'); ?></p>
    <p class="stic-empty-actions">
        <a class="stic-button" href="<?= esc_url($current_url . '?internalpage=single_stic_payment_form'); ?>"><?= esc_html__('Reintentar el pago', 'sticpa'); ?></a>
        <a class="stic-back-button stic-button" href="<?= esc_url($current_url); ?>"><?= esc_html__('Volver al inicio', 'sticpa'); ?></a>
    </p>
</div>


