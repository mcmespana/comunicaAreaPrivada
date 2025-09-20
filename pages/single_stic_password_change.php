<?php

$current_url = explode('?', $_SERVER['REQUEST_URI'], 2);
$current_url = $current_url[0] . "?internalpage=single_stic_password_change";
$html .= " <div class='stic-entry-header'>
                         <h3>" . __('Change password', 'sticpa') . "</h3>";
if (isset($_REQUEST['success']) && $_REQUEST['success'] == true) {
    $html .= "<span class='success'>" . __('Your password has been successfully changed.', 'sticpa') . "</span>";
}

if (isset($_REQUEST['error']) && $_REQUEST['error'] == 1) {
    $html .= "<span class='error'>" . __('The confirmation password does not match.', 'sticpa') . " </span>";
}

if (isset($_REQUEST['error']) && $_REQUEST['error'] == 2) {
    $html .= "<span class='error'>" . __('Enter the correct old password.', 'sticpa') . ".</span>";
}

$html .= "</div>";

$html .= "<div class='stic-form stic-form-two-col'>
        <form action='" . site_url() . "/wp-admin/admin-post.php' method='post'>
        <ul>
            <li>
                        <label>" . __('Old password', 'sticpa') . ":</label>
                        <span><input class='input-text textinputform' type='password' name='add-profile-old-password' id='add-profile-old-password' required /> </span>
            </li>
            <li class='last'>
                        <label></label>
                        <span></span>
            </li>

            <li>
                        <label>" . __('New password', 'sticpa') . ":</label>
                        <span><input class='input-text textinputform' type='password' name='add-profile-new-password' id='add-profile-new-password' required /> </span>
            </li>
            <li class='last'>
                        <label>" . __('Confirm new password', 'sticpa') . ":</label>
                        <span><input class='input-text textinputform' type='password' name='add-profile-confirm-password' id='add-profile-confirm-password' required /> </span>
            </li>

            <li class='stic-send'>
                        <input type='hidden' name='action' value='single_stic_password_change'>
                        <input type='hidden' name='scp_current_url' value='" . $current_url . "'>
                        <span class='desc'><input type='submit' value='" . __('Change password', 'sticpa') . "' /></span>
            </li>
        </ul>
        </form>
</div>";
