<?php
/**
 * USUARIO Y CONTRASEÑA.
 * ----------------------------------------------------------------------------
 * Antes se llamaba «Cambiar contraseña» y pedía la antigua a todo el mundo. Casi
 * nadie tiene: se entra por el enlace del correo (docs/ACCESO.md), así que ni
 * sabía su contraseña antigua ni con qué USUARIO usar la nueva.
 *
 * Ahora la pantalla dice primero con qué se entra —el usuario, y si no tiene, su
 * DNI, que no se puede cambiar— y la contraseña antigua solo se pide si existe.
 *
 * Guardado: prefix_admin_single_stic_password_change (inc/stic-action.php).
 */

$current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
$current_url = $current_url[0] . "?internalpage=single_stic_password_change";

$ownerId = sticpa_password_owner_id();
$ownerInfo = ($ownerId !== '') ? $objSCP->getUserInformation($ownerId) : null;
$ownerData = isset($ownerInfo->entry_list[0]->name_value_list) ? $ownerInfo->entry_list[0]->name_value_list : null;
$cuenta = sticpa_portal_username($ownerData);
$tienePassword = isset($ownerData->stic_pa_password_c->value) && (string) $ownerData->stic_pa_password_c->value !== '';

$errores = array(
    1 => __('Las dos contraseñas nuevas no coinciden.', 'sticpa'),
    2 => __('La contraseña actual no es correcta.', 'sticpa'),
    3 => __('No tenemos tu DNI y sin él no hay usuario con el que entrar. Escribe a la oficina técnica y lo añadimos.', 'sticpa'),
    4 => __('La contraseña tiene que tener al menos 6 caracteres.', 'sticpa'),
    5 => __('Tu DNI ya es el usuario de otra ficha. Escribe a la oficina técnica y lo revisamos.', 'sticpa'),
);

$html .= "<div class='stic-entry-header'><h3>" . esc_html__('Usuario y contraseña', 'sticpa') . "</h3>";
if (isset($_REQUEST['success']) && $_REQUEST['success'] == true) {
    $html .= "<span class='success' role='status'>" . esc_html(sprintf(
        /* translators: %s: el usuario con el que se entra */
        __('Guardado. Ya puedes entrar con tu usuario %s y tu contraseña.', 'sticpa'),
        $cuenta['usuario']
    )) . "</span>";
}
$error = isset($_REQUEST['error']) ? (int) $_REQUEST['error'] : 0;
if (isset($errores[$error])) {
    $html .= "<span class='error' role='alert'>" . esc_html($errores[$error]) . "</span>";
}
$html .= "</div>";

$html .= "<div class='stic-form stic-form-two-col'>
        <form action='" . esc_url(site_url() . '/wp-admin/admin-post.php') . "' method='post'>
        <ul>";

// ---- El usuario: se enseña, no se edita ----
if ($cuenta['usuario'] !== '') {
    $html .= "<li>
                <label for='stic-portal-user'>" . esc_html__('Tu usuario', 'sticpa') . "</label>
                <span><input class='input-text textinputform' type='text' id='stic-portal-user' value='" . esc_attr($cuenta['usuario']) . "' readonly aria-readonly='true' autocomplete='username' /></span>
              </li>
              <li class='last'><label></label><span></span></li>";
    $html .= "<li class='stic-form-note stic-note-soft'>" . ($cuenta['desde_dni']
        ? esc_html__('Tu usuario es tu DNI y no se puede cambiar. La contraseña sí: pon la que quieras, y con las dos puedes entrar sin esperar al correo.', 'sticpa')
        : esc_html__('Con tu usuario y tu contraseña puedes entrar sin esperar al correo. El usuario no se puede cambiar; la contraseña, sí.', 'sticpa'))
        . "</li>";
} else {
    // Sin usuario ni DNI no hay nada con qué entrar por contraseña: se dice, y
    // no se enseña un formulario que no serviría.
    $html .= "<li class='stic-form-note stic-note-warning'>" . esc_html($errores[3]) . "</li>";
}

if ($cuenta['usuario'] !== '') {
    if ($tienePassword) {
        $html .= "<li>
                    <label for='add-profile-old-password'>" . esc_html__('Contraseña actual', 'sticpa') . "</label>
                    <span><input class='input-text textinputform' type='password' name='add-profile-old-password' id='add-profile-old-password' autocomplete='current-password' required /></span>
                  </li>
                  <li class='last'><label></label><span></span></li>";
    }
    $html .= "<li>
                <label for='add-profile-new-password'>" . esc_html($tienePassword ? __('Contraseña nueva', 'sticpa') : __('Contraseña', 'sticpa')) . "</label>
                <span><input class='input-text textinputform' type='password' name='add-profile-new-password' id='add-profile-new-password' minlength='6' autocomplete='new-password' required /></span>
              </li>
              <li class='last'>
                <label for='add-profile-confirm-password'>" . esc_html__('Repítela', 'sticpa') . "</label>
                <span><input class='input-text textinputform' type='password' name='add-profile-confirm-password' id='add-profile-confirm-password' minlength='6' autocomplete='new-password' required /></span>
              </li>
              <li class='stic-send'>
                <input type='hidden' name='action' value='single_stic_password_change'>
                " . sticpa_form_fields_input('single_stic_password_change', array()) . "
                <input type='hidden' name='scp_current_url' value='" . esc_attr($current_url) . "'>
                <span class='desc'><input type='submit' value='" . esc_attr($tienePassword ? __('Cambiar contraseña', 'sticpa') : __('Guardar contraseña', 'sticpa')) . "' /></span>
              </li>";
}

$html .= "</ul>
        </form>
</div>";
